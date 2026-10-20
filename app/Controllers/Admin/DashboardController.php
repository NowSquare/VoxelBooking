<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Engine\Auth;
use App\Engine\Database;
use App\Engine\Request;
use App\Engine\Response;
use App\Engine\Version;
use App\Engine\View;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Tenant;

/**
 * Operator dashboard controller.
 *
 * Queries real tenant/booking metrics from the database,
 * including week-over-week deltas and upcoming bookings.
 */
final class DashboardController
{
    public function index(Request $request): Response
    {
        // Operator dashboard — business users should never reach this
        // (AuthMiddleware redirects them to /admin/tenants/{id}, but belt-and-suspenders)
        if (!Auth::isOperator()) {
            return Response::redirect('/admin/login');
        }

        $tenantCounts = Tenant::counts();

        $today = date('Y-m-d');
        $todayEnd = $today . ' 23:59:59';
        $now = date('Y-m-d H:i:s');
        $weekStart = date('Y-m-d', strtotime('-6 days'));
        $now24h = (new \DateTimeImmutable($now))->setTimezone(new \DateTimeZone('UTC'))->modify('+24 hours')->format('c');
        $prevDay = date('Y-m-d', strtotime('-7 days'));
        $prevWeekStart = date('Y-m-d', strtotime('-13 days'));
        $prevWeekEnd = date('Y-m-d', strtotime('-7 days'));

        $todayBookings = $this->queryCount(
            "SELECT COUNT(*) as cnt FROM `bookings` WHERE DATE(`start_datetime`) = ?",
            [$today]
        );
        $weekBookings = $this->queryCount(
            "SELECT COUNT(*) as cnt FROM `bookings` WHERE `start_datetime` >= ? AND `start_datetime` <= ?",
            [$weekStart, $todayEnd]
        );
        $upcoming24h = Booking::countUpcomingBetween($now, $now24h);

        // Deltas: same-day-last-week for daily, previous-7-day-window for weekly
        $prevTodayBookings = $this->queryCount(
            "SELECT COUNT(*) as cnt FROM `bookings` WHERE DATE(`start_datetime`) = ?",
            [$prevDay]
        );
        $prevWeekBookings = $this->queryCount(
            "SELECT COUNT(*) as cnt FROM `bookings` WHERE `start_datetime` >= ? AND `start_datetime` < ?",
            [$prevWeekStart, $prevWeekEnd]
        );

        $deltaToday = $todayBookings - $prevTodayBookings;
        $deltaWeek = $weekBookings - $prevWeekBookings;

        // Cross-tenant upcoming bookings list
        $upcoming = Booking::allUpcoming($now, 5);

        return View::response('admin.dashboard', [
            'user'           => Auth::user(),
            'version'        => Version::get(),
            'activeTenants'  => $tenantCounts['active'],
            'tenantCounts'   => $tenantCounts,
            'todayBookings'  => $todayBookings,
            'weekBookings'   => $weekBookings,
            'upcoming24h'    => $upcoming24h,
            'deltaToday'     => $deltaToday,
            'deltaWeek'      => $deltaWeek,
            'upcoming'       => $upcoming,
            'pendingApprovals' => $this->queryCount("SELECT COUNT(*) AS cnt FROM `bookings` WHERE `status` = 'pending'"),
        ]);
    }

    /**
     * Tenant-scoped business dashboard.
     *
     * Business users land here via AuthMiddleware redirect from /admin.
     * Operators can also view any tenant's dashboard.
     */
    public function tenantDashboard(Request $request): Response
    {
        $tenantId = $request->getAttribute('tenant_id');

        // Business user: verify tenant ownership
        if (Auth::isBusinessUser()) {
            $user = Auth::user();
            if (($user['tenant_id'] ?? '') !== $tenantId) {
                return Response::redirect('/admin');
            }
        }

        $tenant = Tenant::find($tenantId);
        if ($tenant === null) {
            return Response::redirect('/admin');
        }

        $tenantNow = new \DateTimeImmutable('now', new \DateTimeZone($tenant['timezone'] ?? 'UTC'));
        $today = $tenantNow->format('Y-m-d');
        $weekStart = $tenantNow->modify('-6 days')->format('Y-m-d');
        $monthStart = $tenantNow->format('Y-m-01');
        $now = $tenantNow->format('Y-m-d H:i:s');

        $todayBookings = Booking::countForTenant($tenantId, null, $today, $today);
        $weekBookings = Booking::countForTenant($tenantId, null, $weekStart, $today);
        $monthBookings = Booking::countForTenant($tenantId, null, $monthStart, $today);
        $totalCustomers = Customer::countForTenant($tenantId, null);

        // Deltas: same-day-last-week for daily, previous-7-day-window for weekly
        $prevDay = $tenantNow->modify('-7 days')->format('Y-m-d');
        $prevWeekStart = $tenantNow->modify('-13 days')->format('Y-m-d');
        $prevWeekEnd = $tenantNow->modify('-7 days')->format('Y-m-d');

        $prevTodayBookings = Booking::countForTenant($tenantId, null, $prevDay, $prevDay);
        $prevWeekBookings = Booking::countForTenant($tenantId, null, $prevWeekStart, $prevWeekEnd);

        $deltaToday = $todayBookings - $prevTodayBookings;
        $deltaWeek = $weekBookings - $prevWeekBookings;

        // Today's schedule for the schedule strip
        $todaySchedule = Booking::forTenantDate($tenantId, $today);

        // "Who's working today" — timeslot-pattern tenants only
        $staffWorkingToday = null;
        if (($tenant['booking_pattern'] ?? '') === 'timeslot') {
            $staffWorkingToday = $this->loadStaffWorkingToday($tenantId, $tenantNow);
        }

        return View::response('admin.dashboard-business', [
            'user'           => Auth::user(),
            'version'        => Version::get(),
            'pageTitle'      => $tenant['name'],
            'activePage'     => 'dashboard',
            'csrfToken'      => \App\Middleware\CsrfMiddleware::generateToken(),
            'tenant'         => $tenant,
            'dashboardDate'  => $tenantNow,
            'todayBookings'  => $todayBookings,
            'weekBookings'   => $weekBookings,
            'monthBookings'  => $monthBookings,
            'totalCustomers' => $totalCustomers,
            'upcoming'       => Booking::forTenantUpcoming($tenantId, $now, 5),
            'deltaToday'     => $deltaToday,
            'deltaWeek'      => $deltaWeek,
            'todaySchedule'  => $todaySchedule,
            'staffWorkingToday' => $staffWorkingToday,
            'pendingApprovals' => Booking::countForTenant($tenantId, 'pending', null, null),
            'setupActions' => $this->loadSetupActions($tenantId, $tenant['booking_pattern'] ?? 'timeslot'),
        ]);
    }

    /**
     * Surface missing prerequisites for this booking pattern, never optional staff.
     * These notices describe configuration only; they do not claim availability.
     *
     * @return list<array{key: string, path: string}>
     */
    private function loadSetupActions(string $tenantId, string $pattern): array
    {
        $catalog = match ($pattern) {
            'resource' => ['resources', 'setup_resources', 'resources'],
            'capacity' => ['capacity_slots', 'setup_capacity', 'capacity-slots'],
            'event' => ['events', 'setup_events', 'events'],
            default => ['services', 'setup_services', 'services'],
        };
        [$table, $key, $path] = $catalog;
        $count = Database::query("SELECT COUNT(*) AS cnt FROM `{$table}` WHERE `tenant_id` = ? AND `is_active` = 1", [$tenantId]);
        if ((int) ($count[0]['cnt'] ?? 0) === 0) {
            return [['key' => $key, 'path' => $path]];
        }
        if ($pattern !== 'timeslot') {
            return [];
        }

        $actions = [];
        $hours = Database::query('SELECT COUNT(*) AS cnt FROM `availability` WHERE `tenant_id` = ? AND `is_available` = 1', [$tenantId]);
        if ((int) ($hours[0]['cnt'] ?? 0) === 0) {
            $actions[] = ['key' => 'setup_hours', 'path' => 'availability'];
        }
        return $actions;
    }

    /**
     * Load active staff with availability windows for today.
     *
     * Resolution order per staff member:
     * 1. Staff-specific override rows in `availability` (staff_id = X)
     * 2. Tenant default rows (staff_id IS NULL)
     *
     * Returns null (no staff exist) or array of staff with their windows.
     * Staff who have no windows today are excluded from the result.
     *
     * @return list<array{id: string, name: string, title: ?string, windows: list<array{start: string, end: string}>}>|null
     */
    private function loadStaffWorkingToday(string $tenantId, ?\DateTimeImmutable $today = null): ?array
    {
        // 1. Load all active staff
        $allStaff = Database::query(
            'SELECT `id`, `name`, `title` FROM `staff`
             WHERE `tenant_id` = ? AND `is_active` = 1
             ORDER BY `sort_order` ASC, `name` ASC',
            [$tenantId]
        );

        if (empty($allStaff)) {
            return null; // Staff are optional; omit an empty staff section.
        }

        // 2. Today's day_of_week (0=Mon … 6=Sun, ISO convention)
        $todayDow = ((int) ($today ?? new \DateTimeImmutable())->format('N')) - 1;

        // 3. Bulk-load all staff overrides for today in one query
        $staffIds = array_column($allStaff, 'id');
        $placeholders = implode(',', array_fill(0, count($staffIds), '?'));
        $overrideRows = Database::query(
            "SELECT `staff_id`, `start_time`, `end_time`
             FROM `availability`
             WHERE `tenant_id` = ? AND `staff_id` IN ({$placeholders})
              AND `day_of_week` = ? AND `is_available` = 1
             ORDER BY `start_time` ASC",
            array_merge([$tenantId], $staffIds, [$todayDow])
        );

        // Index overrides by staff_id
        $overrideMap = [];
        $staffWithOverrides = [];
        foreach ($overrideRows as $row) {
            $overrideMap[$row['staff_id']][] = [
                'start' => substr($row['start_time'], 0, 5),
                'end'   => substr($row['end_time'], 0, 5),
            ];
            $staffWithOverrides[$row['staff_id']] = true;
        }

        // 4. Which staff have ANY override rows (even for other days)?
        //    Staff with overrides on other days but NOT today → they're off today.
        $anyOverrideRows = Database::query(
            "SELECT DISTINCT `staff_id` FROM `availability`
             WHERE `tenant_id` = ? AND `staff_id` IN ({$placeholders})",
            array_merge([$tenantId], $staffIds)
        );
        $hasAnyOverride = [];
        foreach ($anyOverrideRows as $row) {
            $hasAnyOverride[$row['staff_id']] = true;
        }

        // 5. Load tenant defaults for today (fallback for staff without overrides)
        $defaultRows = Database::query(
            'SELECT `start_time`, `end_time`
             FROM `availability`
             WHERE `tenant_id` = ? AND `staff_id` IS NULL
              AND `day_of_week` = ? AND `is_available` = 1
             ORDER BY `start_time` ASC',
            [$tenantId, $todayDow]
        );
        $defaultWindows = [];
        foreach ($defaultRows as $row) {
            $defaultWindows[] = [
                'start' => substr($row['start_time'], 0, 5),
                'end'   => substr($row['end_time'], 0, 5),
            ];
        }

        // 6. Resolve per-staff: override → tenant default, attach windows
        $result = [];
        foreach ($allStaff as $staff) {
            $sid = $staff['id'];

            if (isset($staffWithOverrides[$sid])) {
                // Has override windows for today → use them
                $windows = $overrideMap[$sid];
            } elseif (isset($hasAnyOverride[$sid])) {
                // Has overrides but none for today → staff is off today
                $windows = [];
            } else {
                // No overrides at all → fall back to tenant defaults
                $windows = $defaultWindows;
            }

            if (empty($windows)) {
                continue; // not working today
            }

            $result[] = [
                'id'      => $sid,
                'name'    => $staff['name'],
                'title'   => $staff['title'] ?? null,
                'windows' => $windows,
            ];
        }

        return $result;
    }

    private function queryCount(string $sql, array $bindings = []): int
    {
        try {
            $rows = Database::query($sql, $bindings);
            return (int) ($rows[0]['cnt'] ?? 0);
        } catch (\Throwable) {
            return 0;
        }
    }
}
