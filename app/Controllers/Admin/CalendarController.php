<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Engine\Auth;
use App\Engine\Database;
use App\Engine\Locale;
use App\Engine\Request;
use App\Engine\Response;
use App\Engine\Version;
use App\Engine\View;
use App\Middleware\CsrfMiddleware;
use App\Application\Bookings\{BookingCommands, BookingQueries};

/**
 * Calendar controller (tenant-scoped).
 *
 * Access: all authenticated admin roles (operator, owner, manager).
 *
 * GET /admin/tenants/{tenant_id}/calendar          → month view (default, flagship surface)
 * GET /admin/tenants/{tenant_id}/calendar/day      → day view (detail drill-down)
 * GET /admin/tenants/{tenant_id}/calendar/week     → week view
 *
 * All views load:
 * - Bookings (from `bookings` table)
 * - Blocked dates (from `blocked_dates` table, tenant-level)
 * - Availability schedule (from `availability` table, day-of-week patterns)
 */
final class CalendarController
{
    private const HOUR_START = 7;   // Calendar starts at 07:00
    private const HOUR_END   = 21;  // Calendar ends at 21:00

    // ── Day View ──

    public function day(Request $request): Response
    {
        $tenantId = $request->getAttribute('tenant_id');

        if (!Auth::canAccessTenant($tenantId)) {
            return $this->forbidden($request);
        }

        $tenant = $this->loadTenant($tenantId);
        if ($tenant === null) {
            return Response::redirect('/admin/tenants');
        }

        $dateStr = $this->date($request, $tenant);

        $date     = new \DateTimeImmutable($dateStr, new \DateTimeZone($tenant['timezone']));
        $prevDate = $date->modify('-1 day')->format('Y-m-d');
        $nextDate = $date->modify('+1 day')->format('Y-m-d');
        $today    = (new \DateTimeImmutable('now', new \DateTimeZone($tenant['timezone'])))->format('Y-m-d');
        $isToday  = $dateStr === $today;

        $calendar = $this->calendar($tenantId, $dateStr, $dateStr);
        $bookings = $calendar['bookings'];

        // Assign columns for concurrent bookings (side-by-side layout)
        $bookings = $this->assignOverlapColumns($bookings);

        // Build hour slots for the timeline
        $hours = [];
        for ($h = self::HOUR_START; $h <= self::HOUR_END; $h++) {
            $hours[] = $h;
        }

        // Availability / blocked state for this specific day
        $isBlocked = in_array($dateStr, $calendar['blocked'], true);
        $dayOfWeek = $this->isoDayOfWeek($date);
        $hasAvailability = in_array($dayOfWeek, $calendar['available_weekdays'], true);

        return $this->render('admin.tenants.calendar.day', __('admin.calendar.page_title'), [
            'documentTitle' => __('admin.calendar.page_title') . ' — ' . $tenant['name'],
            'tenant'    => $tenant,
            'date'      => $date,
            'dateStr'   => $dateStr,
            'prevDate'  => $prevDate,
            'nextDate'  => $nextDate,
            'isToday'   => $isToday,
            'today'     => $today,
            'bookings'  => $bookings,
            'hours'     => $hours,
            'hourStart' => self::HOUR_START,
            'hourEnd'   => self::HOUR_END,
            'isBlocked'      => $isBlocked,
            'hasAvailability' => $hasAvailability,
        ], $tenantId);
    }

    // ── Week View ──

    public function week(Request $request): Response
    {
        $tenantId = $request->getAttribute('tenant_id');

        if (!Auth::canAccessTenant($tenantId)) {
            return $this->forbidden($request);
        }

        $tenant = $this->loadTenant($tenantId);
        if ($tenant === null) {
            return Response::redirect('/admin/tenants');
        }

        $dateStr = $this->date($request, $tenant);

        $date      = new \DateTimeImmutable($dateStr, new \DateTimeZone($tenant['timezone']));
        $weekStart = Locale::weekStart(); // 0=Sunday, 1=Monday
        $today     = (new \DateTimeImmutable('now', new \DateTimeZone($tenant['timezone'])))->format('Y-m-d');

        // Calculate week boundaries
        $currentDow = (int) $date->format('w'); // 0=Sun..6=Sat
        $daysBack   = ($currentDow - $weekStart + 7) % 7;
        $weekBegin  = $date->modify("-{$daysBack} days");

        $prevWeek = $weekBegin->modify('-7 days')->format('Y-m-d');
        $nextWeek = $weekBegin->modify('+7 days')->format('Y-m-d');

        // Pre-load availability dow map and blocked dates for the week range
        $weekEndStr = $weekBegin->modify('+6 days')->format('Y-m-d');
        $calendar = $this->calendar($tenantId, $weekBegin->format('Y-m-d'), $weekEndStr);
        $availableDows = $calendar['available_weekdays'];
        $blockedDatesInRange = $calendar['blocked'];
        $bookingsByDate = $this->groupOccupiedDates($calendar['bookings'], $weekBegin->format('Y-m-d'), $weekEndStr);

        // Build 7 days with their bookings + state
        $days = [];
        for ($i = 0; $i < 7; $i++) {
            $dayDate = $weekBegin->modify("+{$i} days");
            $dayStr  = $dayDate->format('Y-m-d');
            $dow     = $this->isoDayOfWeek($dayDate);
            $days[]  = [
                'date'            => $dayDate,
                'dateStr'         => $dayStr,
                'isToday'         => $dayStr === $today,
                'bookings'        => $bookingsByDate[$dayStr] ?? [],
                'isBlocked'       => in_array($dayStr, $blockedDatesInRange, true),
                'hasAvailability' => in_array($dow, $availableDows, true),
            ];
        }

        return $this->render('admin.tenants.calendar.week', __('admin.calendar.page_title'), [
            'documentTitle' => __('admin.calendar.page_title') . ' — ' . $tenant['name'],
            'tenant'    => $tenant,
            'date'      => $date,
            'dateStr'   => $dateStr,
            'weekBegin' => $weekBegin,
            'prevWeek'  => $prevWeek,
            'nextWeek'  => $nextWeek,
            'today'     => $today,
            'days'      => $days,
        ], $tenantId);
    }

    // ── Month View ──

    public function month(Request $request): Response
    {
        $tenantId = $request->getAttribute('tenant_id');

        if (!Auth::canAccessTenant($tenantId)) {
            return $this->forbidden($request);
        }

        $tenant = $this->loadTenant($tenantId);
        if ($tenant === null) {
            return Response::redirect('/admin/tenants');
        }

        $dateStr = $this->date($request, $tenant);

        $date      = new \DateTimeImmutable($dateStr, new \DateTimeZone($tenant['timezone']));
        $today     = (new \DateTimeImmutable('now', new \DateTimeZone($tenant['timezone'])))->format('Y-m-d');
        $year      = (int) $date->format('Y');
        $month     = (int) $date->format('n');
        $weekStart = Locale::weekStart(); // 0=Sunday, 1=Monday

        // Previous / next month navigation
        $prevMonth = $date->modify('first day of previous month')->format('Y-m-d');
        $nextMonth = $date->modify('first day of next month')->format('Y-m-d');

        // First day of the month and total days
        $firstOfMonth = new \DateTimeImmutable("$year-$month-01");
        $daysInMonth  = (int) $firstOfMonth->format('t');

        // Calculate grid padding: how many days from previous month to show
        $firstDow    = (int) $firstOfMonth->format('w'); // 0=Sun..6=Sat
        $paddingBefore = ($firstDow - $weekStart + 7) % 7;

        // Grid start date (may be in previous month)
        $gridStart = $firstOfMonth->modify("-{$paddingBefore} days");

        // Always show 6 rows = 42 cells for a consistent grid height
        $totalCells   = 42;
        $gridEnd      = $gridStart->modify('+' . ($totalCells - 1) . ' days');

        // Fetch all bookings in the visible range with a single query
        $calendar = $this->calendar(
            $tenantId,
            $gridStart->format('Y-m-d'),
            $gridEnd->format('Y-m-d')
        );

        // Group bookings by date for O(1) lookup in template
        $bookingsByDate = $this->groupOccupiedDates($calendar['bookings'], $gridStart->format('Y-m-d'), $gridEnd->format('Y-m-d'));

        // Pre-load availability + blocked dates for the grid range
        $availableDows = $calendar['available_weekdays'];
        $blockedDatesInRange = $calendar['blocked'];

        // Build the grid cells
        $cells = [];
        for ($i = 0; $i < $totalCells; $i++) {
            $cellDate = $gridStart->modify("+{$i} days");
            $cellStr  = $cellDate->format('Y-m-d');
            $cellMonth = (int) $cellDate->format('n');
            $dow      = $this->isoDayOfWeek($cellDate);
            $cells[] = [
                'date'            => $cellDate,
                'dateStr'         => $cellStr,
                'day'             => (int) $cellDate->format('j'),
                'isToday'         => $cellStr === $today,
                'isCurrentMonth'  => $cellMonth === $month,
                'bookings'        => $bookingsByDate[$cellStr] ?? [],
                'isBlocked'       => in_array($cellStr, $blockedDatesInRange, true),
                'hasAvailability' => in_array($dow, $availableDows, true),
            ];
        }

        return $this->render('admin.tenants.calendar.month', __('admin.calendar.page_title'), [
            'documentTitle' => __('admin.calendar.page_title') . ' — ' . $tenant['name'],
            'tenant'    => $tenant,
            'date'      => $date,
            'dateStr'   => $dateStr,
            'year'      => $year,
            'month'     => $month,
            'prevMonth' => $prevMonth,
            'nextMonth' => $nextMonth,
            'today'     => $today,
            'cells'     => $cells,
        ], $tenantId);
    }

    /** The UI consumes the same paginated calendar projection as HTTP and MCP. */
    /** A stay occupies every intersected local day, including arrivals before this page. */
    private function groupOccupiedDates(array $bookings, string $from, string $to): array
    {
        $days = [];
        foreach ($bookings as $booking) {
            $start = max($from, substr($booking['start_datetime'], 0, 10));
            $last = min($to, (new \DateTimeImmutable($booking['end_datetime']))->modify('-1 second')->format('Y-m-d'));
            for ($day = new \DateTimeImmutable($start); $day->format('Y-m-d') <= $last; $day = $day->modify('+1 day')) {
                $days[$day->format('Y-m-d')][] = $booking;
            }
        }
        return $days;
    }

    private function calendar(string $tenantId, string $from, string $to): array
    {
        $actor = BookingCommands::sessionActor($tenantId);
        $input = ['business_id' => $tenantId, 'from' => $from, 'to' => $to, 'limit' => 100];
        $result = null;
        $bookings = [];
        do {
            $page = BookingQueries::calendar($actor, $input);
            $result ??= $page;
            foreach ($page['bookings'] as $booking) {
                $booking['customer_name'] = $booking['customer']['name'];
                $booking['customer_email'] = $booking['customer']['email'];
                $bookings[] = $booking;
            }
            $input['cursor'] = $page['next_cursor'];
        } while ($input['cursor'] !== null);
        usort($bookings, static fn($a, $b) => [$a['start_datetime'], $a['id']] <=> [$b['start_datetime'], $b['id']]);
        $result['bookings'] = $bookings;
        $blocked = [];
        foreach ($result['blocked_dates'] as $range) {
            // Only whole-business closures block a day; staff and resource blocks leave it open.
            if ($range['staff_id'] !== null || $range['resource_id'] !== null) {
                continue;
            }
            $day = new \DateTimeImmutable(max($from, $range['start_date']));
            $end = min($to, $range['end_date']);
            while ($day->format('Y-m-d') <= $end) {
                $blocked[] = $day->format('Y-m-d');
                $day = $day->modify('+1 day');
            }
        }
        $result['blocked'] = array_values(array_unique($blocked));
        return $result;
    }

    private function date(Request $request, array $tenant): string
    {
        $today = new \DateTimeImmutable('today', new \DateTimeZone($tenant['timezone']));
        $input = $request->string('date');
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $input, $today->getTimezone());
        return $date !== false && $date->format('Y-m-d') === $input ? $input : $today->format('Y-m-d');
    }

    // ── Shared Helpers ──

    /**
     * Assign side-by-side columns for concurrent bookings.
     *
     * Uses a greedy column-packing algorithm: sort by start, assign each
     * booking to the leftmost column where it doesn't overlap with any
     * existing booking. Each booking gets `colIndex` (0-based) and
     * `colTotal` (max columns in its overlap group).
     *
     * @param  array<int, array<string, mixed>>  $bookings
     * @return array<int, array<string, mixed>>
     */
    private function assignOverlapColumns(array $bookings): array
    {
        if (count($bookings) <= 1) {
            foreach ($bookings as &$b) {
                $b['colIndex'] = 0;
                $b['colTotal'] = 1;
            }
            return $bookings;
        }

        // Sort by start time, then by end time (broader first)
        usort($bookings, function (array $a, array $b): int {
            $cmp = $a['start_datetime'] <=> $b['start_datetime'];
            return $cmp !== 0 ? $cmp : ($a['end_datetime'] <=> $b['end_datetime']);
        });

        // Track column end-times: $columns[$col] = latest end_datetime in that column
        $columns = [];

        foreach ($bookings as &$booking) {
            $start = $booking['start_datetime'];
            $placed = false;

            // Try to fit into the leftmost available column
            foreach ($columns as $col => $colEnd) {
                if ($start >= $colEnd) {
                    $columns[$col] = $booking['end_datetime'];
                    $booking['colIndex'] = $col;
                    $placed = true;
                    break;
                }
            }

            if (!$placed) {
                $booking['colIndex'] = count($columns);
                $columns[] = $booking['end_datetime'];
            }
        }
        unset($booking);

        // Now find connected overlap groups and assign colTotal
        $totalCols = count($columns);
        foreach ($bookings as &$booking) {
            $booking['colTotal'] = $totalCols;
        }
        unset($booking);

        return $bookings;
    }

    private function loadTenant(string $tenantId): ?array
    {
        $rows = Database::query(
            'SELECT `id`, `name`, `slug`, `email`, `timezone`, `currency`, `brand_color` FROM `tenants` WHERE `id` = ? LIMIT 1',
            [$tenantId]
        );

        return $rows[0] ?? null;
    }

    /**
     * Convert PHP weekday numbering to the availability table's ISO convention.
     * PHP `N`: 1=Mon .. 7=Sun → DB: 0=Mon .. 6=Sun.
     */
    private function isoDayOfWeek(\DateTimeInterface $date): int
    {
        return (int) $date->format('N') - 1;
    }

    private function render(string $template, string $pageTitle, array $extra, string $tenantId): Response
    {
        return View::response($template, array_merge([
            'user'       => Auth::user(),
            'version'    => Version::get(),
            'pageTitle'  => $pageTitle,
            'activePage' => 'calendar',
            'csrfToken'  => CsrfMiddleware::generateToken(),
            'tenantId'   => $tenantId,
        ], $extra));
    }

    private function forbidden(Request $request): Response
    {
        if ($request->isJson()) {
            return Response::json([
                'error'   => 'forbidden',
                'message' => 'Access denied.',
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
