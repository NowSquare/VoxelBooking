<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Template output tests for the booking detail page.
 *
 * Renders bookings/show.php with mock data and asserts the correct
 * CSS classes, HTML patterns, and i18n compliance.
 */
final class BookingDetailOutputTest extends TestCase
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
    // Status chips
    // ════════════════════════════════════════════════════════════════

    public function test_detail_renders_status_chip(): void
    {
        $html = $this->renderDetail([
            'booking' => $this->makeBooking(['status' => 'confirmed']),
        ]);

        $this->assertStringContainsString('vb-status vb-status-confirmed', $html);
        $this->assertStringNotContainsString('vb-badge-success', $html);
    }

    // ════════════════════════════════════════════════════════════════
    // Cell stacks
    // ════════════════════════════════════════════════════════════════

    public function test_detail_renders_cell_stacks(): void
    {
        $html = $this->renderDetail([
            'booking' => $this->makeBooking(),
        ]);

        $this->assertStringContainsString('vb-cell-primary', $html);
        $this->assertStringContainsString('vb-cell-secondary', $html);
    }

    // ════════════════════════════════════════════════════════════════
    // Customer link
    // ════════════════════════════════════════════════════════════════

    public function test_detail_renders_customer_link(): void
    {
        $html = $this->renderDetail([
            'booking' => $this->makeBooking([
                'customer_id' => 'test-cust-id',
                'tenant_id'   => 'test-tenant-id',
            ]),
        ]);

        $this->assertStringContainsString('/admin/tenants/test-tenant-id/customers/test-cust-id', $html);
        $this->assertStringContainsString('vb-link', $html);
    }

    // ════════════════════════════════════════════════════════════════
    // Staff row
    // ════════════════════════════════════════════════════════════════

    public function test_detail_renders_staff_row(): void
    {
        $html = $this->renderDetail([
            'booking' => $this->makeBooking(['staff_name' => 'Alice Smith']),
        ]);

        $this->assertStringContainsString('Alice Smith', $html);
        // The rendered label is either the translated value or the raw key;
        // both contain 'Staff member' or 'label_staff', so assert the name is present
        // and the info-row structure wraps it.
        $this->assertMatchesRegularExpression('/vb-info-label[^<]*>.*(?:Staff member|label_staff)/s', $html);
    }

    public function test_detail_hides_staff_row_when_null(): void
    {
        $html = $this->renderDetail([
            'booking' => $this->makeBooking(['staff_name' => null]),
        ]);

        // Neither the translated label nor the staff name should appear
        $this->assertStringNotContainsString('Staff member', $html);
        $this->assertStringNotContainsString('label_staff', $html);
    }

    // ════════════════════════════════════════════════════════════════
    // Timeline
    // ════════════════════════════════════════════════════════════════

    public function test_detail_renders_timeline(): void
    {
        $html = $this->renderDetail([
            'booking'  => $this->makeBooking(),
            'timeline' => [
                [
                    'action'     => 'booking.created',
                    'actor_type' => 'system',
                    'details'    => null,
                    'created_at' => '2026-03-30 10:00:00',
                ],
                [
                    'action'     => 'booking.status_changed',
                    'actor_type' => 'operator',
                    'details'    => json_encode(['old_status' => 'pending', 'new_status' => 'confirmed']),
                    'created_at' => '2026-03-30 11:00:00',
                ],
            ],
        ]);

        $this->assertStringContainsString('vb-timeline', $html);
        $this->assertStringContainsString('vb-timeline-entry', $html);
        $this->assertStringContainsString('is-created', $html);
        $this->assertStringContainsString('is-status', $html);
    }

    public function test_detail_hides_timeline_when_empty(): void
    {
        $html = $this->renderDetail([
            'booking'  => $this->makeBooking(),
            'timeline' => [],
        ]);

        $this->assertStringNotContainsString('vb-timeline', $html);
    }

    // ════════════════════════════════════════════════════════════════
    // Flash message
    // ════════════════════════════════════════════════════════════════

    public function test_detail_renders_flash_message(): void
    {
        $html = $this->renderDetail([
            'booking' => $this->makeBooking(),
            'flash'   => ['type' => 'success', 'message' => 'Status updated'],
        ]);

        $this->assertStringContainsString('vb-alert-success', $html);
        $this->assertStringContainsString('Status updated', $html);
    }

    // ════════════════════════════════════════════════════════════════
    // Helpers
    // ════════════════════════════════════════════════════════════════

    private function makeBooking(array $overrides = []): array
    {
        return array_merge([
            'id'             => 'test-booking-id',
            'customer_id'    => 'test-cust-id',
            'customer_name'  => 'Jane Doe',
            'customer_email' => 'jane@example.com',
            'service_name'   => 'Haircut',
            'staff_name'     => null,
            'start_datetime' => date('Y-m-d 10:00:00', strtotime('+1 day')),
            'end_datetime'   => date('Y-m-d 10:30:00', strtotime('+1 day')),
            'status'         => 'confirmed',
            'tenant_id'      => 'test-tenant-id',
            'tenant_name'    => 'Test Salon',
            'tenant_slug'    => 'test-salon',
            'source'         => 'web',
            'created_at'     => '2026-03-30 09:00:00',
            'notes'          => null,
        ], $overrides);
    }

    private function renderDetail(array $overrides = []): string
    {
        $defaults = [
            'user'           => ['name' => 'Test Operator', 'type' => 'operator'],
            'version'        => '1.0.0-test',
            'csrfToken'      => 'test-csrf-token',
            'pageTitle'      => 'Booking Detail',
            'activePage'     => 'bookings',
            'booking'        => $this->makeBooking(),
            'timeline'       => [],
            'flash'          => null,
            'backUrl'        => '/admin/bookings',
        ];

        return $this->renderTemplate(
            $this->templateDir . '/admin/bookings/show.php',
            array_merge($defaults, $overrides)
        );
    }

    /**
     * Render a template and return its $content variable.
     */
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

        while (ob_get_level() > $levelBefore) {
            ob_end_clean();
        }

        return $content ?? '';
    }
}
