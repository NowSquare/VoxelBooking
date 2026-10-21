<?php

declare(strict_types=1);

namespace App\Application\Availability;

use App\Agent\OperationDefinition;
use App\Application\Bookings\BookingOperations;

final class AvailabilityOperations
{
    public static function definitions(): array
    {
        $o = BookingOperations::object(...);
        $string = ['type' => 'string'];
        $nullable = ['type' => ['string', 'null']];
        $id = ['type' => 'string', 'maxLength' => 26];
        $base = ['business_id' => $id];
        $patterns = ['timeslot', 'resource', 'capacity', 'event'];

        // Public business profile: everything a booking page or agent needs before choosing an offering.
        $business = [];
        foreach ([
            'business_id', 'name', 'slug', 'booking_pattern', 'timezone', 'currency', 'locale', 'consent_text', 'booking_url'
        ] as $key) $business[$key] = $string;
        foreach (['description', 'confirmation_message', 'privacy_policy_url', 'cancellation_policy'] as $key) $business[$key] = $nullable;
        foreach ([
            'require_phone', 'requires_consent', 'allow_cancellation', 'allow_rescheduling', 'requires_approval'
        ] as $key) $business[$key] = ['type' => 'boolean'];
        foreach ([
            'cancellation_hours_before', 'rescheduling_hours_before', 'min_advance_hours', 'max_advance_days'
        ] as $key) $business[$key] = ['type' => 'integer'];
        $business['custom_fields'] = [
            'type' => 'array',
            'items' => [
                'type' => 'object',
                'properties' => [
                    'name' => $string,
                    'key' => $string,
                    'label' => $string,
                    'type' => $string,
                    'required' => ['type' => 'boolean'],
                    'placeholder' => $string,
                    'options' => ['type' => 'array'],
                    'min' => ['type' => ['number', 'string']],
                    'max' => ['type' => ['number', 'string']],
                    'step' => ['type' => ['number', 'string']]
                ]
            ]
        ];

        // One catalog item shape covers services, staff, resources, capacity slots and events.
        $item = [];
        foreach (['id', 'name', 'start_time', 'end_time', 'start_datetime', 'end_datetime'] as $key) $item[$key] = $string;
        foreach ([
            'description', 'title', 'price', 'price_per_night', 'price_label', 'category', 'preparation_text',
            'cover_image_path', 'color', 'avatar_path', 'label', 'location', 'rrule'
        ] as $key) $item[$key] = $nullable;
        foreach ([
            'sort_order', 'capacity', 'duration_minutes', 'min_stay_nights', 'max_stay_nights', 'day_of_week', 'max_capacity',
            'min_party_size', 'max_party_size', 'max_participants', 'min_spot_count', 'waitlist_max'
        ] as $key) $item[$key] = ['type' => 'integer'];
        $item['max_spot_count'] = ['type' => ['integer', 'null']];
        foreach (['is_virtual', 'requires_staff', 'allow_waitlist', 'is_recurring'] as $key) $item[$key] = ['type' => 'boolean'];
        foreach (['amenities', 'check_in_days', 'check_out_days', 'exception_dates'] as $key) $item[$key] = ['type' => ['array', 'null']];
        $catalog = $o(
            [
                'kind' => $string,
                'items' => ['type' => 'array', 'items' => $o($item, ['id'])],
                'timezone' => $string,
                'currency' => $string,
                'limit' => ['type' => 'integer'],
                'truncated' => ['type' => 'boolean'],
                'next_cursor' => ['type' => ['string', 'null']]
            ],
            ['kind', 'items', 'timezone', 'currency', 'limit', 'truncated', 'next_cursor']
        );

        $choice = [];
        foreach (['id', 'name', 'date', 'time', 'end_time', 'start_utc', 'end_utc'] as $key) $choice[$key] = $string;
        foreach (['staff_id', 'label', 'price'] as $key) $choice[$key] = $nullable;
        foreach (['remaining', 'max', 'min_party_size', 'max_party_size'] as $key) $choice[$key] = ['type' => 'integer'];
        $choice['waitlisted'] = ['type' => 'boolean'];

        // Only a resource search with check-in and check-out dates returns a quote; otherwise it is null.
        $quote = [
            'type' => ['object', 'null'],
            'additionalProperties' => false,
            'properties' => [
                'available' => ['type' => 'boolean'],
                'error' => $nullable,
                'nights' => ['type' => ['integer', 'null']],
                'total' => $nullable,
                'check_in' => $string,
                'check_out' => $string,
                'resource' => [
                    'type' => ['object', 'null'],
                    'additionalProperties' => false,
                    'properties' => ['id' => $string, 'name' => $string, 'capacity' => ['type' => 'integer']],
                    'required' => ['id', 'name', 'capacity']
                ],
                'pricing' => ['type' => 'array', 'items' => $o(['date' => $string, 'price' => $string, 'label' => $nullable], ['date', 'price', 'label'])]
            ],
            'required' => ['available', 'error', 'nights', 'total', 'check_in', 'check_out']
        ];
        $search = $o(
            [
                'business_id' => $string,
                'booking_pattern' => $string,
                'timezone' => $string,
                'currency' => $string,
                'choices' => ['type' => 'array', 'items' => $o($choice, ['date', 'time', 'end_time', 'start_utc', 'end_utc'])],
                'dates' => ['type' => 'array', 'items' => $string],
                'quote' => $quote,
                'next_cursor' => $nullable,
                'date_rules' => [
                    'type' => ['object', 'null'],
                    'additionalProperties' => false,
                    'properties' => [
                        'check_in_days' => ['type' => ['array', 'null'], 'items' => ['type' => 'integer']],
                        'check_out_days' => ['type' => ['array', 'null'], 'items' => ['type' => 'integer']]
                    ],
                    'required' => ['check_in_days', 'check_out_days']
                ]
            ],
            ['business_id', 'booking_pattern', 'timezone', 'currency', 'choices', 'dates', 'quote', 'next_cursor']
        );

        // One input schema serves every pattern; AvailabilityService reads only the keys its pattern needs.
        $input = $base + [
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
            'cursor' => ['type' => 'string', 'maxLength' => 4096],
            'service_id' => $id,
            'staff_id' => $id,
            'resource_id' => $id,
            'event_id' => $id,
            'reschedule_booking_id' => $id,
            'date' => ['type' => 'string', 'format' => 'date'],
            'month' => ['type' => 'string', 'pattern' => '^20[0-9]{2}-(0[1-9]|1[012])$'],
            'check_in' => ['type' => 'string', 'format' => 'date'],
            'check_out' => ['type' => 'string', 'format' => 'date']
        ];
        foreach (['party_size', 'guest_count', 'spot_count'] as $key) $input[$key] = ['type' => 'integer', 'minimum' => 1, 'maximum' => 10000];

        return [
            new OperationDefinition(
                'public_business_get',
                __('agent-booking.titles.public_business_get'),
                __('agent-booking.descriptions.public_business_get'),
                $o($base),
                $o($business, array_keys($business)),
                ['public:read'],
                'public',
                true,
                false,
                'none',
                $patterns,
                'book-an-appointment',
                ['C29', 'C32'],
                ['A07', 'A27', 'A41'],
                'GET',
                '/public/{slug}/business',
                AvailabilityService::business(...)
            ),
            new OperationDefinition(
                'public_catalog_list',
                __('agent-booking.titles.public_catalog_list'),
                __('agent-booking.descriptions.public_catalog_list'),
                $o($base + [
                    'kind' => ['type' => 'string', 'enum' => ['services', 'staff', 'resources', 'capacity_slots', 'events']],
                    'service_id' => $id,
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
                    'cursor' => ['type' => 'string', 'maxLength' => 4096]
                ]),
                $catalog,
                ['public:read'],
                'public',
                true,
                false,
                'none',
                $patterns,
                'book-an-appointment',
                ['C29', 'C32'],
                ['A02', 'A28', 'A41'],
                'GET',
                '/public/{slug}/catalog',
                AvailabilityService::catalog(...)
            ),
            new OperationDefinition(
                'availability_search',
                __('agent-booking.titles.availability_search'),
                __('agent-booking.descriptions.availability_search'),
                $o($input),
                $search,
                ['public:read'],
                'public',
                true,
                false,
                'none',
                $patterns,
                'availability-and-timezones',
                ['C16', 'C18', 'C30'],
                ['A21', 'A22', 'A23', 'A24', 'A25', 'A26', 'A41'],
                'GET',
                '/public/{slug}/availability',
                AvailabilityService::search(...)
            ),
            new OperationDefinition(
                'customer_availability_search',
                __('agent-booking.titles.customer_availability_search'),
                __('agent-booking.descriptions.customer_availability_search'),
                $o($input),
                $search,
                ['availability:read'],
                'customer_booking',
                true,
                false,
                'none',
                $patterns,
                'manage-your-booking',
                ['C08', 'C30'],
                ['A08', 'A17', 'A21', 'A41'],
                'GET',
                '/businesses/{business_id}/customer/booking/availability',
                AvailabilityService::search(...)
            ),
            new OperationDefinition(
                'staff_availability_search',
                __('agent-booking.titles.staff_availability_search'),
                __('agent-booking.descriptions.staff_availability_search'),
                $o($input),
                $search,
                ['bookings:read'],
                'staff',
                true,
                false,
                'none',
                $patterns,
                'availability-and-timezones',
                ['C06', 'C08', 'C30'],
                ['A02', 'A21', 'A41'],
                'GET',
                '/businesses/{business_id}/availability',
                AvailabilityService::search(...)
            ),
        ];
    }
}
