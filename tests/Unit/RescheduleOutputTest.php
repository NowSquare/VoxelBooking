<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Template output tests for the reschedule UI on the booking detail page.
 *
 * Verifies:
 * - Reschedule button/modal shown for confirmed bookings of any pattern
 * - Hidden for pending, cancelled, rescheduled bookings
 * - Rescheduled-to link shown when rescheduled_to_id is set
 * - Rescheduled status in dropdown is read-only (no outbound transitions)
 */
final class RescheduleOutputTest extends TestCase
{
    private string $templateDir;
    private static bool $helpersReady = false;

    public static function setUpBeforeClass(): void
    {
        $root = dirname(__DIR__, 2);
        require_once $root . '/vendor/autoload.php';

        if (file_exists($root . '/.env')) {
            \Tests\Support\TestEnvironment::boot();
        }

        self::$helpersReady = function_exists('__') && function_exists('app_name');
    }

    protected function setUp(): void
    {
        if (!self::$helpersReady) {
            $this->markTestSkipped('Translation/app helpers not available');
        }

        $this->templateDir = dirname(__DIR__, 2) . '/templates';
    }

    // ════════════════════════════════════════════════════════════════
    // Reschedule button visibility
    // ════════════════════════════════════════════════════════════════

    public function test_reschedule_button_shown_for_confirmed_timeslot(): void
    {
        $html = $this->renderDetail([
            'booking' => $this->makeBooking([
                'status'          => 'confirmed',
                'booking_pattern' => 'timeslot',
            ]),
        ]);

        $this->assertStringContainsString('btn-reschedule-open', $html);
        $this->assertStringContainsString('reschedule-modal', $html);
    }

    public function test_reschedule_button_hidden_for_pending_booking(): void
    {
        $html = $this->renderDetail([
            'booking' => $this->makeBooking([
                'status'          => 'pending',
                'booking_pattern' => 'timeslot',
            ]),
        ]);

        $this->assertStringNotContainsString('btn-reschedule-open', $html);
        $this->assertStringNotContainsString('reschedule-modal', $html);
    }

    public function test_reschedule_button_hidden_for_cancelled_booking(): void
    {
        $html = $this->renderDetail([
            'booking' => $this->makeBooking([
                'status'          => 'cancelled',
                'booking_pattern' => 'timeslot',
            ]),
        ]);

        $this->assertStringNotContainsString('btn-reschedule-open', $html);
    }

    public function test_reschedule_button_hidden_for_rescheduled_booking(): void
    {
        $html = $this->renderDetail([
            'booking' => $this->makeBooking([
                'status'           => 'rescheduled',
                'booking_pattern'  => 'timeslot',
                'rescheduled_to_id' => 'new-booking-id',
            ]),
        ]);

        $this->assertStringNotContainsString('btn-reschedule-open', $html);
        $this->assertStringNotContainsString('reschedule-modal', $html);
    }

    public function test_reschedule_button_shown_for_resource_pattern(): void
    {
        $html = $this->renderDetail([
            'booking' => $this->makeBooking([
                'status'          => 'confirmed',
                'booking_pattern' => 'resource',
            ]),
        ]);

        $this->assertStringContainsString('btn-reschedule-open', $html);
    }

    public function test_reschedule_button_shown_for_capacity_pattern(): void
    {
        $html = $this->renderDetail([
            'booking' => $this->makeBooking([
                'status'          => 'confirmed',
                'booking_pattern' => 'capacity',
            ]),
        ]);

        $this->assertStringContainsString('btn-reschedule-open', $html);
    }

    public function test_reschedule_button_shown_for_event_pattern(): void
    {
        $html = $this->renderDetail([
            'booking' => $this->makeBooking([
                'status'          => 'confirmed',
                'booking_pattern' => 'event',
            ]),
        ]);

        $this->assertStringContainsString('btn-reschedule-open', $html);
    }

    // ════════════════════════════════════════════════════════════════
    // Rescheduled-to link
    // ════════════════════════════════════════════════════════════════

    public function test_rescheduled_booking_shows_link_to_new_booking(): void
    {
        $html = $this->renderDetail([
            'booking' => $this->makeBooking([
                'status'            => 'rescheduled',
                'rescheduled_to_id' => 'new-booking-abc',
            ]),
        ]);

        $this->assertStringContainsString('new-booking-abc', $html);
        $this->assertStringContainsString('vb-link', $html);
    }

    public function test_confirmed_booking_hides_rescheduled_link(): void
    {
        $html = $this->renderDetail([
            'booking' => $this->makeBooking([
                'status'            => 'confirmed',
                'rescheduled_to_id' => null,
            ]),
        ]);

        // The rescheduled-to section should not appear
        $this->assertStringNotContainsString('view_new_booking', $html);
    }

    // ════════════════════════════════════════════════════════════════
    // Rescheduled status in dropdown
    // ════════════════════════════════════════════════════════════════

    public function test_rescheduled_status_dropdown_is_readonly(): void
    {
        $html = $this->renderDetail([
            'booking' => $this->makeBooking([
                'status'            => 'rescheduled',
                'rescheduled_to_id' => 'new-booking-id',
            ]),
        ]);

        // Status form is hidden for rescheduled bookings (terminal state)
        $this->assertStringNotContainsString('name="status"', $html, 'Status form should be hidden for rescheduled bookings');

        // Terminal-state CTA: "View Active Booking" links to the replacement booking
        $this->assertStringContainsString('btn-view-active-booking', $html, 'Should show View Active Booking button');
    }

    // ════════════════════════════════════════════════════════════════
    // Modal form structure
    // ════════════════════════════════════════════════════════════════

    public function test_reschedule_modal_contains_date_and_time_inputs(): void
    {
        $html = $this->renderDetail([
            'booking' => $this->makeBooking([
                'status'          => 'confirmed',
                'booking_pattern' => 'timeslot',
            ]),
        ]);

        $this->assertStringContainsString('name="new_date"', $html);
        $this->assertStringContainsString('name="new_time"', $html);
        $this->assertStringContainsString('/reschedule', $html);
    }

    public function test_reschedule_modal_prefills_current_booking_datetime(): void
    {
        $startDate = date('Y-m-d', strtotime('+2 days'));
        $html = $this->renderDetail([
            'booking' => $this->makeBooking([
                'status'          => 'confirmed',
                'booking_pattern' => 'timeslot',
                'start_datetime'  => "{$startDate} 14:30:00",
            ]),
        ]);

        $this->assertStringContainsString("value=\"{$startDate}\"", $html);
        $this->assertStringContainsString('value="14:30"', $html);
    }

    // ════════════════════════════════════════════════════════════════
    // Helpers
    // ════════════════════════════════════════════════════════════════

    private function makeBooking(array $overrides = []): array
    {
        return array_merge([
            'id'                => 'test-booking-id',
            'customer_id'       => 'test-cust-id',
            'customer_name'     => 'Jane Doe',
            'customer_email'    => 'jane@example.com',
            'service_name'      => 'Haircut',
            'staff_name'        => null,
            'start_datetime'    => date('Y-m-d 10:00:00', strtotime('+1 day')),
            'end_datetime'      => date('Y-m-d 10:30:00', strtotime('+1 day')),
            'status'            => 'confirmed',
            'booking_pattern'   => 'timeslot',
            'tenant_id'         => 'test-tenant-id',
            'tenant_name'       => 'Test Salon',
            'tenant_slug'       => 'test-salon',
            'source'            => 'web',
            'created_at'        => '2026-03-30 09:00:00',
            'notes'             => null,
            'rescheduled_to_id' => null,
        ], $overrides);
    }

    private function renderDetail(array $overrides = []): string
    {
        $defaults = [
            'user'       => ['name' => 'Test Operator', 'type' => 'operator'],
            'version'    => '1.0.0-test',
            'csrfToken'  => 'test-csrf-token',
            'pageTitle'  => 'Booking Detail',
            'activePage' => 'bookings',
            'booking'    => $this->makeBooking(),
            'timeline'   => [],
            'rescheduledFrom' => null,
            'flash'      => null,
            'backUrl'    => '/admin/bookings',
        ];

        $vars = array_merge($defaults, $overrides);

        // Derive $canReschedule the same way the template does:
        // confirmed status (all patterns now supported)
        $b = $vars['booking'];
        if (!isset($vars['canReschedule'])) {
            $vars['canReschedule'] = ($b['status'] === 'confirmed');
        }

        return $this->renderTemplate(
            $this->templateDir . '/admin/bookings/show.php',
            $vars
        );
    }

    private function renderTemplate(string $path, array $vars): string
    {
        if (!file_exists($path)) {
            $this->markTestSkipped("Template not found: {$path}");
        }

        $levelBefore = ob_get_level();
        extract($vars);

        ob_start();

        try {
            include $path;
        } catch (\Throwable) {
            // Layout include will fail — $content was already set
        }

        // Capture any remaining buffer content before cleanup
        $captured = '';
        while (ob_get_level() > $levelBefore) {
            $captured = ob_get_clean() . $captured;
        }

        // Combine $content (main body) + $modals (body-level overlays)
        // Fall back to captured buffer if $content is empty (buffer nesting issue)
        $body = ($content ?? '') ?: $captured;
        return $body . ($modals ?? '');
    }
}
