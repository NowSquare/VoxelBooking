<?php

declare(strict_types=1);

namespace App\Controllers\Booking;

use App\Agent\OperationException;
use App\Engine\Database;

/**
 * Explain a booking operation problem to the customer on the booking page.
 *
 * The shared operation layer reports stable problem codes for agents. The
 * booking page needs the same outcome in the customer's words: which time,
 * room, table or event is unavailable and why. This class keeps that
 * translation in one place so the public create, cancel and reschedule
 * endpoints cannot drift apart.
 *
 * Rules the booking page depends on:
 * - `errors` is present only when at least one field needs correcting. The
 *   page sends the customer back to the form whenever it sees field errors.
 * - The public `error` codes match what the booking page has always received,
 *   so the page can offer refreshed times after `slot_unavailable`.
 */
final class BookingProblemResponse
{
    /**
     * Problem codes from the inventory recheck under lock carry no pattern
     * detail, so each pattern names its own conflict.
     */
    private const INVENTORY_CONFLICT = [
        'timeslot' => 'slot_unavailable',
        'resource' => 'resource_unavailable',
        'capacity' => 'capacity_exceeded',
        'event' => 'event_full',
    ];

    /** Resource calculator reasons that have their own message on the booking page. */
    private const RESOURCE_REASONS = [
        'resource_not_found', 'invalid_date_range', 'min_stay_violation', 'max_stay_violation',
        'capacity_exceeded', 'too_soon', 'too_far', 'date_blocked', 'already_booked',
        'invalid_check_in_day', 'invalid_check_out_day',
    ];

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    public static function forCreate(OperationException $problem, array $tenant, array $input): array
    {
        if ($problem->fieldErrors !== []) {
            return self::fieldCorrections($problem);
        }

        $pattern = (string) $tenant['booking_pattern'];
        $code = $problem->problemCode === 'inventory_conflict'
            ? (self::INVENTORY_CONFLICT[$pattern] ?? 'slot_unavailable')
            : $problem->problemCode;

        if ($pattern === 'resource' && in_array($code, self::RESOURCE_REASONS, true)) {
            return self::conflict($problem->status, $code, __('booking.resource.error_' . $code));
        }

        $message = match ($code) {
            'slot_unavailable', 'staff_unavailable' => __('booking.api.slot_unavailable'),
            'max_bookings_exceeded' => __('booking.api.max_bookings_exceeded'),
            'resource_unavailable' => __('booking.api.resource_unavailable'),
            'capacity_exceeded', 'party_too_large' => __('booking.api.capacity_exceeded'),
            'party_too_small' => __('booking.api.party_too_small'),
            'event_full' => __('booking.api.event_full'),
            'waitlist_full' => __('booking.api.waitlist_full'),
            'instance_cancelled' => __('booking.api.event_cancelled'),
            'spot_count_too_few', 'spot_count_too_many' => self::spotLimitMessage($code, $tenant, $input),
            'too_soon', 'outside_booking_window' => __('booking.api.too_soon'),
            'too_far' => __('booking.api.too_far'),
            'date_blocked', 'slot_not_on_day', 'invalid_date' => __('booking.api.date_unavailable'),
            'invalid_party_size' => __('booking.api.party_size_invalid'),
            'not_found', 'slot_not_found', 'event_not_found', 'staff_invalid' => __('booking.api.selection_unavailable'),
            default => __('booking.api.booking_failed'),
        };
        // The timeslot page offers refreshed times for a staff conflict too.
        $publicCode = $code === 'staff_unavailable' ? 'slot_unavailable' : $code;

        return self::conflict($problem->status, $publicCode, $message);
    }

    /**
     * Cancel and reschedule keep the codes the manage page has always used.
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    public static function forManage(OperationException $problem, string $action): array
    {
        if ($problem->fieldErrors !== []) {
            return self::fieldCorrections($problem);
        }

        $code = $problem->problemCode;
        [$publicCode, $message] = match (true) {
            $code === 'not_found' => ['booking_not_found', __('booking.manage.not_found')],
            in_array($code, ['not_confirmed', 'invalid_transition'], true)
                => ['not_confirmed', __('booking.manage.already_cancelled')],
            $code === 'too_late' => ['time_gate', __($action === 'cancel' ? 'booking.manage.time_gate_cancel' : 'booking.manage.time_gate_reschedule')],
            in_array($code, ['cancellation_disabled', 'cancellation_unavailable'], true)
                => ['cancellation_disabled', __('booking.manage.cancellation_disabled')],
            in_array($code, ['rescheduling_disabled', 'rescheduling_unavailable', 'pattern_not_supported'], true)
                => ['rescheduling_disabled', __('booking.manage.rescheduling_disabled')],
            $code === 'same_slot' => ['same_slot', __('booking.manage.same_slot')],
            in_array($code, ['min_stay', 'max_stay'], true)
                => [$code, __('booking.resource.error_' . $code . '_violation')],
            in_array($code, ['invalid_check_in_day', 'invalid_check_out_day', 'already_booked'], true)
                => [$code, __('booking.resource.error_' . $code)],
            in_array($code, ['party_too_small'], true) => [$code, __('booking.api.party_too_small')],
            in_array($code, ['party_too_large', 'too_many_guests'], true) => [$code, __('booking.api.capacity_exceeded')],
            in_array($code, ['slot_unavailable', 'capacity_exceeded', 'event_full', 'date_blocked', 'outside_booking_window', 'resource_unavailable', 'inventory_conflict'], true)
                => [$code === 'inventory_conflict' ? 'slot_unavailable' : $code, __('booking.api.slot_unavailable')],
            $code === 'too_soon' => [$code, __('booking.api.too_soon')],
            in_array($code, ['invalid_date', 'invalid_slot', 'invalid_event'], true) => [$code, __('booking.api.date_unavailable')],
            $code === 'invalid_time' => [$code, __('booking.api.start_time_required')],
            $code === 'verification_required' => [$code, __('booking.access.expired')],
            default => [$action === 'cancel' ? 'cancel_failed' : 'reschedule_failed', __('booking.errors.generic')],
        };

        return self::conflict($problem->status, $publicCode, $message);
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    private static function fieldCorrections(OperationException $problem): array
    {
        $errors = [];
        foreach ($problem->fieldErrors as $field => $reason) {
            $errors[$field] = match (true) {
                $field === 'consent' => __('booking.errors.required_consent'),
                $reason === 'required' => __('booking.errors.required_field'),
                default => __('booking.errors.invalid_field'),
            };
        }

        return [
            'status' => $problem->status,
            'body' => [
                'error' => $problem->problemCode,
                'message' => __('booking.errors.correct_fields'),
                'errors' => $errors,
            ],
        ];
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    private static function conflict(int $status, string $code, string $message): array
    {
        return ['status' => $status, 'body' => ['error' => $code, 'message' => $message]];
    }

    /**
     * Spot limits name the event's own numbers, read from the event the
     * customer chose within this business.
     */
    private static function spotLimitMessage(string $code, array $tenant, array $input): string
    {
        $event = Database::query(
            'SELECT `min_spot_count`, `max_spot_count` FROM `events` WHERE `id` = ? AND `tenant_id` = ?',
            [(string) ($input['event_id'] ?? ''), $tenant['id']]
        )[0] ?? [];

        return $code === 'spot_count_too_few'
            ? __('booking.api.spot_count_too_few', ['min' => (string) max(1, (int) ($event['min_spot_count'] ?? 1))])
            : __('booking.api.spot_count_too_many', ['max' => (string) (int) ($event['max_spot_count'] ?? 0)]);
    }
}
