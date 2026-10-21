<?php

declare(strict_types=1);
namespace App\Application\Business;

use App\Agent\ActorContext;
use App\Agent\OperationException;
use App\Engine\Database;
use App\Models\Booking;
use App\Models\Customer;

/** One source for browser dashboards and agent summary metrics. */
final class DashboardService
{
    public static function business(ActorContext $actor, array $input): array
    {
        $actor = BusinessAccess::actor($actor, 'reports:read', 'staff');
        if (!in_array('bookings:read', $actor->scopes, true)) { throw new OperationException('insufficient_scope', 403); }
        $tenant = BusinessAccess::tenant($actor, $input);
        return self::businessData($tenant);
    }
    private static function businessData(array $tenant): array
    {
        $tenantId = $tenant['id'];
        $now = new \DateTimeImmutable('now',new \DateTimeZone($tenant['timezone']));
        $today = $now->format('Y-m-d');
        $weekStart = $now->modify('-6 days')->format('Y-m-d');
        $todayCount = Booking::countForTenant($tenantId,null,$today,$today);
        $weekCount = Booking::countForTenant($tenantId,null,$weekStart,$today);
        return [
            'tenant'=>$tenant, 'dashboardDate'=>$now,
            'todayBookings'=>$todayCount, 'weekBookings'=>$weekCount,
            'monthBookings'=>Booking::countForTenant($tenantId,null,$now->format('Y-m-01'),$today),
            'totalCustomers'=>Customer::countForTenant($tenantId,null),
            'deltaToday'=>$todayCount-Booking::countForTenant($tenantId,null,$now->modify('-7 days')->format('Y-m-d'),$now->modify('-7 days')->format('Y-m-d')),
            'deltaWeek'=>$weekCount-Booking::countForTenant($tenantId,null,$now->modify('-13 days')->format('Y-m-d'),$now->modify('-7 days')->format('Y-m-d')),
            'upcoming'=>Booking::forTenantUpcoming($tenantId,$now->format('Y-m-d H:i:s'),5),
            'todaySchedule'=>Booking::forTenantDate($tenantId,$today),
            'staffWorkingToday'=>$tenant['booking_pattern']==='timeslot'?self::loadStaffWorkingToday($tenantId,$now):null,
            'pendingApprovals'=>Booking::countForTenant($tenantId,'pending',null,null),
            'setupActions'=>self::loadSetupActions($tenantId,$tenant['booking_pattern']),
        ];
    }
    public static function operator(ActorContext $actor): array
    {
        $actor = BusinessAccess::actor($actor,'reports:read');
        if (!in_array('bookings:read',$actor->scopes,true)) { throw new OperationException('insufficient_scope',403); }
        $counts = ['active'=>0,'paused'=>0,'archived'=>0,'total'=>0];
        $data = ['todayBookings'=>0,'weekBookings'=>0,'deltaToday'=>0,'deltaWeek'=>0,'upcoming24h'=>0,'pendingApprovals'=>0];
        $upcoming = [];
        $now = new \DateTimeImmutable('now',new \DateTimeZone('UTC'));
        foreach ($actor->businessIds as $id) {
            $tenant = Database::query('SELECT * FROM tenants WHERE id = ?',[$id])[0] ?? null;
            if (!$tenant) { continue; }
            $counts[$tenant['status']]++; $counts['total']++;
            $summary = self::businessData($tenant);
            foreach (['todayBookings','weekBookings','deltaToday','deltaWeek','pendingApprovals'] as $key) { $data[$key]+=$summary[$key]; }
            $zone = new \DateTimeZone($tenant['timezone']);
            $data['upcoming24h'] += (int)Database::query("SELECT COUNT(*) AS total FROM bookings WHERE tenant_id = ? AND status = 'confirmed' AND start_datetime >= ? AND start_datetime < ?",[$id,$now->setTimezone($zone)->format('Y-m-d H:i:s'),$now->modify('+24 hours')->setTimezone($zone)->format('Y-m-d H:i:s')])[0]['total'];
            foreach ($summary['upcoming'] as $booking) {
                $booking['tenant_timezone'] = $tenant['timezone'];
                $upcoming[] = $booking;
            }
        }
        usort($upcoming,static fn(array $a,array $b):int => (new \DateTimeImmutable($a['start_datetime'],new \DateTimeZone($a['tenant_timezone'])))->getTimestamp() <=> (new \DateTimeImmutable($b['start_datetime'],new \DateTimeZone($b['tenant_timezone'])))->getTimestamp());
        return [...$data,'activeTenants'=>$counts['active'],'tenantCounts'=>$counts,'upcoming'=>array_slice($upcoming,0,5)];
    }
    public static function query(ActorContext $actor,array $input,bool $operator=false): array
    {
        $data = $operator ? self::operator($actor) : self::business($actor,$input);
        $tenant = $data['tenant'] ?? null;
        $timezone = $tenant['timezone'] ?? 'UTC';
        $now = new \DateTimeImmutable('now',new \DateTimeZone($timezone));
        $booking = static function(array $row) use ($timezone): array {
            $zone = new \DateTimeZone($row['tenant_timezone'] ?? $timezone);
            return ['id'=>$row['id'],'business_id'=>$row['tenant_id'] ?? null,'customer_name'=>$row['customer_name'] ?? null,'status'=>$row['status'],
                'booking_pattern'=>$row['booking_pattern'],'start_datetime'=>(new \DateTimeImmutable($row['start_datetime'],$zone))->format(DATE_ATOM),
                'end_datetime'=>(new \DateTimeImmutable($row['end_datetime'],$zone))->format(DATE_ATOM),
                'service_name'=>$row['service_name'] ?? null,'resource_name'=>$row['resource_name'] ?? null,'event_name'=>$row['event_name'] ?? null];
        };
        return ['business_id'=>$tenant['id'] ?? null,'timezone'=>$timezone,'generated_at'=>$now->format(DATE_ATOM),
            'date_basis'=>$operator?'Each business local calendar date; upcoming 24 hours measured as instants.':'Business local calendar date.',
            'metrics'=>['today_bookings'=>$data['todayBookings'],'week_bookings'=>$data['weekBookings'],'month_bookings'=>$data['monthBookings'] ?? null,
                'today_change'=>$data['deltaToday'],'week_change'=>$data['deltaWeek'],'customers'=>$data['totalCustomers'] ?? null,
                'pending_approvals'=>$data['pendingApprovals'],'upcoming_24h'=>$data['upcoming24h'] ?? null,'businesses'=>$data['tenantCounts'] ?? null],
            'upcoming'=>array_map($booking,$data['upcoming']), 'today_schedule'=>array_map($booking,array_slice($data['todaySchedule'] ?? [],0,500)),
            'today_schedule_truncated'=>count($data['todaySchedule'] ?? [])>500, 'staff_working_today'=>$data['staffWorkingToday'] ?? null,
            'setup_actions'=>array_map(static fn(array $action):array=>['key'=>$action['key'],'url'=>app_url('/admin/tenants/'.$tenant['id'].'/'.$action['path'])],$data['setupActions'] ?? [])];
    }
    private static function loadSetupActions(string $tenantId, string $pattern): array
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
    private static function loadStaffWorkingToday(string $tenantId, ?\DateTimeImmutable $today = null): ?array
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


}
