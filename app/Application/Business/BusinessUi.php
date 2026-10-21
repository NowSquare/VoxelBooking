<?php

declare(strict_types=1);

namespace App\Application\Business;

use App\Agent\ActorContext;
use App\Agent\OperationException;
use App\Agent\OperationRegistry;
use App\Agent\SessionActor;
use App\Engine\Auth;
use App\Engine\FormState;
use App\Engine\Request;
use App\Engine\Response;
use App\Engine\Version;
use App\Engine\View;
use App\Middleware\CsrfMiddleware;

/** Native-form parsing and feedback; domain rules live in the shared services. */
final class BusinessUi
{
    public static function actor(): ActorContext
    {
        return SessionActor::resolve(new OperationRegistry(...BusinessOperations::definitions()));
    }

    public static function all(callable $query, array $input = []): array
    {
        $actor = self::actor();
        $rows = [];
        do {
            $page = $query($actor, [...$input, 'limit' => 100]);
            array_push($rows, ...$page['items']);
            $input['cursor'] = $page['next_cursor'];
        } while ($input['cursor'] !== null);

        return $rows;
    }

    public static function business(Request $request, string $action): Response
    {
        $id = (string) ($request->getAttribute('id') ?? '');
        $target = $action === 'create' ? '/admin/tenants/create' : "/admin/tenants/{$id}/edit";
        try {
            $actor = self::actor();
            $input = ['business_id' => $id, 'expected_version' => $request->string('_record_version')];
            if ($action === 'archive' || $action === 'activate') {
                $input['status'] = $action === 'archive' ? 'archived' : 'active';
                BusinessService::setStatus($actor, $input);
                FormState::toast(
                    'success',
                    __('admin.tenants.flash_' . ($action === 'archive' ? 'archived' : 'activated'))
                );
                return Response::redirect('/admin/tenants');
            }
            foreach (['name', 'slug', 'email', 'timezone', 'currency', 'brand_color'] as $field) {
                $value = trim($request->string($field));
                if ($value !== '' || in_array($field, ['name', 'email'], true)) {
                    $input[$field] = $value;
                }
            }
            if ($action === 'create') {
                unset($input['business_id'], $input['expected_version']);
                $input['booking_pattern'] = $request->string('booking_pattern') ?: 'timeslot';
                if ($request->string('create_owner') === '1') {
                    $input['owner'] = [
                        'name' => $request->string('owner_name'),
                        'email' => $request->string('owner_email')
                    ];
                }
                BusinessService::create(
                    $actor,
                    $input,
                    $request->string('owner_password') ?: null,
                    $request->string('send_owner_email') === '1'
                );
                FormState::toast(
                    'success',
                    __(isset($input['owner']) ? 'agent-business.invitation_created' : 'admin.tenants.flash_created')
                );
                return Response::redirect('/admin/tenants');
            }
            BusinessService::update($actor, $input);
            FormState::toast('success', __('admin.tenants.flash_updated'));
        } catch (OperationException $error) {
            if (in_array($error->status, [401, 403], true)) {
                return self::forbidden($request, $error);
            }
            if ($error->status === 404) {
                return Response::redirect('/admin/tenants');
            }
            $old = array_intersect_key(
                $_POST,
                array_flip([
                    'name',
                    'slug',
                    'email',
                    'booking_pattern',
                    'timezone',
                    'currency',
                    'brand_color',
                    'create_owner',
                    'owner_name',
                    'owner_email'
                ])
            );
            FormState::flash($old, self::fieldErrors($error, 'business'));
            FormState::toast('error', self::message($error, 'business'));
        }

        return Response::redirect($target);
    }

    public static function team(Request $request, string $action): Response
    {
        $business = (string) $request->getAttribute('tenant_id');
        $base = "/admin/tenants/{$business}/users";
        try {
            $actor = self::actor();
            if ($action === 'invite') {
                $input = [
                    'business_id' => $business,
                    'name' => $request->string('name'),
                    'email' => $request->string('email'),
                    'role' => $request->string('role')
                ];
                TeamService::invite(
                    $actor,
                    $input,
                    $request->string('password') ?: null,
                    $request->string('send_email') === '1'
                );
                FormState::toast(
                    'success',
                    __(
                        $request->string('send_email') === '1'
                            ? 'agent-business.invitation_queued'
                            : 'agent-business.invitation_created'
                    )
                );
            } else {
                TeamService::setActive($actor, [
                    'business_id' => $business,
                    'id' => (string) $request->getAttribute('id'),
                    'expected_version' => $request->string('_record_version'),
                    'is_active' => $action === 'activate'
                ]);
                FormState::toast(
                    'success',
                    __('admin.users.flash_' . ($action === 'activate' ? 'activated' : 'deactivated'))
                );
            }
        } catch (OperationException $error) {
            if (in_array($error->status, [401, 403], true)) {
                return self::forbidden($request, $error);
            }
            FormState::flash(
                array_intersect_key($_POST, array_flip(['name', 'email', 'role'])),
                self::fieldErrors($error, 'team')
            );
            FormState::toast('error', self::message($error));
            if ($action === 'invite') {
                return Response::redirect($base . '/invite');
            }
        }

        return Response::redirect($base);
    }

    public static function application(Request $request, string $decision): Response
    {
        try {
            $result = ApplicationService::review(self::actor(), [
                'id' => (string) $request->getAttribute('id'),
                'expected_version' => $request->string('_record_version'),
                'decision' => $decision,
                'admin_notes' => $request->string('admin_notes') ?: null
            ]);
            FormState::toast('success', __('admin.applications.' . $decision, ['name' => $result['business_name']]));
        } catch (OperationException $error) {
            if (in_array($error->status, [401, 403], true)) {
                return self::forbidden($request, $error);
            }
            FormState::toast('error', self::message($error));
        }
        return Response::redirect('/admin/applications');
    }

    public static function submitApplication(Request $request): Response
    {
        $input = [];
        foreach (['business_name', 'contact_name', 'email', 'phone', 'website', 'message'] as $field) {
            $input[$field] = $request->string($field);
        }
        try {
            ApplicationService::submit(
                new ActorContext('anonymous', 'browser', 'anonymous', [], [], authMode: 'public'),
                $input
            );
            FormState::toast('success', __('public.success_message'));
        } catch (OperationException $error) {
            FormState::flashInput($input);
            FormState::toast(
                'error',
                __(
                    $error->problemCode === 'validation_failed'
                        ? (isset($error->fieldErrors['email']) ? 'public.error_email' : 'public.error_required')
                        : 'public.error_spam'
                )
            );
        }

        return Response::redirect('/');
    }

    public static function forbidden(Request $request, OperationException $error): Response
    {
        if ($request->isJson()) {
            return Response::json($error->problem(), $error->status);
        }
        return View::response(
            'admin.errors.403',
            [
                'user' => Auth::user(),
                'version' => Version::get(),
                'pageTitle' => '403',
                'csrfToken' => CsrfMiddleware::generateToken()
            ],
            $error->status
        );
    }

    private static function fieldErrors(OperationException $error, string $section): array
    {
        $result = [];
        foreach ($error->fieldErrors as $field => $code) {
            $result[$field] = self::message($error, $section);
        }
        return $result;
    }

    private static function message(OperationException $error, string $section = ''): string
    {
        $field = array_key_first($error->fieldErrors);
        if ($section === 'business' && $field !== null) {
            $key = match ($field) {
                'slug' => $error->problemCode === 'conflict' ? 'slug_taken' : 'slug_invalid',
                'name' => 'name_required',
                'email' => 'email_invalid',
                'owner_name' => 'owner_name_required',
                'owner_email' => $error->problemCode === 'identity_unavailable'
                    ? 'owner_email_taken'
                    : (
                        ($error->fieldErrors['owner_email'] ?? '') === 'required'
                            ? 'owner_email_required'
                            : 'owner_email_invalid'
                    ),
                default => null,
            };
            if ($key !== null) {
                return __('admin.tenants.flash_' . $key);
            }
        }
        return __('agent-business.errors.' . $error->problemCode);
    }
}
