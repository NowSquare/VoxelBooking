<?php

declare(strict_types=1);

namespace App\Application\Bookings;

use App\Agent\OperationDefinition;

/**
 * Calendar views as agent operations.
 *
 * calendar_get pages through the booking records of a date range. calendar_summary
 * answers the question the admin calendar answers at a glance: which days of this
 * day, week or month are busy, closed or open.
 */
final class CalendarOperations
{
    /** Resolving a staff caller can fail before any booking is read. */
    private const STAFF_ACTOR_ERRORS = ['unauthorized', 'identity_verification_required', 'forbidden', 'not_found'];

    public static function definitions(): array
    {
        $patterns = ['timeslot', 'resource', 'capacity', 'event'];
        $id = ['type' => 'string', 'minLength' => 1, 'maxLength' => 26];
        $date = ['type' => 'string', 'format' => 'date'];

        return [new OperationDefinition(
            'calendar_summary',
            __('agent-booking.titles.calendar_summary'),
            __('agent-booking.descriptions.calendar_summary'),
            BookingOperations::object(
                [
                    'business_id' => $id,
                    'view' => ['type' => 'string', 'enum' => BookingCalendar::VIEWS],
                    // Omitted, the anchor is today in the business's timezone, as on the calendar page.
                    'date' => $date
                ],
                ['view']
            ),
            BookingOperations::object(
                [
                    'view' => ['type' => 'string', 'enum' => BookingCalendar::VIEWS],
                    'date' => $date,
                    'from' => $date,
                    'to' => $date,
                    'week_start' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 6],
                    'today' => $date,
                    'timezone' => ['type' => 'string'],
                    'total' => ['type' => 'integer', 'minimum' => 0],
                    'days' => [
                        'type' => 'array',
                        'items' => BookingOperations::object(
                            [
                                'date' => $date,
                                'in_month' => ['type' => 'boolean'],
                                'bookings' => ['type' => 'integer', 'minimum' => 0],
                                'blocked' => ['type' => 'boolean'],
                                'open' => ['type' => 'boolean']
                            ],
                            ['date', 'in_month', 'bookings', 'blocked', 'open']
                        )
                    ]
                ],
                ['view', 'date', 'from', 'to', 'week_start', 'today', 'timezone', 'total', 'days']
            ),
            ['bookings:read'],
            'staff',
            true,
            false,
            'none',
            $patterns,
            'booking-tools',
            ['C10'],
            ['A01', 'A02', 'A40', 'A41'],
            'GET',
            '/businesses/{business_id}/calendar/summary',
            static fn ($a, $i) => BookingCalendar::summary($a, $i),
            errorCodes: [...self::STAFF_ACTOR_ERRORS, 'invalid_date', 'validation_failed']
        )];
    }
}
