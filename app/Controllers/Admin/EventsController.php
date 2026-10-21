<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Engine\Auth;
use App\Engine\FormState;
use App\Engine\AuditLog;
use App\Engine\Database;
use App\Engine\ImageUpload;
use App\Engine\Request;
use App\Engine\Response;
use App\Engine\Ulid;
use App\Engine\Version;
use App\Engine\View;
use App\Middleware\CsrfMiddleware;

/**
 * Event management controller (tenant-scoped, event-pattern only).
 *
 * Provides CRUD for events with one-off / recurring support,
 * waitlist config, and participant tracking.
 * Access: operators + business owners (Auth::canManageTenant()).
 */
final class EventsController
{

    public function index(Request $request): Response
    {
        $tenantId = $request->getAttribute('tenant_id');

        if (!Auth::canAccessTenant($tenantId) || !Auth::canManageTenant()) {
            return $this->forbidden($request);
        }

        $tenant = $this->loadTenant($tenantId);
        if ($tenant === null) {
            return Response::redirect('/admin/tenants');
        }

        if (($tenant['booking_pattern'] ?? '') !== 'event') {
            return Response::redirect("/admin/tenants/{$tenantId}");
        }

        $events = Database::query(
            'SELECT e.*, (
                SELECT COALESCE(SUM(b.party_size), 0)
                FROM bookings b
                WHERE b.event_id = e.id AND b.booking_pattern = \'event\'
                AND b.status IN (\'confirmed\', \'rescheduled\')
             ) AS booked_count,
             (
                SELECT COALESCE(SUM(b.party_size), 0)
                FROM bookings b
                WHERE b.event_id = e.id AND b.booking_pattern = \'event\'
                AND b.status = \'waitlisted\'
             ) AS waitlist_count
             FROM `events` e
             WHERE e.`tenant_id` = ?
             ORDER BY e.`start_datetime` ASC',
            [$tenantId]
        );

        return $this->render('admin.tenants.events.index', __('admin.events.title'), [
            'recordVersions' => \App\Application\Catalog\CatalogUi::versions('events', $tenantId, $events),
            'documentTitle' => __('admin.events.title'),
            'tenant'        => $tenant,
            'tenantId'      => $tenantId,
            'events'        => $events,
            'flash'         => FormState::getToast(),
        ], $tenantId);
    }

    public function create(Request $request): Response
    {
        $tenantId = $request->getAttribute('tenant_id');

        if (!Auth::canAccessTenant($tenantId) || !Auth::canManageTenant()) {
            return $this->forbidden($request);
        }

        $tenant = $this->loadTenant($tenantId);
        if ($tenant === null) {
            return Response::redirect('/admin/tenants');
        }

        if (($tenant['booking_pattern'] ?? '') !== 'event') {
            return Response::redirect("/admin/tenants/{$tenantId}");
        }

        return $this->render('admin.tenants.events.create', __('admin.events.create_title'), [
            'documentTitle' => __('admin.events.create_title'),
            'tenant'        => $tenant,
            'tenantId'      => $tenantId,
            'flash'         => FormState::getToast(),
        ], $tenantId);
    }

    public function store(Request $request): Response
    {
        return \App\Application\Catalog\CatalogUi::mutate($request, 'events', 'create');
    }

    public function edit(Request $request): Response
    {
        $tenantId = $request->getAttribute('tenant_id');
        $eventId = $request->getAttribute('id');

        if (!Auth::canAccessTenant($tenantId) || !Auth::canManageTenant()) {
            return $this->forbidden($request);
        }

        $tenant = $this->loadTenant($tenantId);
        if ($tenant === null || ($tenant['booking_pattern'] ?? '') !== 'event') {
            return Response::redirect('/admin/tenants');
        }

        $events = Database::query(
            'SELECT * FROM `events` WHERE `id` = ? AND `tenant_id` = ?',
            [$eventId, $tenantId]
        );
        if (empty($events)) {
            return Response::redirect("/admin/tenants/{$tenantId}/events");
        }

        return $this->render('admin.tenants.events.edit', __('admin.events.edit_title'), [
            'recordVersion' => \App\Application\Catalog\CatalogUi::version('events', $tenantId, $eventId),
            'documentTitle' => __('admin.events.edit_title'),
            'tenant'        => $tenant,
            'tenantId'      => $tenantId,
            'event'         => $events[0],
            'flash'         => FormState::getToast(),
        ], $tenantId);
    }

    public function update(Request $request): Response
    {
        return \App\Application\Catalog\CatalogUi::mutate($request, 'events', 'update');
    }

    public function toggleActive(Request $request): Response
    {
        return \App\Application\Catalog\CatalogUi::mutate($request, 'events', 'toggle');
    }

    public function delete(Request $request): Response
    {
        return \App\Application\Catalog\CatalogUi::mutate($request, 'events', 'delete');
    }

    // ── Private helpers ──

    private function loadTenant(string $tenantId): ?array
    {
        $rows = Database::query('SELECT * FROM `tenants` WHERE `id` = ? LIMIT 1', [$tenantId]);
        return $rows[0] ?? null;
    }

    private function render(string $template, string $pageTitle, array $extra, string $tenantId): Response
    {
        return View::response($template, array_merge([
            'user'       => Auth::user(),
            'version'    => Version::get(),
            'pageTitle'  => $pageTitle,
            'activePage' => 'events',
            'csrfToken'  => CsrfMiddleware::generateToken(),
            'tenantId'   => $tenantId,
        ], $extra));
    }

    private function forbidden(Request $request): Response
    {
        if ($request->isJson()) {
            return Response::json([
                'error'   => 'forbidden',
                'message' => __('admin.common.forbidden'),
            ], 403);
        }

        return View::response('admin.errors.403', [
            'user'      => Auth::user(),
            'version'   => Version::get(),
            'pageTitle' => '403',
            'csrfToken' => CsrfMiddleware::generateToken(),
        ], 403);
    }


}
