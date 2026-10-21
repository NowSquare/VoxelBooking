<?php

declare(strict_types=1);

namespace App\Engine;

/**
 * Canonical interpretation of the venue wall datetimes stored by bookings.
 *
 * Bookings store venue wall clocks in naive DATETIME columns. The canonical
 * instant of a stored wall time is PHP's own interpretation of it in the
 * venue timezone, and every published UTC time goes through instant().
 * Clock changes make that interpretation worth stating precisely:
 *
 * - Spring gap. A wall time inside the skipped hour never happens. Slots
 *   there are not offered (see wallTimeExists()). A stored value in the gap
 *   is moved forward by the length of the gap, as PHP does.
 * - Autumn repeat. A wall time inside the repeated hour happens twice, and
 *   PHP does not pick the same pass everywhere. East of UTC and on UTC
 *   (Amsterdam, London, Sydney, Lord Howe) it means the SECOND occurrence,
 *   the later instant on standard time: "02:30" on the Amsterdam fall-back
 *   night is 01:30 UTC. West of UTC (New York, Los Angeles) it means the
 *   FIRST occurrence, the earlier instant on summer time: "01:30" on the New
 *   York fall-back night is 05:30 UTC.
 *
 * Stored bookings keep the instant they were published with. This class
 * must never reinterpret a stored wall time, because customers, emails and
 * calendar files already carry that instant.
 *
 * A published range always has a positive length. When the two bounds'
 * instants would put the end at or before the start, the end becomes the
 * start plus the range's wall-clock length.
 *
 * Resource stays are not instants at all. They are local calendar dates and
 * are published as check-in and check-out dates (see presentRange()), never
 * as a UTC midnight that east of UTC names the previous day.
 */
final class VenueDateTime
{
    /**
     * Preserve an event's venue clocks and calendar day offset for an occurrence.
     * DateInterval hours can count a repeated hour and shift the end clock.
     *
     * @return array{start: \DateTimeImmutable, end: \DateTimeImmutable}
     */
    public static function eventRange(string $originalStart, string $originalEnd, string $date, \DateTimeZone $timezone): array
    {
        $start = new \DateTimeImmutable($originalStart, $timezone);
        $end = new \DateTimeImmutable($originalEnd, $timezone);
        $calendarZone = new \DateTimeZone('UTC');
        $startDay = new \DateTimeImmutable($start->format('Y-m-d'), $calendarZone);
        $endDay = new \DateTimeImmutable($end->format('Y-m-d'), $calendarZone);
        $dayOffset = (int) $startDay->diff($endDay)->format('%r%a');
        $instanceEndDay = (new \DateTimeImmutable($date, $calendarZone))->modify($dayOffset . ' days');

        return [
            'start' => new \DateTimeImmutable($date . ' ' . $start->format('H:i:s'), $timezone),
            'end' => new \DateTimeImmutable($instanceEndDay->format('Y-m-d') . ' ' . $end->format('H:i:s'), $timezone),
        ];
    }

    /**
     * The canonical instant of a venue wall time: PHP's interpretation of it in the venue timezone.
     * See the class policy for what that means in a spring gap or a repeated autumn hour.
     */
    public static function instant(string $wall, \DateTimeZone $timezone): \DateTimeImmutable
    {
        return new \DateTimeImmutable($wall, $timezone);
    }

    /** @return array{start_utc: string, end_utc: string} */
    public static function utcRange(string $start, string $end, string $timezone): array
    {
        $venue = new \DateTimeZone($timezone);
        $utc = new \DateTimeZone('UTC');

        // Parse both complete stored values afresh. A transient object's offset
        // can differ from this interpretation after a naive DATETIME round trip.
        $startInstant = self::instant($start, $venue);
        $endInstant = self::instant($end, $venue);
        // Keep a positive length when the bounds' instants would collapse or invert.
        if ($endInstant <= $startInstant) {
            $endInstant = $startInstant->modify('+' . self::wallSeconds($start, $end) . ' seconds');
        }

        return [
            'start_utc' => $startInstant->setTimezone($utc)->format('Y-m-d\TH:i:s\Z'),
            'end_utc' => $endInstant->setTimezone($utc)->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    /**
     * A stay's local calendar dates. Stored as local midnights; the time part carries no meaning.
     *
     * @return array{check_in: string, check_out: string, nights: int}
     */
    public static function stayRange(string $start, string $end): array
    {
        $checkIn = substr($start, 0, 10);
        $checkOut = substr($end, 0, 10);
        // Count calendar days in a zone without clock changes, so no night is 23 or 25 hours.
        $calendar = new \DateTimeZone('UTC');
        $nights = (int) (new \DateTimeImmutable($checkIn, $calendar))->diff(new \DateTimeImmutable($checkOut, $calendar))->days;

        return ['check_in' => $checkIn, 'check_out' => $checkOut, 'nights' => $nights];
    }

    /**
     * How a booking's period is published: local dates for a stay, UTC instants for everything timed.
     *
     * @return array{check_in: string, check_out: string, nights: int}|array{start_utc: string, end_utc: string}
     */
    public static function presentRange(string $pattern, string $start, string $end, string $timezone): array
    {
        return $pattern === 'resource'
            ? self::stayRange($start, $end)
            : self::utcRange($start, $end, $timezone);
    }

    /** PHP normalizes nonexistent spring times; these cannot be offered as slots. */
    public static function wallTimeExists(string $date, string $time, \DateTimeZone $timezone): bool
    {
        $wall = $date . ' ' . substr($time, 0, 5);
        return (new \DateTimeImmutable($wall, $timezone))->format('Y-m-d H:i') === $wall;
    }

    /** Elapsed wall-clock seconds between two stored values, ignoring any clock change. */
    private static function wallSeconds(string $start, string $end): int
    {
        $calendar = new \DateTimeZone('UTC');

        return max(
            0,
            (new \DateTimeImmutable($end, $calendar))->getTimestamp() - (new \DateTimeImmutable($start, $calendar))->getTimestamp(),
        );
    }
}
