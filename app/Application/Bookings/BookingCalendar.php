<?php

declare(strict_types=1);

namespace App\Application\Bookings;

use App\Agent\{ActorContext, OperationException};
use App\Engine\{Database, Locale};

/**
 * The admin calendar's day, week and month views as data.
 *
 * CalendarController renders these views and calendar_summary returns them to
 * agents. Both take their date windows, closures and per-day booking counts from
 * here, so an agent's totals are the numbers a person sees on the calendar.
 */
final class BookingCalendar
{
    public const VIEWS = ['day', 'week', 'month'];

    /** The month grid always shows six weeks so its height never jumps between months. */
    public const MONTH_CELLS = 42;

    /**
     * The first and last date a view shows around an anchor date.
     *
     * A week starts on the business's week start day. A month grid starts on
     * the week start on or before the 1st and runs for 42 days.
     *
     * @return array{from: string, to: string}
     */
    public static function window(string $view, string $date, int $weekStart): array
    {
        $anchor = new \DateTimeImmutable($date);
        if ($view === 'day') {
            return ['from' => $anchor->format('Y-m-d'), 'to' => $anchor->format('Y-m-d')];
        }

        $first = $view === 'month' ? $anchor->modify('first day of this month') : $anchor;
        $daysBack = ((int) $first->format('w') - $weekStart + 7) % 7;
        $begin = $first->modify("-{$daysBack} days");
        $length = $view === 'month' ? self::MONTH_CELLS : 7;

        return [
            'from' => $begin->format('Y-m-d'),
            'to' => $begin->modify('+' . ($length - 1) . ' days')->format('Y-m-d'),
        ];
    }

    /** The week start of a tenant row that carries its `week_start` and `locale` columns. */
    public static function weekStart(array $tenant): int
    {
        return Locale::weekStartForTenant($tenant);
    }

    /**
     * Every occupied booking, closure and opening day in a date range.
     *
     * Reads all pages of the shared calendar query, so the calendar never shows a
     * truncated day. Only whole-business closures block a day; staff and resource
     * blocks leave the business open.
     */
    public static function load(ActorContext $actor, string $tenantId, string $from, string $to): array
    {
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
        usort($bookings, static fn ($a, $b) => [$a['start_datetime'], $a['id']] <=> [$b['start_datetime'], $b['id']]);
        $result['bookings'] = $bookings;

        $blocked = [];
        foreach ($result['blocked_dates'] as $range) {
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

    /**
     * Bookings per local date. A stay occupies every intersected day, including
     * arrivals before the window; an end at midnight does not occupy that day.
     *
     * @return array<string, list<array>>
     */
    public static function groupOccupiedDates(array $bookings, string $from, string $to): array
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

    /** The availability table numbers weekdays 0=Monday .. 6=Sunday. */
    public static function isoDayOfWeek(\DateTimeInterface $date): int
    {
        return (int) $date->format('N') - 1;
    }

    /**
     * calendar_summary: one view's date window with per-day totals and day states.
     *
     * Counts include every occupied booking the calendar draws, whatever its
     * status, so a client can answer "how busy is next week" without paging
     * through booking records.
     */
    public static function summary(ActorContext $actor, array $input): array
    {
        $actor = BookingCommands::currentActor($actor);
        if (!$actor->isStaff()) {
            throw new OperationException('forbidden', 403);
        }
        $tenantId = $actor->business($input['business_id'] ?? null);
        $tenant = Database::query(
            'SELECT id, timezone, locale, week_start FROM tenants WHERE id = ? LIMIT 1',
            [$tenantId]
        )[0] ?? throw new OperationException('not_found', 404);

        $view = (string) ($input['view'] ?? '');
        if (!in_array($view, self::VIEWS, true)) {
            throw new OperationException('validation_failed', 422, ['view' => 'invalid']);
        }
        $zone = new \DateTimeZone($tenant['timezone']);
        $today = (new \DateTimeImmutable('now', $zone))->format('Y-m-d');
        $date = isset($input['date']) ? BookingDraft::date($input['date']) : $today;
        $weekStart = self::weekStart($tenant);
        $window = self::window($view, $date, $weekStart);

        $calendar = self::load($actor, $tenantId, $window['from'], $window['to']);
        $occupied = self::groupOccupiedDates($calendar['bookings'], $window['from'], $window['to']);
        $month = substr($date, 0, 7);
        $days = [];
        $ids = [];
        $last = new \DateTimeImmutable($window['to']);
        for ($day = new \DateTimeImmutable($window['from']); $day <= $last; $day = $day->modify('+1 day')) {
            $dayStr = $day->format('Y-m-d');
            $bookings = $occupied[$dayStr] ?? [];
            foreach ($bookings as $booking) {
                $ids[$booking['id']] = true;
            }
            $days[] = [
                'date' => $dayStr,
                'in_month' => substr($dayStr, 0, 7) === $month,
                'bookings' => count($bookings),
                'blocked' => in_array($dayStr, $calendar['blocked'], true),
                'open' => in_array(self::isoDayOfWeek($day), $calendar['available_weekdays'], true),
            ];
        }

        return [
            'view' => $view,
            'date' => $date,
            'from' => $window['from'],
            'to' => $window['to'],
            'week_start' => $weekStart,
            'today' => $today,
            'timezone' => $tenant['timezone'],
            'total' => count($ids),
            'days' => $days,
        ];
    }
}
