<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Regression tests for BookingApiController::bookingDetail() response structure
 * and the public rescheduleBookingAction() response enrichment.
 *
 * Source-level assertions verify:
 * - The pattern_not_supported gate has been removed from bookingDetail
 * - The detail response includes pattern-specific identifiers
 * - The reschedule response enriches new_booking with pattern-specific fields
 * - The event payload requires both date and event_id
 */
final class BookingDetailResponseTest extends TestCase
{
    private string $controllerSource;

    protected function setUp(): void
    {
        $path = dirname(__DIR__, 2) . '/app/Controllers/Booking/BookingApiController.php';
        $this->controllerSource = (string) file_get_contents($path);
        $this->assertNotEmpty($this->controllerSource, 'Controller source should be readable');
    }

    /**
     * The bookingDetail method must not suppress reschedule for non-timeslot patterns.
     * The old gate was: if ($canReschedule['allowed'] && $pattern !== 'timeslot') { ... = 'pattern_not_supported'; }
     */
    public function test_booking_detail_does_not_suppress_non_timeslot_reschedule(): void
    {
        // Extract just the bookingDetail method body
        preg_match('/function\s+bookingDetail\b.*?\n    \}/s', $this->controllerSource, $m);
        $methodBody = $m[0] ?? '';
        $this->assertNotEmpty($methodBody, 'bookingDetail method should exist');

        $this->assertStringNotContainsString(
            'pattern_not_supported',
            $methodBody,
            'bookingDetail must not suppress reschedule for non-timeslot patterns'
        );
    }

    /**
     * The response must include resource_id and event_id for pattern-aware JS.
     */
    public function test_response_includes_resource_and_event_ids(): void
    {
        $this->assertStringContainsString(
            "'resource_id'",
            $this->controllerSource,
            'Response should include resource_id'
        );
        $this->assertStringContainsString(
            "'event_id'",
            $this->controllerSource,
            'Response should include event_id'
        );
    }

    /**
     * The response must include check_in and check_out for resource pattern.
     */
    public function test_response_includes_check_in_check_out(): void
    {
        $this->assertStringContainsString(
            "'check_in'",
            $this->controllerSource,
            'Response should include check_in'
        );
        $this->assertStringContainsString(
            "'check_out'",
            $this->controllerSource,
            'Response should include check_out'
        );
    }

    /**
     * check_in/check_out should only be set for resource pattern.
     */
    public function test_check_in_only_set_for_resource_pattern(): void
    {
        // The code should only set checkIn/checkOut inside a resource pattern guard
        $this->assertMatchesRegularExpression(
            '/if\s*\(\$pattern\s*===\s*[\'"]resource[\'"]\)\s*\{[^}]*\$checkIn/s',
            $this->controllerSource,
            'check_in should only be populated for resource pattern'
        );
    }

    /**
     * The reschedule response must enrich new_booking with check_in/check_out for resource.
     */
    public function test_reschedule_response_enriches_resource_with_check_in_out(): void
    {
        // Extract rescheduleBookingAction method
        preg_match('/function\s+rescheduleBookingAction\b.*?\n    \}/s', $this->controllerSource, $m);
        $methodBody = $m[0] ?? '';
        $this->assertNotEmpty($methodBody, 'rescheduleBookingAction method should exist');

        // presentRange() publishes check_in, check_out and nights for a stay
        // (PublicBookingUtcContractTest asserts the actual response).
        $this->assertStringContainsString(
            "VenueDateTime::presentRange(\$result['pattern']",
            $methodBody,
            'Reschedule response should publish a resource stay as check-in/check-out dates'
        );
    }

    /**
     * The reschedule response must enrich new_booking with event_name for event pattern.
     */
    public function test_reschedule_response_enriches_event_with_name(): void
    {
        preg_match('/function\s+rescheduleBookingAction\b.*?\n    \}/s', $this->controllerSource, $m);
        $methodBody = $m[0] ?? '';
        $this->assertNotEmpty($methodBody, 'rescheduleBookingAction method should exist');

        $this->assertStringContainsString(
            "'event_name'",
            $methodBody,
            'Reschedule response should include event_name for event pattern'
        );
    }
}
