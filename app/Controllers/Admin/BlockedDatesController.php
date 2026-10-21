<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Engine\Auth;
use App\Engine\FormState;
use App\Engine\AuditLog;
use App\Engine\Database;
use App\Engine\Request;
use App\Engine\Response;
use App\Engine\Ulid;
use App\Engine\Version;
use App\Engine\View;
use App\Middleware\CsrfMiddleware;

/**
 * Blocked dates management controller (tenant-scoped).
 *
 * Shared by timeslot and resource patterns.
 * Timeslot: blocks by tenant-level or staff-level scope.
 * Resource: blocks by tenant-level or resource-level scope.
 *
 * Access: operators + business owners (Auth::canManageTenant()).
 *
 * GET  /admin/tenants/{tenant_id}/blocked-dates          → list
 * POST /admin/tenants/{tenant_id}/blocked-dates          → create
 * POST /admin/tenants/{tenant_id}/blocked-dates/{id}/delete → delete
 */
final class BlockedDatesController
{
    // ── List ──

    public function index(Request $request): Response
    {
        $tenantId = $request->getAttribute('tenant_id');

        if (!$this->canAccess($tenantId)) {
            return $this->forbidden($request);
        }

        $tenant = $this->loadTenant($tenantId);
        if ($tenant === null) {
            return Response::redirect('/admin/tenants');
        }

        if (!$this->supportsBlockedDates($tenant)) {
            return Response::redirect("/admin/tenants/{$tenantId}");
        }

        $isResourcePattern = ($tenant['booking_pattern'] ?? '') === 'resource';

        // Load all blocked dates, split into tenant-level and entity-level
        $blockedDates = Database::query(
            'SELECT bd.*, s.`name` AS staff_name, r.`name` AS resource_name
             FROM `blocked_dates` bd
             LEFT JOIN `staff` s ON s.`id` = bd.`staff_id`
             LEFT JOIN `resources` r ON r.`id` = bd.`resource_id`
             WHERE bd.`tenant_id` = ?
             ORDER BY bd.`start_date` DESC, bd.`end_date` DESC',
            [$tenantId]
        );

        // Separate upcoming from past
        $today = date('Y-m-d');
        $upcoming = [];
        $past = [];
        foreach ($blockedDates as $bd) {
            if ($bd['end_date'] >= $today) {
                $upcoming[] = $bd;
            } else {
                $past[] = $bd;
            }
        }

        // Load active staff for the "per staff" selector (timeslot pattern)
        $staff = [];
        if (($tenant['booking_pattern'] ?? '') === 'timeslot') {
            $staff = Database::query(
                'SELECT `id`, `name`, `title` FROM `staff`
                 WHERE `tenant_id` = ? AND `is_active` = 1
                 ORDER BY `sort_order` ASC, `name` ASC',
                [$tenantId]
            );
        }

        // Load active resources for the "per resource" selector (resource pattern)
        $resources = [];
        if ($isResourcePattern) {
            $resources = Database::query(
                'SELECT `id`, `name` FROM `resources`
                 WHERE `tenant_id` = ? AND `is_active` = 1
                 ORDER BY `sort_order` ASC, `name` ASC',
                [$tenantId]
            );
        }

        return $this->render('admin.tenants.blocked-dates.index', __('admin.blocked_dates.title'), [
            'recordVersions' => \App\Application\Catalog\CatalogUi::versions('blocked_dates', $tenantId, $blockedDates),
            'tenant'    => $tenant,
            'upcoming'  => $upcoming,
            'past'      => $past,
            'staff'     => $staff,
            'resources' => $resources,
            'flash'     => FormState::getToast(),
        ], $tenantId);
    }

    // ── Create ──

    public function store(Request $request): Response
    {
        return \App\Application\Catalog\CatalogUi::mutate($request, 'blocked_dates', 'create');
    }

    // ── Delete ──

    public function delete(Request $request): Response
    {
        return \App\Application\Catalog\CatalogUi::mutate($request, 'blocked_dates', 'delete');
    }

    // ── Helpers ──

    private function canAccess(string $tenantId): bool
    {
        return Auth::canAccessTenant($tenantId) && Auth::canManageTenant();
    }

    private function supportsBlockedDates(array $tenant): bool
    {
        return in_array($tenant['booking_pattern'] ?? '', ['timeslot', 'resource', 'capacity'], true);
    }

    private function isValidDate(string $date): bool
    {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)
            && strtotime($date) !== false;
    }

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
            'activePage' => 'blocked-dates',
            'csrfToken'  => CsrfMiddleware::generateToken(),
            'tenantId'   => $tenantId,
        ], $extra));
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


}
