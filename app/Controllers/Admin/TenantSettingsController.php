<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Engine\Auth;
use App\Engine\FormState;
use App\Engine\AuditLog;
use App\Engine\BrandColorHelper;
use App\Engine\Database;
use App\Engine\Request;
use App\Engine\Response;
use App\Engine\Ulid;
use App\Engine\Version;
use App\Engine\View;
use App\Middleware\CsrfMiddleware;
use App\Engine\ImageUpload;
use App\Models\Tenant;

/**
 * Tenant settings controller (tenant-scoped, all patterns).
 *
 * Access: operators + business owners (Auth::canManageTenant()).
 * Managers are forbidden (403).
 *
 * Five tabs: General, Branding, Booking Rules, Privacy, Notifications.
 */
final class TenantSettingsController
{
    // ── General ──

    public function general(Request $request): Response
    {
        $tenantId = $request->getAttribute('tenant_id');
        if (!$this->canAccess($tenantId)) {
            return $this->forbidden($request);
        }

        $tenant = $this->loadTenant($tenantId);
        if ($tenant === null) {
            return Response::redirect('/admin/tenants');
        }

        return $this->render('admin.tenants.settings.general', $tenantId, $tenant, 'general', [
            'localeOptions'   => \App\Engine\Locale::localeOptions(),
            'timezones'       => get_supported_timezones(),
            'currencyOptions' => get_supported_currencies(),
        ]);
    }

    public function saveGeneral(Request $request): Response
    {
        return \App\Application\Settings\BrowserSettings::save($request, 'general');
    }

    // ── Branding ──

    public function branding(Request $request): Response
    {
        $tenantId = $request->getAttribute('tenant_id');
        if (!$this->canAccess($tenantId)) {
            return $this->forbidden($request);
        }

        $tenant = $this->loadTenant($tenantId);
        if ($tenant === null) {
            return Response::redirect('/admin/tenants');
        }

        return $this->render('admin.tenants.settings.branding', $tenantId, $tenant, 'branding');
    }

    public function saveBranding(Request $request): Response
    {
        return \App\Application\Settings\BrowserSettings::save($request, 'branding');
    }

    // ── Booking Page (all patterns) ──

    public function bookingPage(Request $request): Response
    {
        $tenantId = $request->getAttribute('tenant_id');
        if (!$this->canAccess($tenantId)) {
            return $this->forbidden($request);
        }

        $tenant = $this->loadTenant($tenantId);
        if ($tenant === null) {
            return Response::redirect('/admin/tenants');
        }

        return $this->render('admin.tenants.settings.bookingpage', $tenantId, $tenant, 'bookingpage');
    }

    public function saveBookingPage(Request $request): Response
    {
        return \App\Application\Settings\BrowserSettings::save($request, 'bookingpage');
    }

    // ── Booking Rules (timeslot + resource — Phase R) ──

    public function booking(Request $request): Response
    {
        $tenantId = $request->getAttribute('tenant_id');
        if (!$this->canAccess($tenantId)) {
            return $this->forbidden($request);
        }

        $tenant = $this->loadTenant($tenantId);
        if ($tenant === null) {
            return Response::redirect('/admin/tenants');
        }

        // Phase R: timeslot + resource only. Capacity/event gated until their phases.
        if (!in_array($tenant['booking_pattern'] ?? '', ['timeslot', 'resource'], true)) {
            return $this->forbidden($request);
        }

        return $this->render('admin.tenants.settings.booking', $tenantId, $tenant, 'booking');
    }

    public function saveBooking(Request $request): Response
    {
        return \App\Application\Settings\BrowserSettings::save($request, 'booking');
    }

    // ── Privacy ──

    public function privacy(Request $request): Response
    {
        $tenantId = $request->getAttribute('tenant_id');
        if (!$this->canAccess($tenantId)) {
            return $this->forbidden($request);
        }

        $tenant = $this->loadTenant($tenantId);
        if ($tenant === null) {
            return Response::redirect('/admin/tenants');
        }

        return $this->render('admin.tenants.settings.privacy', $tenantId, $tenant, 'privacy');
    }

    public function savePrivacy(Request $request): Response
    {
        return \App\Application\Settings\BrowserSettings::save($request, 'privacy');
    }

    // ── Notifications ──

    public function notifications(Request $request): Response
    {
        $tenantId = $request->getAttribute('tenant_id');
        if (!$this->canAccess($tenantId)) {
            return $this->forbidden($request);
        }

        $tenant = $this->loadTenant($tenantId);
        if ($tenant === null) {
            return Response::redirect('/admin/tenants');
        }

        return $this->render('admin.tenants.settings.notifications', $tenantId, $tenant, 'notifications');
    }

    public function saveNotifications(Request $request): Response
    {
        return \App\Application\Settings\BrowserSettings::save($request, 'notifications');
    }

    // ── Helpers ──

    private function canAccess(string $tenantId): bool
    {
        return Auth::canAccessTenant($tenantId) && Auth::canManageTenant();
    }

    private function loadTenant(string $tenantId): ?array
    {
        $rows = Database::query('SELECT * FROM `tenants` WHERE `id` = ? LIMIT 1', [$tenantId]);
        return $rows[0] ?? null;
    }

    /**
     * Update tenant fields, compute diff, log audit, flash success.
     */
    private function saveTenant(string $tenantId, array $data, array $tenant, string $tab): void
    {
        $changes = [];
        foreach ($data as $k => $v) {
            $old = $tenant[$k] ?? null;
            if (is_int($v)) {
                $old = (int) $old;
            }
            if ($old !== $v) {
                $changes[$k] = ['old' => $tenant[$k] ?? null, 'new' => $v];
            }
        }

        if (empty($changes)) {
            FormState::toast('info', __('admin.tenant_settings.no_changes'));
            return;
        }

        $sets = [];
        $params = [];
        foreach ($data as $k => $v) {
            if (array_key_exists($k, $changes)) {
                $sets[] = "`{$k}` = ?";
                $params[] = $v;
            }
        }
        $params[] = $tenantId;

        Database::execute(
            'UPDATE `tenants` SET ' . implode(', ', $sets) . ' WHERE `id` = ?',
            $params
        );

        AuditLog::log('tenant.settings_updated', 'tenant', $tenantId, [
            'tab'     => $tab,
            'changes' => $changes,
        ]);

        FormState::toast('success', __('admin.tenant_settings.saved'));
    }

    private function render(string $template, string $tenantId, array $tenant, string $activeTab, array $extraData = []): Response
    {
        return View::response($template, array_merge([
            'user'          => Auth::user(),
            'version'       => Version::get(),
            'pageTitle'     => __('admin.tenant_settings.title'),
            'documentTitle' => __('admin.tenant_settings.title') . ' — ' . $tenant['name'],
            'activePage'    => 'settings',
            'activeTab'     => $activeTab,
            'csrfToken'     => CsrfMiddleware::generateToken(),
            'tenantId'      => $tenantId,
            'tenant'        => $tenant,
            'flash'         => FormState::getToast(),
        ], $extraData));
    }

    private function forbidden(Request $request): Response
    {
        if ($request->isJson()) {
            return Response::json([
                'error'   => 'forbidden',
                'message' => 'Owner or operator access required.',
            ], 403);
        }

        return View::response('admin.errors.403', [
            'user'      => Auth::user(),
            'version'   => Version::get(),
            'pageTitle' => '403',
            'csrfToken' => CsrfMiddleware::generateToken(),
        ], 403);
    }


    // ── Email Templates ──

    public function emails(Request $request): Response
    {
        $tenantId = $request->getAttribute('tenant_id');
        if (!$this->canAccess($tenantId)) {
            return $this->forbidden($request);
        }

        $tenant = $this->loadTenant($tenantId);
        if ($tenant === null) {
            return Response::redirect('/admin/tenants');
        }

        $templates = Database::query(
            'SELECT * FROM `tenant_email_templates` WHERE `tenant_id` = ? ORDER BY `type`',
            [$tenantId]
        );

        return View::response('admin.tenants.settings.emails', [
            'user'          => Auth::user(),
            'version'       => Version::get(),
            'pageTitle'     => __('admin.tenant_settings.title'),
            'documentTitle' => __('admin.tenant_settings.title') . ' — ' . $tenant['name'],
            'activePage'    => 'settings',
            'activeTab'     => 'emails',
            'csrfToken'     => CsrfMiddleware::generateToken(),
            'tenantId'      => $tenantId,
            'tenant'        => $tenant,
            'templates'     => $templates,
            'flash'         => FormState::getToast(),
        ]);
    }

    // ── Embed Settings ──

    public function embed(Request $request): Response
    {
        $tenantId = $request->getAttribute('tenant_id');
        if (!$this->canAccess($tenantId)) {
            return $this->forbidden($request);
        }

        $tenant = $this->loadTenant($tenantId);
        if ($tenant === null) {
            return Response::redirect('/admin/tenants');
        }

        return $this->render('admin.tenants.settings.embed', $tenantId, $tenant, 'embed');
    }

    public function saveEmbed(Request $request): Response
    {
        return \App\Application\Settings\BrowserSettings::save($request, 'embed');
    }

    public function saveEmails(Request $request): Response
    {
        $tenantId = (string)$request->getAttribute('tenant_id');
        try {
            $actor = \App\Agent\SessionActor::resolve(\App\Agent\Operations::registry());
            $templates = [];
            foreach (\App\Application\Settings\EmailTemplates::TYPES as $type) {
                $values = ['type'=>$type, 'is_enabled'=>$request->string($type.'_is_enabled')==='1'];
                foreach (\App\Application\Settings\EmailTemplates::FIELDS as $field) {
                    $values[$field] = $request->string($type.'_'.$field) ?: null;
                }
                $templates[] = $values;
            }
            \App\Application\Settings\EmailTemplates::update($actor, [
                'business_id'=>$tenantId, 'expected_version'=>$request->string('expected_version'), 'templates'=>$templates,
            ]);
            FormState::toast('success', __('admin.tenant_settings.emails_saved'));
        } catch (\App\Agent\OperationException $error) {
            FormState::toast('error', __('agent.errors.'.$error->problemCode));
        }
        return Response::redirect('/admin/tenants/'.$tenantId.'/settings/emails');
    }

}

