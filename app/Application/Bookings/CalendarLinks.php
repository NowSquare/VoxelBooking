<?php

declare(strict_types=1);

namespace App\Application\Bookings;

/**
 * Add-to-calendar actions for a confirmed booking: a Google Calendar template
 * link and an iCalendar file.
 *
 * The booking page builds the same two actions in the browser (gcalUrl and
 * downloadIcs in resources/js/booking/app.js). This class reproduces that
 * format so an agent hands the customer the event the booking page would
 * have offered: the same title fallback, UTC instants for timed bookings,
 * all-day dates for stays, and the same UID. Pending and waitlisted bookings
 * get no action, because a calendar entry would promise a seat or time the
 * business has not confirmed.
 */
final class CalendarLinks
{
    public const MEDIA_TYPE = 'text/calendar';

    /** The contract every result that carries calendar actions publishes. */
    public static function schema(): array
    {
        return [
            'type' => ['object', 'null'],
            'additionalProperties' => false,
            'properties' => [
                'google_url' => ['type' => 'string', 'format' => 'uri'],
                'ics' => ['type' => 'string'],
                'ics_filename' => ['type' => 'string'],
                'media_type' => ['type' => 'string', 'const' => self::MEDIA_TYPE],
            ],
            'required' => ['google_url', 'ics', 'ics_filename', 'media_type'],
        ];
    }

    /** Actions for a creation receipt (BookingCommands::create), as the booking page receives it. */
    public static function forCreation(array $receipt, array $tenant): ?array
    {
        return self::build([
            'id' => $receipt['id'],
            'status' => $receipt['status'],
            'booking_pattern' => $receipt['booking_pattern'],
            'title' => self::first($receipt['service'] ?? null, $receipt['event_name'] ?? null, $receipt['label'] ?? null, $tenant['name']),
            'resource' => self::first($receipt['resource'] ?? null, $tenant['name']),
            'staff' => $receipt['staff'] ?? null,
            'start_utc' => $receipt['start_utc'] ?? null,
            'end_utc' => $receipt['end_utc'] ?? null,
            'check_in' => $receipt['check_in'] ?? null,
            'check_out' => $receipt['check_out'] ?? null,
            'nights' => $receipt['nights'] ?? null,
        ], $tenant);
    }

    /** Actions for a booking read projection (BookingQueries::project). */
    public static function forBooking(array $booking, array $tenant): ?array
    {
        return self::build([
            'id' => $booking['id'],
            'status' => $booking['status'],
            'booking_pattern' => $booking['booking_pattern'],
            'title' => self::first($booking['service_name'] ?? null, $booking['event_name'] ?? null, $tenant['name']),
            'resource' => self::first($booking['resource_name'] ?? null, $tenant['name']),
            'staff' => $booking['staff_name'] ?? null,
            'start_utc' => $booking['start_utc'] ?? null,
            'end_utc' => $booking['end_utc'] ?? null,
            'check_in' => $booking['check_in'] ?? null,
            'check_out' => $booking['check_out'] ?? null,
            'nights' => $booking['nights'] ?? null,
        ], $tenant);
    }

    /**
     * @param array $event normalized booking: id, status, booking_pattern, title, resource, staff and its period
     * @param array $tenant tenant row with `name`, `slug` and `timezone`
     */
    public static function build(array $event, array $tenant, ?\DateTimeImmutable $now = null): ?array
    {
        if ($event['status'] !== 'confirmed') {
            return null;
        }

        $stay = $event['booking_pattern'] === 'resource';
        if ($stay) {
            if (!self::filled($event['check_in']) || !self::filled($event['check_out'])) {
                return null;
            }
            $start = str_replace('-', '', $event['check_in']);
            $end = str_replace('-', '', $event['check_out']);
            $title = $event['resource'];
            $description = self::filled($event['nights'])
                ? __('agent-booking.calendar.nights', ['nights' => (string) $event['nights']])
                : '';
        } else {
            $start = self::utc($event['start_utc']);
            $end = self::utc($event['end_utc']);
            if ($start === null || $end === null) {
                return null;
            }
            $title = $event['title'];
            $description = self::filled($event['staff'])
                ? __('agent-booking.calendar.with', ['name' => (string) $event['staff']])
                : '';
        }

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//VoxelBooking//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            'UID:' . $event['id'] . '@' . $tenant['slug'] . '.voxelbooking',
            $stay ? 'DTSTART;VALUE=DATE:' . $start : 'DTSTART:' . $start,
            $stay ? 'DTEND;VALUE=DATE:' . $end : 'DTEND:' . $end,
            'SUMMARY:' . self::escape($title),
            $description !== '' ? 'DESCRIPTION:' . self::escape($description) : '',
            'DTSTAMP:' . ($now ?? self::createdAt((string) $event['id']) ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format('Ymd\THis\Z'),
            'END:VEVENT',
            'END:VCALENDAR',
        ];

        return [
            'google_url' => 'https://calendar.google.com/calendar/render?action=TEMPLATE&text=' . self::uriComponent($title)
                . '&dates=' . $start . '/' . $end . '&ctz=' . self::uriComponent($tenant['timezone']),
            'ics' => implode("\r\n", array_values(array_filter($lines, static fn (string $line) => $line !== ''))),
            'ics_filename' => 'booking-' . $event['id'] . '.ics',
            'media_type' => self::MEDIA_TYPE,
        ];
    }

    /** JavaScript's `a || b || c`: the first value that is not empty. */
    /**
     * The creation instant a ULID carries in its first ten characters.
     *
     * Stamping the calendar entry with the booking's own creation time keeps
     * every read of the same booking byte-identical, across retries and
     * transports, instead of changing with the clock.
     */
    private static function createdAt(string $id): ?\DateTimeImmutable
    {
        if (preg_match('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/i', $id) !== 1) {
            return null;
        }
        $milliseconds = 0;
        foreach (str_split(strtoupper(substr($id, 0, 10))) as $character) {
            $milliseconds = $milliseconds * 32 + strpos('0123456789ABCDEFGHJKMNPQRSTVWXYZ', $character);
        }

        return (new \DateTimeImmutable('@' . intdiv($milliseconds, 1000)))->setTimezone(new \DateTimeZone('UTC'));
    }

    private static function first(mixed ...$values): string
    {
        foreach ($values as $value) {
            if (self::filled($value)) {
                return (string) $value;
            }
        }

        return '';
    }

    private static function filled(mixed $value): bool
    {
        return $value !== null && $value !== '' && $value !== 0 && $value !== false;
    }

    /** 2026-10-25T01:30:00Z becomes 20261025T013000Z, as calendarUtc() does in the browser. */
    private static function utc(mixed $instant): ?string
    {
        if (!is_string($instant) || preg_match('/T.*Z$/D', $instant) !== 1) {
            return null;
        }
        try {
            return (new \DateTimeImmutable($instant))->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z');
        } catch (\Exception) {
            return null;
        }
    }

    /** iCalendar TEXT escaping, in the browser's order: backslash first. */
    private static function escape(string $text): string
    {
        return str_replace(['\\', ';', ',', "\n"], ['\\\\', '\\;', '\\,', '\\n'], $text);
    }

    /** encodeURIComponent: rawurlencode also escapes ! * ' ( ), which JavaScript leaves alone. */
    private static function uriComponent(string $value): string
    {
        return strtr(rawurlencode($value), ['%21' => '!', '%2A' => '*', '%27' => "'", '%28' => '(', '%29' => ')']);
    }
}
