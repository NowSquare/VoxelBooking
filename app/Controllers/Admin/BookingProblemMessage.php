<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Agent\OperationException;
use App\Controllers\Booking\BookingProblemResponse;

/**
 * Explain a failed booking action to staff on the admin screens.
 *
 * The admin forms show field errors under their inputs and one toast that
 * says what to fix. Conflicts a customer can also meet (a full event, a
 * room's minimum stay) reuse the booking page's wording through
 * BookingProblemResponse. Policy limits point staff to the setting that
 * caused them.
 */
final class BookingProblemMessage
{
    /** Operation field paths that map to a manual booking form input. */
    private const CUSTOMER_INPUTS = [
        'customer.name' => 'customer_name',
        'customer.email' => 'customer_email',
        'customer.phone' => 'customer_phone',
    ];

    /**
     * @param array<string, mixed> $tenant
     * @param array<string, mixed> $input the submitted form, with nested `customer`
     * @return array{toast: string, fields: array<string, string>}
     */
    public static function forCreate(OperationException $problem, array $tenant, array $input): array
    {
        if ($problem->fieldErrors !== []) {
            return ['toast' => __('admin.bookings.error_check_fields'), 'fields' => self::fieldMessages($problem->fieldErrors, $input)];
        }

        $code = $problem->problemCode;
        $pattern = (string) ($tenant['booking_pattern'] ?? 'timeslot');
        $toast = match (true) {
            in_array($code, ['too_soon', 'outside_booking_window'], true) => __('admin.bookings.error_too_soon'),
            $code === 'too_far' => __('admin.bookings.error_too_far'),
            $code === 'staff_invalid' => __('admin.bookings.error_staff_invalid'),
            $code === 'invalid_date' => __('admin.bookings.error_date_required'),
            $code === 'invalid_time' => __('admin.bookings.error_time_required'),
            $pattern === 'timeslot' && in_array($code, ['slot_unavailable', 'staff_unavailable', 'inventory_conflict'], true)
                => __('admin.bookings.flash_slot_taken'),
            $pattern === 'resource' && in_array($code, ['resource_unavailable', 'inventory_conflict'], true)
                => __('admin.bookings.flash_resource_unavailable'),
            $pattern === 'capacity' && in_array($code, ['capacity_exceeded', 'party_too_large', 'inventory_conflict'], true)
                => __('admin.bookings.flash_capacity_exceeded'),
            default => self::sharedMessage($problem, $tenant, $input),
        };

        return ['toast' => $toast, 'fields' => []];
    }

    public static function forReschedule(OperationException $problem): string
    {
        return match ($problem->problemCode) {
            'not_confirmed', 'rescheduling_disabled', 'rescheduling_unavailable', 'too_late', 'invalid_transition'
                => __('admin.bookings.error_reschedule_not_allowed'),
            'same_slot' => __('admin.bookings.error_same_slot'),
            'slot_unavailable', 'already_booked', 'capacity_exceeded', 'event_full', 'inventory_conflict',
            'date_blocked', 'resource_unavailable', 'too_many_guests', 'party_too_large'
                => __('admin.bookings.flash_slot_taken'),
            'too_soon', 'outside_booking_window' => __('admin.bookings.error_too_soon'),
            'too_far' => __('admin.bookings.error_too_far'),
            'invalid_date' => __('admin.bookings.error_date_required'),
            'invalid_time' => __('admin.bookings.error_time_required'),
            default => __('admin.bookings.flash_reschedule_failed'),
        };
    }

    public static function forStatus(OperationException $problem): string
    {
        // Confirming re-occupies inventory; anything else is an illegal transition.
        return $problem->problemCode === 'inventory_conflict'
            ? __('admin.bookings.flash_slot_taken')
            : __('admin.bookings.flash_status_failed');
    }

    /**
     * @param array<string, string> $fieldErrors operation path => reason
     * @return array<string, string> form input name => message
     */
    private static function fieldMessages(array $fieldErrors, array $input): array
    {
        $messages = [];
        foreach ($fieldErrors as $path => $reason) {
            $name = self::CUSTOMER_INPUTS[$path] ?? $path;
            $messages[$name] = match (true) {
                $name === 'customer_name' => __('admin.bookings.error_name_required'),
                $name === 'customer_email' => trim((string) ($input['customer']['email'] ?? '')) === ''
                    ? __('admin.bookings.error_email_required')
                    : __('admin.bookings.error_email_invalid'),
                $name === 'customer_phone' => __('admin.bookings.error_phone_required'),
                $reason === 'required' => __('admin.bookings.error_field_required'),
                default => __('admin.bookings.error_field_invalid'),
            };
        }

        return $messages;
    }

    private static function sharedMessage(OperationException $problem, array $tenant, array $input): string
    {
        $message = BookingProblemResponse::forCreate($problem, $tenant, $input)['body']['message'];

        return $message === __('booking.api.booking_failed') ? __('admin.bookings.flash_create_failed') : $message;
    }
}
