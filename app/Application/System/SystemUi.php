<?php

declare(strict_types=1);

namespace App\Application\System;

use App\Agent\ActorContext;
use App\Agent\OperationException;
use App\Agent\OperationRegistry;
use App\Agent\SessionActor;
use App\Engine\FormState;
use App\Engine\Request;
use App\Engine\Response;

final class SystemUi
{
    public static function actor(): ActorContext
    {
        return SessionActor::resolve(new OperationRegistry(...SystemOperations::definitions()));
    }

    public static function save(Request $request, string $section): Response
    {
        $target = $section === 'mail' ? '/admin/settings/email' : '/admin/settings';
        try {
            $changes = [];
            foreach ($section === 'mail' ? SystemSettings::MAIL : SystemSettings::GENERAL as $key) {
                if ($key === 'enable_applications') {
                    $changes[$key] = $request->string($key) === '1';
                    continue;
                }

                if (array_key_exists($key, $_POST)) {
                    $changes[$key] = $request->string($key);
                }
            }

            $credential = $section === 'mail'
                ? $request->string(($changes['mail_transport'] ?? '') === 'resend' ? 'resend_api_key' : 'smtp_password')
                : null;
            SystemSettings::updateBrowser(
                self::actor(),
                [
                    'section' => $section,
                    'expected_version' => $request->string('_record_version'),
                    'changes' => $changes
                ],
                $credential
            );
            FormState::toast(
                'success',
                __($section === 'mail' ? 'admin.flash.email_saved' : 'admin.flash.general_saved')
            );
        } catch (OperationException $error) {
            FormState::toast(
                'error',
                __($error->problemCode === 'version_conflict' ? 'agent-system.changed' : 'agent-system.invalid')
            );
        }

        return Response::redirect($target);
    }

    public static function run(): Response
    {
        try {
            JobCoordinator::request(self::actor(), []);
            $result = JobCoordinator::dispatch();
            FormState::toast(
                $result['status'] === 'partial' ? 'error' : 'success',
                __('agent-system.maintenance_' . $result['status'])
            );
        } catch (OperationException) {
            FormState::toast('error', __('agent-system.not_allowed'));
        }

        return Response::redirect('/admin/settings/cron');
    }
}
