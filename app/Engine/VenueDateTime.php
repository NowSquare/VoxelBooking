<?php

declare(strict_types=1);

namespace App\Engine;

/** Canonical interpretation of the venue wall datetimes stored by bookings. */
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

    /** @return array{start_utc: string, end_utc: string} */
    public static function utcRange(string $start, string $end, string $timezone): array
    {
        $venue = new \DateTimeZone($timezone);
        $utc = new \DateTimeZone('UTC');

        // Parse both complete stored values afresh. A transient object's offset
        // can differ from PHP's interpretation after a naive DATETIME round trip.
        return [
            'start_utc' => (new \DateTimeImmutable($start, $venue))->setTimezone($utc)->format('Y-m-d\TH:i:s\Z'),
            'end_utc' => (new \DateTimeImmutable($end, $venue))->setTimezone($utc)->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    /** PHP normalizes nonexistent spring times; these cannot be offered as slots. */
    public static function wallTimeExists(string $date, string $time, \DateTimeZone $timezone): bool
    {
        $wall = $date . ' ' . substr($time, 0, 5);
        return (new \DateTimeImmutable($wall, $timezone))->format('Y-m-d H:i') === $wall;
    }
}
