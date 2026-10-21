<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers;

use App\Agent\OperationException;
use App\Controllers\Booking\BookingProblemResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every operation problem reaches the booking page as a specific message.
 *
 * The HTTP flow test covers conflicts a fixture can reproduce. These cases
 * cover the rest, including the inventory recheck under lock, which only
 * loses a race in production.
 */
final class BookingProblemResponseTest extends TestCase
{
    /** @var array<string, mixed> */
    private static array $booking = [];

    protected function setUp(): void
    {
        $basePath = dirname(__DIR__, 3);
        \App\Engine\Locale::init($basePath);
        if (!function_exists('__')) {
            require_once $basePath . '/app/helpers.php';
        }
        self::$booking = require $basePath . '/lang/en/booking.php';
    }

    /** @return iterable<string, array{string, string, string, string}> */
    public static function createConflicts(): iterable
    {
        yield 'timeslot lost under lock' => ['timeslot', 'inventory_conflict', 'slot_unavailable', 'api.slot_unavailable'];
        yield 'resource lost under lock' => ['resource', 'inventory_conflict', 'resource_unavailable', 'api.resource_unavailable'];
        yield 'capacity lost under lock' => ['capacity', 'inventory_conflict', 'capacity_exceeded', 'api.capacity_exceeded'];
        yield 'event lost under lock' => ['event', 'inventory_conflict', 'event_full', 'api.event_full'];
        yield 'staff became unavailable' => ['timeslot', 'staff_unavailable', 'slot_unavailable', 'api.slot_unavailable'];
        yield 'timeslot too soon' => ['timeslot', 'too_soon', 'too_soon', 'api.too_soon'];
        yield 'timeslot too far' => ['timeslot', 'too_far', 'too_far', 'api.too_far'];
        yield 'capacity outside window' => ['capacity', 'outside_booking_window', 'outside_booking_window', 'api.too_soon'];
        yield 'capacity date blocked' => ['capacity', 'date_blocked', 'date_blocked', 'api.date_unavailable'];
        yield 'capacity party too large' => ['capacity', 'party_too_large', 'party_too_large', 'api.capacity_exceeded'];
        yield 'event too soon' => ['event', 'too_soon', 'too_soon', 'api.too_soon'];
        yield 'event occurrence cancelled' => ['event', 'instance_cancelled', 'instance_cancelled', 'api.event_cancelled'];
        yield 'resource too soon' => ['resource', 'too_soon', 'too_soon', 'resource.error_too_soon'];
        yield 'resource blocked' => ['resource', 'date_blocked', 'date_blocked', 'resource.error_date_blocked'];
        yield 'resource check-in day' => ['resource', 'invalid_check_in_day', 'invalid_check_in_day', 'resource.error_invalid_check_in_day'];
        yield 'retired selection' => ['timeslot', 'not_found', 'not_found', 'api.selection_unavailable'];
    }

    #[DataProvider('createConflicts')]
    public function testCreateConflictsCarryTheirOwnMessageAndNoFieldErrors(string $pattern, string $code, string $publicCode, string $key): void
    {
        $response = BookingProblemResponse::forCreate(new OperationException($code, 409), ['id' => 'business', 'booking_pattern' => $pattern], []);

        $this->assertSame(409, $response['status']);
        $this->assertSame($publicCode, $response['body']['error']);
        $this->assertSame($this->message($key), $response['body']['message']);
        $this->assertArrayNotHasKey('errors', $response['body']);
    }

    public function testFieldProblemsListOnlyTheFieldsToCorrect(): void
    {
        $problem = new OperationException('consent_required', 422, ['consent' => 'required', 'custom_fields.allergies' => 'required', 'customer.email' => 'invalid']);

        $response = BookingProblemResponse::forCreate($problem, ['id' => 'business', 'booking_pattern' => 'timeslot'], []);

        $this->assertSame(422, $response['status']);
        $this->assertSame($this->message('errors.correct_fields'), $response['body']['message']);
        $this->assertSame([
            'consent' => $this->message('errors.required_consent'),
            'custom_fields.allergies' => $this->message('errors.required_field'),
            'customer.email' => $this->message('errors.invalid_field'),
        ], $response['body']['errors']);
    }

    /** @return iterable<string, array{string, string, string, string}> */
    public static function manageConflicts(): iterable
    {
        yield 'cancel after deadline' => ['cancel', 'too_late', 'time_gate', 'manage.time_gate_cancel'];
        yield 'reschedule after deadline' => ['reschedule', 'too_late', 'time_gate', 'manage.time_gate_reschedule'];
        yield 'cancel disabled' => ['cancel', 'cancellation_disabled', 'cancellation_disabled', 'manage.cancellation_disabled'];
        yield 'already cancelled' => ['cancel', 'not_confirmed', 'not_confirmed', 'manage.already_cancelled'];
        yield 'repeated cancel' => ['cancel', 'invalid_transition', 'not_confirmed', 'manage.already_cancelled'];
        yield 'reschedule disabled' => ['reschedule', 'rescheduling_disabled', 'rescheduling_disabled', 'manage.rescheduling_disabled'];
        yield 'pattern without reschedule' => ['reschedule', 'pattern_not_supported', 'rescheduling_disabled', 'manage.rescheduling_disabled'];
        yield 'same time' => ['reschedule', 'same_slot', 'same_slot', 'manage.same_slot'];
        yield 'target taken' => ['reschedule', 'slot_unavailable', 'slot_unavailable', 'api.slot_unavailable'];
        yield 'target lost under lock' => ['reschedule', 'inventory_conflict', 'slot_unavailable', 'api.slot_unavailable'];
        yield 'stay too short' => ['reschedule', 'min_stay', 'min_stay', 'resource.error_min_stay_violation'];
        yield 'stay too long' => ['reschedule', 'max_stay', 'max_stay', 'resource.error_max_stay_violation'];
        yield 'room booked' => ['reschedule', 'already_booked', 'already_booked', 'resource.error_already_booked'];
        yield 'missing booking' => ['cancel', 'not_found', 'booking_not_found', 'manage.not_found'];
        yield 'unexpected cancel failure' => ['cancel', 'temporarily_unavailable', 'cancel_failed', 'errors.generic'];
        yield 'unexpected reschedule failure' => ['reschedule', 'reschedule_failed', 'reschedule_failed', 'errors.generic'];
    }

    #[DataProvider('manageConflicts')]
    public function testManageConflictsKeepTheirPublicCodesAndMessages(string $action, string $code, string $publicCode, string $key): void
    {
        $response = BookingProblemResponse::forManage(new OperationException($code, 409), $action);

        $this->assertSame($publicCode, $response['body']['error']);
        $this->assertSame($this->message($key), $response['body']['message']);
        $this->assertArrayNotHasKey('errors', $response['body']);
    }

    private function message(string $key): string
    {
        [$group, $name] = explode('.', $key, 2);
        $message = self::$booking[$group][$name] ?? null;
        $this->assertIsString($message, "Missing booking.{$key}");

        return $message;
    }
}
