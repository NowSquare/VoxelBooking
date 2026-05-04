<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Engine\Auth;
use App\Engine\AuditLog;
use App\Engine\Database;
use App\Engine\FormState;
use App\Engine\Logger;
use App\Engine\Mailer;
use App\Engine\Request;
use App\Engine\Response;
use App\Engine\Version;
use App\Engine\View;
use App\Middleware\CsrfMiddleware;
use App\Models\Tenant;

/**
 * Tenant management controller (operator-only).
 *
 * Access control: Every public method checks Auth::isOperator() and
 * returns 403 if false. This is NOT in OPERATOR_ONLY_PREFIXES because
 * the /admin/tenants/{tenant_id}/... prefix is used by business users.
 *
 * GET  /admin/tenants          → Tenant list
 * GET  /admin/tenants/create   → Create form
 * POST /admin/tenants/create   → Store new tenant
 * GET  /admin/tenants/{id}/edit → Edit form
 * POST /admin/tenants/{id}/edit → Update tenant
 * POST /admin/tenants/{id}/archive → Archive tenant
 * POST /admin/tenants/{id}/activate → Re-activate tenant
 */
final class TenantsController
{
    public function index(Request $request): Response
    {
        if (!Auth::isOperator()) {
            return $this->forbidden($request);
        }

        $search = trim($request->string('search'));
        $status = trim($request->string('status'));

        $tenants = Tenant::filtered(
            $search !== '' ? $search : null,
            $status !== '' ? $status : null,
        );

        foreach ($tenants as &$t) {
            $t['booking_count'] = Tenant::bookingCount($t['id']);
            $t['service_count'] = Tenant::serviceCount($t['id']);
        }
        unset($t);

        return $this->render('admin.tenants.index', __('admin.tenants.title'), [
            'tenants' => $tenants,
            'counts'  => Tenant::counts(),
            'filters' => ['search' => $search, 'status' => $status],
            'flash'   => FormState::getToast(),
        ]);
    }

    public function create(Request $request): Response
    {
        if (!Auth::isOperator()) {
            return $this->forbidden($request);
        }

        $defaults = $this->resolveSystemDefaults();

        return $this->render('admin.tenants.create', __('admin.tenants.title'), [
            'documentTitle'   => __('admin.tenants.create'),
            'defaultTimezone' => $defaults['timezone'],
            'defaultCurrency' => $defaults['currency'],
            'flash'           => FormState::getToast(),
        ]);
    }

    public function store(Request $request): Response
    {
        if (!Auth::isOperator()) {
            return $this->forbidden($request);
        }

        $name    = trim($request->string('name'));
        $rawSlug = trim($request->string('slug'));
        // If user submitted a slug, validate it strictly; otherwise auto-generate from name
        $slug    = $rawSlug !== '' ? $rawSlug : $this->generateSlug($name);
        $email   = trim($request->string('email'));
        $pattern = trim($request->string('booking_pattern'));

        // Validate tenant fields
        $errors = [];
        if ($name === '') {
            $errors[] = __('admin.tenants.flash_name_required');
        }
        if ($email === '') {
            $errors[] = __('admin.tenants.flash_email_required');
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = __('admin.tenants.flash_email_invalid');
        }
        if ($slug !== '' && !self::isValidSlug($slug)) {
            $errors[] = __('admin.tenants.flash_slug_invalid');
        } elseif ($slug !== '' && Tenant::slugExists($slug)) {
            $errors[] = __('admin.tenants.flash_slug_taken');
        }
        if (!in_array($pattern, ['timeslot', 'resource', 'capacity', 'event'], true)) {
            $pattern = 'timeslot';
        }

        // Optional owner fields
        $createOwner = $request->string('create_owner') === '1';
        $ownerName   = trim($request->string('owner_name'));
        $ownerEmail  = trim($request->string('owner_email'));
        $ownerPass   = trim($request->string('owner_password'));
        $sendEmail   = $request->string('send_owner_email') === '1';

        // Validate owner fields if requested
        if ($createOwner) {
            if ($ownerName === '') {
                $errors[] = __('admin.tenants.flash_owner_name_required');
            }
            if ($ownerEmail === '') {
                $errors[] = __('admin.tenants.flash_owner_email_required');
            } elseif (!filter_var($ownerEmail, FILTER_VALIDATE_EMAIL)) {
                $errors[] = __('admin.tenants.flash_owner_email_invalid');
            }
            if ($ownerPass === '') {
                $ownerPass = bin2hex(random_bytes(8)); // 16-char random password
            }
        }

        $sysDefaults = $this->resolveSystemDefaults();

        if (!empty($errors)) {
            FormState::flash([
                'name'            => $name,
                'slug'            => $slug,
                'email'           => $email,
                'booking_pattern' => $pattern,
                'timezone'        => trim($request->string('timezone')) ?: $sysDefaults['timezone'],
                'currency'        => trim($request->string('currency')) ?: $sysDefaults['currency'],
                'brand_color'     => trim($request->string('brand_color')) ?: '#2563EB',
                'create_owner'    => $createOwner ? '1' : '0',
                'owner_name'      => $ownerName,
                'owner_email'     => $ownerEmail,
            ], $this->mapFieldErrors($errors));

            FormState::toast('error', implode(' ', $errors));
            return Response::redirect('/admin/tenants/create');
        }

        try {
            $tenantId = null;
            $ownerId = null;

            Database::transaction(function () use (
                $name, $slug, $email, $pattern, $request,
                $createOwner, $ownerName, $ownerEmail, $ownerPass,
                &$tenantId, &$ownerId, $sysDefaults
            ) {
                $tenantId = Tenant::create([
                    'name'            => $name,
                    'slug'            => $slug,
                    'email'           => $email,
                    'booking_pattern' => $pattern,
                    'timezone'        => (function () use ($request, $sysDefaults) {
                        $tz = trim($request->string('timezone'));
                        return ($tz !== '' && in_array($tz, get_supported_timezones(), true))
                            ? $tz : $sysDefaults['timezone'];
                    })(),
                    'currency'        => (function () use ($request, $sysDefaults) {
                        $cur = strtoupper(trim($request->string('currency')));
                        return ($cur !== '' && array_key_exists($cur, get_supported_currencies()))
                            ? $cur : $sysDefaults['currency'];
                    })(),
                    'locale'          => $sysDefaults['locale'],
                    'date_format'     => $sysDefaults['date_format'],
                    'number_format'   => $sysDefaults['number_format'],
                    'time_format'     => $sysDefaults['time_format'],
                    'week_start'      => $sysDefaults['week_start'],
                    'brand_color'     => trim($request->string('brand_color')) ?: '#2563EB',
                ]);

                AuditLog::log('tenant.created', 'tenant', $tenantId, [
                    'name' => $name, 'slug' => $slug, 'pattern' => $pattern,
                ]);

                // Create the first owner in the same transaction
                if ($createOwner && $tenantId) {
                    // Check for email collision via global registry
                    $existing = Database::query(
                        'SELECT `email` FROM `auth_emails` WHERE `email` = ? LIMIT 1',
                        [$ownerEmail]
                    );

                    if (!empty($existing)) {
                        throw new \RuntimeException('owner_email_taken');
                    }

                    $ownerId = \App\Engine\Ulid::generate();
                    Database::execute(
                        "INSERT INTO `business_users`
                         (`id`, `tenant_id`, `name`, `email`, `password_hash`, `role`, `force_password_change`, `is_active`)
                         VALUES (?, ?, ?, ?, ?, 'owner', 1, 1)",
                        [$ownerId, $tenantId, $ownerName, $ownerEmail, password_hash($ownerPass, PASSWORD_BCRYPT)]
                    );

                    // Register in auth_emails for passwordless login
                    Database::execute(
                        "INSERT INTO `auth_emails` (`email`, `user_type`, `user_id`) VALUES (?, 'business_user', ?)",
                        [$ownerEmail, $ownerId]
                    );

                    AuditLog::log('business_user.created', 'business_user', $ownerId, [
                        'tenant_id' => $tenantId,
                        'name'      => $ownerName,
                        'role'      => 'owner',
                        'source'    => 'tenant_creation',
                    ]);
                }
            });

            // After commit: send welcome email if requested
            if ($createOwner && $ownerId !== null) {
                $emailResult = null;

                if ($sendEmail && Mailer::isConfigured()) {
                    $loginUrl = app_url('/admin/login');
                    $operatorUser = Auth::user();
                    $emailResult = Mailer::sendBusinessUserWelcome(
                        $ownerEmail, $ownerName, $ownerPass, $loginUrl, $name, $tenantId,
                        $operatorUser['email'] ?? null,
                        $slug,
                        $operatorUser['name'] ?? null,
                    );
                }

                if ($emailResult !== null && $emailResult['sent']) {
                    FormState::toast('success', __('admin.tenants.flash_created_with_owner')
                        . ' ' . __('admin.tenants.flash_owner_email_sent'));
                } else {
                    $reason = !$sendEmail
                        ? __('admin.tenants.flash_owner_email_skipped')
                        : ($emailResult !== null
                            ? __('admin.tenants.flash_owner_email_failed')
                            : __('admin.tenants.flash_owner_email_skipped'));

                    FormState::toast('owner_credentials', json_encode([
                        'message'  => __('admin.tenants.flash_created_with_owner') . ' ' . $reason,
                        'email'    => $ownerEmail,
                        'password' => $ownerPass,
                        'login'    => app_url('/admin/login'),
                    ]));
                }
            } else {
                FormState::toast('success', __('admin.tenants.flash_created'));
            }

            return Response::redirect('/admin/tenants');
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'owner_email_taken') {
                FormState::flash([
                    'name'            => $name,
                    'slug'            => $slug,
                    'email'           => $email,
                    'booking_pattern' => $pattern,
                    'timezone'        => trim($request->string('timezone')) ?: $sysDefaults['timezone'],
                    'currency'        => trim($request->string('currency')) ?: $sysDefaults['currency'],
                    'brand_color'     => trim($request->string('brand_color')) ?: '#2563EB',
                    'create_owner'    => '1',
                    'owner_name'      => $ownerName,
                    'owner_email'     => $ownerEmail,
                ], ['owner_email' => __('admin.tenants.flash_owner_email_taken')]);
                FormState::toast('error', __('admin.tenants.flash_owner_email_taken'));
                return Response::redirect('/admin/tenants/create');
            }
            throw $e;
        } catch (\Throwable $e) {
            Logger::error('Tenant creation failed', ['error' => $e->getMessage()]);
            FormState::toast('error', __('admin.tenants.flash_create_failed'));
            return Response::redirect('/admin/tenants/create');
        }
    }

    public function edit(Request $request): Response
    {
        if (!Auth::isOperator()) {
            return $this->forbidden($request);
        }

        $tenant = Tenant::find($request->getAttribute('id'));
        if ($tenant === null) {
            return Response::redirect('/admin/tenants');
        }

        return $this->render('admin.tenants.edit', __('admin.tenants.title'), [
            'documentTitle' => __('admin.tenants.edit'),
            'tenant'        => $tenant,
            'flash'         => FormState::getToast(),
        ]);
    }

    public function update(Request $request): Response
    {
        if (!Auth::isOperator()) {
            return $this->forbidden($request);
        }

        $id = $request->getAttribute('id');
        $tenant = Tenant::find($id);
        if ($tenant === null) {
            return Response::redirect('/admin/tenants');
        }

        $name  = trim($request->string('name'));
        $slug  = trim($request->string('slug'));
        $email = trim($request->string('email'));

        $errors = [];
        if ($name === '') {
            $errors[] = __('admin.tenants.flash_name_required');
        }
        if ($email === '') {
            $errors[] = __('admin.tenants.flash_email_required');
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = __('admin.tenants.flash_email_invalid');
        }
        if ($slug !== '' && !self::isValidSlug($slug)) {
            $errors[] = __('admin.tenants.flash_slug_invalid');
        } elseif ($slug !== '' && Tenant::slugExists($slug, excludeId: $id)) {
            $errors[] = __('admin.tenants.flash_slug_taken');
        }

        if (!empty($errors)) {
            FormState::flash([
                'name'        => $name,
                'slug'        => $slug,
                'email'       => $email,
                'timezone'    => trim($request->string('timezone')) ?: null,
                'currency'    => trim($request->string('currency')) ?: null,
                'brand_color' => trim($request->string('brand_color')) ?: null,
            ], $this->mapFieldErrors($errors));
            FormState::toast('error', implode(' ', $errors));
            return Response::redirect("/admin/tenants/{$id}/edit");
        }

        $data = array_filter([
            'name'        => $name,
            'email'       => $email,
            'slug'        => $slug !== '' ? $slug : null,
            'timezone'    => trim($request->string('timezone')) ?: null,
            'currency'    => trim($request->string('currency')) ?: null,
            'brand_color' => trim($request->string('brand_color')) ?: null,
        ], fn($v) => $v !== null);

        $changes = [];
        foreach ($data as $k => $v) {
            if (($tenant[$k] ?? '') !== $v) {
                $changes[$k] = ['old' => $tenant[$k] ?? '', 'new' => $v];
            }
        }

        try {
            Tenant::update($id, $data);
            if (!empty($changes)) {
                AuditLog::log('tenant.updated', 'tenant', $id, $changes);
            }
            FormState::toast('success', __('admin.tenants.flash_updated'));
        } catch (\Throwable $e) {
            Logger::error('Tenant update failed', ['error' => $e->getMessage()]);
            FormState::toast('error', __('admin.tenants.flash_update_failed'));
        }

        return Response::redirect("/admin/tenants/{$id}/edit");
    }

    public function archive(Request $request): Response
    {
        if (!Auth::isOperator()) {
            return $this->forbidden($request);
        }

        $id = $request->getAttribute('id');
        $tenant = Tenant::find($id);

        if ($tenant !== null && $tenant['status'] !== 'archived') {
            Tenant::update($id, ['status' => 'archived']);
            AuditLog::log('tenant.archived', 'tenant', $id, ['name' => $tenant['name']]);
            FormState::toast('success', __('admin.tenants.flash_archived'));
        }

        return Response::redirect('/admin/tenants');
    }

    public function activate(Request $request): Response
    {
        if (!Auth::isOperator()) {
            return $this->forbidden($request);
        }

        $id = $request->getAttribute('id');
        $tenant = Tenant::find($id);

        if ($tenant !== null && $tenant['status'] === 'archived') {
            Tenant::update($id, ['status' => 'active']);
            AuditLog::log('tenant.activated', 'tenant', $id, ['name' => $tenant['name']]);
            FormState::toast('success', __('admin.tenants.flash_activated'));
        }

        return Response::redirect('/admin/tenants');
    }

    // ── Helpers ──

    /**
     * Resolve system defaults for new tenants.
     *
     * Priority for timezone: settings.timezone (admin General save)
     * → settings.default_timezone (install wizard seed) → UTC.
     *
     * All other regional settings: read from settings table or fall back to sensible defaults.
     *
     * @return array{timezone: string, currency: string, locale: string, date_format: string, number_format: string, time_format: string, week_start: string}
     */
    private function resolveSystemDefaults(): array
    {
        $defaults = [
            'timezone'      => 'UTC',
            'currency'      => 'EUR',
            'locale'        => 'en',
            'date_format'   => 'Y-m-d',
            'number_format' => 'period',
            'time_format'   => '24h',
            'week_start'    => '1',
        ];

        try {
            $rows = Database::query(
                "SELECT `key`, `value` FROM `settings` WHERE `key` IN ('timezone', 'default_timezone', 'default_currency', 'default_locale', 'date_format', 'number_format', 'time_format', 'week_start')"
            );
            $installTz = null;
            $settingsHadTimezone = false;
            foreach ($rows as $row) {
                if ($row['value'] === '' || $row['value'] === null) {
                    continue;
                }
                match ($row['key']) {
                    'timezone'         => (function () use (&$defaults, &$settingsHadTimezone, $row) {
                        $defaults['timezone'] = $row['value'];
                        $settingsHadTimezone = true;
                    })(),
                    'default_timezone' => $installTz = $row['value'],
                    'default_currency' => $defaults['currency'] = $row['value'],
                    'default_locale'   => $defaults['locale'] = $row['value'],
                    'date_format'      => $defaults['date_format'] = $row['value'],
                    'number_format'    => $defaults['number_format'] = $row['value'],
                    'time_format'      => $defaults['time_format'] = $row['value'],
                    'week_start'       => $defaults['week_start'] = $row['value'],
                    default            => null,
                };
            }
            // Fall back to install-time timezone only if no settings.timezone row was found
            if (!$settingsHadTimezone && $installTz !== null) {
                $defaults['timezone'] = $installTz;
            }
            return $defaults;
        } catch (\Throwable) {
            return $defaults;
        }
    }

    private function generateSlug(string $name): string
    {
        $slug = trim(preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($name)), '-');
        return $slug !== '' && self::isValidSlug($slug) ? $slug : 'tenant';
    }

    /**
     * Validate a slug against the strict format: lowercase a-z, 0-9, hyphens only.
     *
     * No leading/trailing hyphens, no consecutive hyphens, no uppercase,
     * no spaces, no underscores, no path traversal, no query chars.
     */
    private static function isValidSlug(string $slug): bool
    {
        return (bool) preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug);
    }

    /**
     * Map translated error messages back to field names for per-field marking.
     *
     * Returns ['field_name' => 'translated error message'] for each error that
     * matches a known validation message.
     */
    private function mapFieldErrors(array $errors): array
    {
        $map = [
            __('admin.tenants.flash_name_required')        => 'name',
            __('admin.tenants.flash_email_required')        => 'email',
            __('admin.tenants.flash_email_invalid')         => 'email',
            __('admin.tenants.flash_slug_taken')            => 'slug',
            __('admin.tenants.flash_slug_invalid')          => 'slug',
            __('admin.tenants.flash_owner_name_required')   => 'owner_name',
            __('admin.tenants.flash_owner_email_required')  => 'owner_email',
            __('admin.tenants.flash_owner_email_invalid')   => 'owner_email',
            __('admin.tenants.flash_owner_email_taken')     => 'owner_email',
        ];

        $result = [];
        foreach ($errors as $msg) {
            if (isset($map[$msg])) {
                $result[$map[$msg]] = $msg;
            }
        }
        return $result;
    }

    private function render(string $template, string $pageTitle, array $extra = []): Response
    {
        return View::response($template, array_merge([
            'user'      => Auth::user(),
            'version'   => Version::get(),
            'pageTitle' => $pageTitle,
            'activePage' => 'tenants',
            'csrfToken' => CsrfMiddleware::generateToken(),
        ], $extra));
    }

    private function forbidden(Request $request): Response
    {
        if ($request->isJson()) {
            return Response::json([
                'error' => 'forbidden',
                'message' => 'Operator access required.',
            ], 403);
        }

        return View::response('admin.errors.403', [
            'user'      => Auth::user(),
            'version'   => Version::get(),
            'pageTitle' => '403',
            'csrfToken' => CsrfMiddleware::generateToken(),
        ], 403);
    }


    // ── CSV Export ──

    /**
     * Export tenants as CSV — operator-only.
     */
    public function export(Request $request): Response
    {
        if (!Auth::isOperator()) {
            return $this->forbidden($request);
        }

        $search = trim($request->string('search'));
        $status = trim($request->string('status'));

        $tenants = Tenant::filtered(
            $search !== '' ? $search : null,
            $status !== '' ? $status : null,
        );

        $filename = 'tenants-export-' . date('Y-m-d') . '.csv';
        $headers = ['Name', 'Slug', 'Email', 'Pattern', 'Status', 'Bookings', 'Services'];

        $output = fopen('php://temp', 'r+');
        fwrite($output, "\xEF\xBB\xBF");
        fputcsv($output, $headers, escape: '\\');

        foreach ($tenants as $t) {
            fputcsv($output, [
                $t['name'] ?? '',
                $t['slug'] ?? '',
                $t['email'] ?? '',
                $t['booking_pattern'] ?? '',
                $t['status'] ?? '',
                Tenant::bookingCount($t['id']),
                Tenant::serviceCount($t['id']),
            ], escape: '\\');
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        $response = new Response();
        return $response
            ->header('Content-Type', 'text/csv; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->header('Cache-Control', 'no-store')
            ->body($csv);
    }

}
