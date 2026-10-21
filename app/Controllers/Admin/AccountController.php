<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Engine\Auth;
use App\Engine\FormState;
use App\Engine\AuditLog;
use App\Engine\Database;
use App\Engine\Request;
use App\Engine\Response;
use App\Engine\Version;
use App\Engine\View;
use App\Middleware\CsrfMiddleware;

/**
 * Authenticated account controller — shared personal surface.
 *
 * Accessible to both operators and business users. Sits outside
 * the OPERATOR_ONLY_PREFIXES in AuthMiddleware, so any authenticated
 * user can manage their own credentials and profile here.
 *
 * GET  /admin/account        → Profile details (editable) + password form
 * POST /admin/account        → Save profile changes
 * POST /admin/account/password → Save password change
 */
final class AccountController
{
    public function show(Request $request): Response
    {
        return $this->render('admin.account', __('admin.account.page_title'), [
            'flash' => FormState::getToast(),
        ]);
    }

    /**
     * Save profile changes (name, email).
     */
    public function save(Request $request): Response
    {
        $action = $request->string('_action');

        if ($action === 'password') {
            return $this->savePassword($request);
        }

        return $this->saveProfile($request);
    }

    // ── Profile ──

    private function saveProfile(Request $request): Response
    {
        try {
            $actor = \App\Agent\SessionActor::resolve(\App\Agent\Operations::registry());
            $profile = \App\Application\Identity\AccountService::update($actor, [
                'name' => $request->string('name'), 'expected_version' => $request->string('expected_version'),
            ], $request->string('email'));
            $_SESSION['auth_name'] = $profile['name'];
            $_SESSION['auth_email'] = $profile['email'];
            FormState::toast('success', __('admin.flash.profile_updated'));
        } catch (\App\Agent\OperationException $error) {
            $fields = [];
            if (isset($error->fieldErrors['name'])) {
                $fields['name'] = __('admin.flash.profile_name_required');
            }
            if (isset($error->fieldErrors['email'])) {
                $fields['email'] = __('admin.flash.profile_email_invalid');
            }
            if ($error->problemCode === 'identity_unavailable') {
                $fields['email'] = __('admin.flash.profile_email_taken');
            }
            FormState::flash(['name' => $request->string('name'), 'email' => $request->string('email')], $fields);
            FormState::toast('error', $fields !== [] ? reset($fields) : __('agent.errors.' . $error->problemCode));
        }
        return Response::redirect('/admin/account');
    }

    // ── Password ──

    private function savePassword(Request $request): Response
    {
        $currentPassword = $request->string('current_password');
        $newPassword     = $request->string('new_password');
        $confirmPassword = $request->string('confirm_password');

        $errors = [];
        if ($currentPassword === '') {
            $errors['current_password'] = __('admin.flash.password_required');
        }
        if ($newPassword === '') {
            $errors['new_password'] = __('admin.flash.password_required');
        } elseif (strlen($newPassword) < 8) {
            $errors['new_password'] = __('admin.flash.password_min_length');
        }
        if ($confirmPassword === '') {
            $errors['confirm_password'] = __('admin.flash.password_required');
        } elseif ($newPassword !== '' && $newPassword !== $confirmPassword) {
            $errors['confirm_password'] = __('admin.flash.password_mismatch');
        }

        if (!empty($errors)) {
            FormState::flash([], $errors);
            $firstError = reset($errors);
            FormState::toast('error', $firstError);
            return Response::redirect('/admin/account');
        }

        $user = Auth::user();
        if ($user === null) {
            return Response::redirect('/admin/login');
        }

        try {
            $table = $user['type'] === 'operator' ? 'operators' : 'business_users';
            $rows = Database::query(
                "SELECT `password_hash` FROM `{$table}` WHERE `id` = ? LIMIT 1",
                [$user['id']]
            );

            if (empty($rows) || !password_verify($currentPassword, $rows[0]['password_hash'])) {
                FormState::flash([], ['current_password' => __('admin.flash.password_incorrect')]);
                FormState::toast('error', __('admin.flash.password_incorrect'));
                return Response::redirect('/admin/account');
            }

            $newHash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
            Database::execute(
                "UPDATE `{$table}` SET `password_hash` = ?, `updated_at` = NOW() WHERE `id` = ?",
                [$newHash, $user['id']]
            );

            AuditLog::log('auth.password_changed', $user['type'], $user['id']);

            FormState::toast('success', __('admin.flash.password_updated'));
        } catch (\Throwable $e) {
            FormState::toast('error', __('admin.flash.password_failed'));
        }

        return Response::redirect('/admin/account');
    }

    // ── Helpers ──

    private function render(string $template, string $pageTitle, array $extra = []): Response
    {
        return View::response($template, array_merge([
            'user'      => Auth::user(),
            'version'   => Version::get(),
            'pageTitle' => $pageTitle,
            'csrfToken' => CsrfMiddleware::generateToken(),
        ], $extra));
    }


}
