<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Engine\Auth;
use App\Engine\FormState;
use App\Engine\Database;
use App\Engine\Request;
use App\Engine\Response;
use App\Engine\Version;
use App\Engine\View;
use App\Middleware\CsrfMiddleware;

/**
 * Availability management controller (tenant-scoped).
 *
 * Access: operators + business owners (Auth::canManageTenant()).
 * Managers cannot access any of these routes.
 *
 * GET  /admin/tenants/{tenant_id}/availability                     → weekly grid editor (tenant defaults)
 * POST /admin/tenants/{tenant_id}/availability                     → save tenant defaults
 * GET  /admin/tenants/{tenant_id}/availability/staff/{id}          → staff override editor
 * POST /admin/tenants/{tenant_id}/availability/staff/{id}          → save staff override
 * POST /admin/tenants/{tenant_id}/availability/staff/{id}/reset    → reset staff to defaults
 */
final class AvailabilityController
{
    /** Day labels indexed 0=Mon … 6=Sun */
    private const DAY_KEYS = ['day_mon', 'day_tue', 'day_wed', 'day_thu', 'day_fri', 'day_sat', 'day_sun'];

    // ── Tenant defaults ──

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

        if (($tenant['booking_pattern'] ?? '') !== 'timeslot') {
            return Response::redirect("/admin/tenants/{$tenantId}");
        }

        // Load tenant-level availability (staff_id IS NULL)
        $rows = Database::query(
            'SELECT `day_of_week`, `start_time`, `end_time`
             FROM `availability`
             WHERE `tenant_id` = ? AND `staff_id` IS NULL AND `is_available` = 1
             ORDER BY `day_of_week` ASC, `start_time` ASC',
            [$tenantId]
        );

        $schedule = $this->rowsToSchedule($rows);

        // Load active staff for the staff selector
        $staff = Database::query(
            'SELECT `id`, `name`, `title` FROM `staff`
             WHERE `tenant_id` = ? AND `is_active` = 1
             ORDER BY `sort_order` ASC, `name` ASC',
            [$tenantId]
        );

        // Check which staff have their own overrides
        $staffOverrides = [];
        if (!empty($staff)) {
            $staffIds = array_column($staff, 'id');
            $placeholders = implode(',', array_fill(0, count($staffIds), '?'));
            $overrideRows = Database::query(
                "SELECT DISTINCT `staff_id` FROM `availability`
                 WHERE `tenant_id` = ? AND `staff_id` IN ({$placeholders})",
                array_merge([$tenantId], $staffIds)
            );
            $staffOverrides = array_column($overrideRows, 'staff_id');
        }

        return $this->render('admin.tenants.availability.index', __('admin.availability.title'), [
            'tenant'         => $tenant,
            'schedule'       => $schedule,
            'staff'          => $staff,
            'staffOverrides' => $staffOverrides,
            'currentStaffId' => null,
            'currentStaff'   => null,
            'flash'          => FormState::getToast(),
        ], $tenantId);
    }

    public function save(Request $request): Response
    {
        return \App\Application\Catalog\CatalogUi::schedule($request, 'save');
    }

    // ── Staff overrides ──

    public function staffOverride(Request $request): Response
    {
        $tenantId = $request->getAttribute('tenant_id');
        $staffId  = $request->getAttribute('id');

        if (!$this->canAccess($tenantId)) {
            return $this->forbidden($request);
        }

        $tenant = $this->loadTenant($tenantId);
        if ($tenant === null) {
            return Response::redirect('/admin/tenants');
        }

        if (($tenant['booking_pattern'] ?? '') !== 'timeslot') {
            return Response::redirect("/admin/tenants/{$tenantId}");
        }

        $staffMember = $this->loadStaff($tenantId, $staffId);
        if ($staffMember === null) {
            return Response::redirect("/admin/tenants/{$tenantId}/availability");
        }

        // Load staff-level availability
        $rows = Database::query(
            'SELECT `day_of_week`, `start_time`, `end_time`
             FROM `availability`
             WHERE `tenant_id` = ? AND `staff_id` = ? AND `is_available` = 1
             ORDER BY `day_of_week` ASC, `start_time` ASC',
            [$tenantId, $staffId]
        );

        $hasOverride = !empty($rows);

        // If no staff override, load tenant defaults for display
        if (!$hasOverride) {
            $rows = Database::query(
                'SELECT `day_of_week`, `start_time`, `end_time`
                 FROM `availability`
                 WHERE `tenant_id` = ? AND `staff_id` IS NULL AND `is_available` = 1
                 ORDER BY `day_of_week` ASC, `start_time` ASC',
                [$tenantId]
            );
        }

        $schedule = $this->rowsToSchedule($rows);

        // Load active staff for selector
        $staff = Database::query(
            'SELECT `id`, `name`, `title` FROM `staff`
             WHERE `tenant_id` = ? AND `is_active` = 1
             ORDER BY `sort_order` ASC, `name` ASC',
            [$tenantId]
        );

        // Check which staff have overrides
        $staffOverrides = [];
        if (!empty($staff)) {
            $staffIds = array_column($staff, 'id');
            $placeholders = implode(',', array_fill(0, count($staffIds), '?'));
            $overrideRows = Database::query(
                "SELECT DISTINCT `staff_id` FROM `availability`
                 WHERE `tenant_id` = ? AND `staff_id` IN ({$placeholders})",
                array_merge([$tenantId], $staffIds)
            );
            $staffOverrides = array_column($overrideRows, 'staff_id');
        }

        return $this->render('admin.tenants.availability.index', __('admin.availability.title'), [
            'tenant'         => $tenant,
            'schedule'       => $schedule,
            'staff'          => $staff,
            'staffOverrides' => $staffOverrides,
            'currentStaffId' => $staffId,
            'currentStaff'   => $staffMember,
            'hasOverride'    => $hasOverride,
            'flash'          => FormState::getToast(),
        ], $tenantId);
    }

    public function saveStaffOverride(Request $request): Response
    {
        return \App\Application\Catalog\CatalogUi::schedule($request, 'save');
    }

    public function resetStaffOverride(Request $request): Response
    {
        return \App\Application\Catalog\CatalogUi::schedule($request, 'reset');
    }

    // ── Helpers ──

    /**
     * Convert DB rows into a 7-element schedule array (0=Mon … 6=Sun).
     * Each day is an array of ['start' => 'HH:MM', 'end' => 'HH:MM'] windows.
     *
     * @return array<int, list<array{start: string, end: string}>>
     */
    private function rowsToSchedule(array $rows): array
    {
        $schedule = array_fill(0, 7, []);

        foreach ($rows as $row) {
            $day = (int) $row['day_of_week'];
            $schedule[$day][] = [
                'start' => substr($row['start_time'], 0, 5),
                'end'   => substr($row['end_time'], 0, 5),
            ];
        }

        return $schedule;
    }



    private function canAccess(string $tenantId): bool
    {
        return Auth::canAccessTenant($tenantId) && Auth::canManageTenant();
    }

    private function loadTenant(string $tenantId): ?array
    {
        $rows = Database::query('SELECT * FROM `tenants` WHERE `id` = ? LIMIT 1', [$tenantId]);
        return $rows[0] ?? null;
    }

    private function loadStaff(string $tenantId, string $staffId): ?array
    {
        $rows = Database::query(
            'SELECT * FROM `staff` WHERE `id` = ? AND `tenant_id` = ? LIMIT 1',
            [$staffId, $tenantId]
        );
        return $rows[0] ?? null;
    }

    private function render(string $template, string $pageTitle, array $extra, string $tenantId): Response
    {
        $extra['recordVersion'] = \App\Application\Catalog\CatalogUi::scheduleVersion($tenantId, $extra['currentStaffId'] ?? null);
        return View::response($template, array_merge([
            'user'       => Auth::user(),
            'version'    => Version::get(),
            'pageTitle'  => $pageTitle,
            'activePage' => 'availability',
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
