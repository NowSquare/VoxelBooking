<?php

declare(strict_types=1);

namespace App\Application\Privacy;

use App\Agent\OperationDefinition;
use App\Application\Bookings\BookingOperations;

final class PrivacyOperations
{
    public static function definitions(): array
    {
        $o = BookingOperations::object(...);
        $s = ['type' => 'string'];
        $n = ['type' => ['string', 'null']];
        $input = $o(['business_id' => ['type' => 'string', 'maxLength' => 26]]);
        $summary = $o(
            [
                'business_id' => $s,
                'customer' => $o(
                    ['name' => $s, 'email' => $s, 'phone' => $n, 'created_at' => $s],
                    ['name', 'email', 'phone', 'created_at']
                ),
                'booking_count' => ['type' => 'integer'],
                'consent_record_count' => ['type' => 'integer'],
                'deletion_status' => ['type' => 'string', 'enum' => ['not_requested', 'pending_review']],
                'deletion_requested_at' => $n
            ],
            [
                'business_id',
                'customer',
                'booking_count',
                'consent_record_count',
                'deletion_status',
                'deletion_requested_at'
            ]
        );
        $booking = $o(
            [
                'booking_pattern' => $s,
                'start_datetime' => $s,
                'end_datetime' => $s,
                'party_size' => ['type' => ['integer', 'string']],
                'status' => $s,
                'notes' => $n,
                'custom_field_data' => ['type' => ['object', 'array', 'null']],
                'customer_timezone' => $n,
                'source' => $s,
                'created_at' => $s,
                'contact' => $o(['name' => $n, 'email' => $n, 'phone' => $n], ['name', 'email', 'phone'])
            ],
            [
                'booking_pattern',
                'start_datetime',
                'end_datetime',
                'party_size',
                'status',
                'notes',
                'custom_field_data',
                'customer_timezone',
                'source',
                'created_at'
            ]
        );
        $export = $o(
            [
                'export_version' => $s,
                'exported_at' => $s,
                'customer' => $o(
                    ['name' => $s, 'email' => $s, 'phone' => $n, 'notes' => $n, 'created_at' => $s, 'updated_at' => $s],
                    ['name', 'email', 'phone', 'notes', 'created_at', 'updated_at']
                ),
                'bookings' => ['type' => 'array', 'items' => $booking],
                'consent_records' => [
                    'type' => 'array',
                    'items' => $o(
                        ['booking_start' => $s, 'consent_given_at' => $s, 'consent_text_shown' => $n, 'source' => $s],
                        ['booking_start', 'consent_given_at', 'consent_text_shown', 'source']
                    )
                ],
                'email_log' => [
                    'type' => 'array',
                    'items' => $o(
                        ['type' => $s, 'subject' => $s, 'status' => $s, 'sent_at' => $n],
                        ['type', 'subject', 'status', 'sent_at']
                    )
                ]
            ],
            ['export_version', 'exported_at', 'customer', 'bookings', 'consent_records', 'email_log']
        );
        $record = $o(
            [
                'id' => $s,
                'booking_pattern' => $s,
                'start_datetime' => $s,
                'end_datetime' => $s,
                'start_utc' => $s,
                'end_utc' => $s,
                'party_size' => ['type' => 'integer'],
                'status' => $s,
                'source' => $s,
                'consent_given_at' => $n,
                'consent_text_shown' => $n
            ],
            [
                'id',
                'booking_pattern',
                'start_datetime',
                'end_datetime',
                'start_utc',
                'end_utc',
                'party_size',
                'status',
                'source',
                'consent_given_at',
                'consent_text_shown'
            ]
        );
        $records = $o(
            ['bookings' => ['type' => 'array', 'items' => $record], 'next_cursor' => $n, 'timezone' => $s],
            ['bookings', 'next_cursor', 'timezone']
        );
        $patterns = ['timeslot', 'resource', 'capacity', 'event'];
        return [
            new OperationDefinition(
                'customer_privacy_records',
                __('agent-booking.titles.customer_privacy_records'),
                __('agent-booking.descriptions.customer_privacy_records'),
                $o([
                    'business_id' => ['type' => 'string', 'maxLength' => 26],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
                    'cursor' => ['type' => 'string', 'maxLength' => 4096]
                ]),
                $records,
                ['privacy:read'],
                'customer_privacy',
                true,
                false,
                'none',
                $patterns,
                'customers-and-privacy',
                ['C33'],
                ['A01', 'A07', 'A08', 'A35', 'A40', 'A41'],
                'GET',
                '/businesses/{business_id}/customer/privacy/records',
                PrivacyService::records(...)
            ),
            new OperationDefinition(
                'customer_privacy_get',
                __('agent-booking.titles.customer_privacy_get'),
                __('agent-booking.descriptions.customer_privacy_get'),
                $input,
                $summary,
                ['privacy:read'],
                'customer_privacy',
                true,
                false,
                'none',
                $patterns,
                'customers-and-privacy',
                ['C33'],
                ['A01', 'A07', 'A08', 'A35', 'A41'],
                'GET',
                '/businesses/{business_id}/customer/privacy',
                PrivacyService::summary(...)
            ),
            new OperationDefinition(
                'customer_privacy_export',
                __('agent-booking.titles.customer_privacy_export'),
                __('agent-booking.descriptions.customer_privacy_export'),
                $input,
                $export,
                ['privacy:export'],
                'customer_privacy',
                false,
                false,
                'none',
                $patterns,
                'customers-and-privacy',
                ['C33'],
                ['A08', 'A20', 'A35', 'A41'],
                'POST',
                '/businesses/{business_id}/customer/privacy/export',
                PrivacyService::export(...),
                sideEffects: ['customer.privacy_notification']
            ),
            new OperationDefinition(
                'customer_deletion_request',
                __('agent-booking.titles.customer_deletion_request'),
                __('agent-booking.descriptions.customer_deletion_request'),
                $input,
                $summary,
                ['privacy:request'],
                'customer_privacy',
                false,
                false,
                'trusted',
                $patterns,
                'customers-and-privacy',
                ['C33'],
                ['A08', 'A13', 'A35', 'A41'],
                'POST',
                '/businesses/{business_id}/customer/privacy/deletion-request',
                PrivacyService::requestDeletion(...),
                sideEffects: ['customer.privacy_notification'],
                previewHandler: PrivacyService::preview(...)
            ),
        ];
    }
}
