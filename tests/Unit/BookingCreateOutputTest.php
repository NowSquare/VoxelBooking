<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Output tests for the booking create template.
 *
 * Renders templates/admin/tenants/bookings/create.php and asserts
 * the CSP-safe Alpine.js markup and data-attribute hydration.
 */
final class BookingCreateOutputTest extends TestCase
{
    /**
     * Templates include layout.php which calls Database::query().
     * When the test process can't reach MySQL, skip instead of crashing.
     */
    private function requireDatabaseForTemplates(): void
    {
        try {
            \Tests\Support\TestEnvironment::boot();
            \App\Engine\Database::connect();
            \App\Engine\Database::query('SELECT 1');
        } catch (\Throwable) {
            $this->markTestSkipped('Database not available (required by layout.php template)');
        }
    }

    private function renderBookingCreate(array $vars = []): string
    {
        $basePath = dirname(__DIR__, 2);
        \App\Engine\Locale::init($basePath);

        if (!function_exists('__')) {
            require_once $basePath . '/app/helpers.php';
        }

        $tenant          = $vars['tenant'] ?? ['name' => 'Test', 'slug' => 'test-slug', 'require_phone' => 0];
        $tenantId        = $vars['tenantId'] ?? 'test-tenant-id';
        $services        = $vars['services'] ?? [];
        $staff           = $vars['staff'] ?? [];
        $serviceStaffMap = $vars['serviceStaffMap'] ?? [];
        $csrfToken       = $vars['csrfToken'] ?? 'test-csrf-token';
        $flash           = $vars['flash'] ?? null;
        $old             = $vars['old'] ?? [];

        ob_start();
        include $basePath . '/templates/admin/tenants/bookings/create.php';
        $output = ob_get_clean() ?: '';

        if (isset($content) && $content !== '') {
            return $content;
        }

        return $output;
    }

    public function test_booking_create_uses_csp_safe_alpine(): void
    {
        $this->requireDatabaseForTemplates();
        $html = $this->renderBookingCreate();

        $this->assertStringContainsString(
            'x-data="bookingCreate"',
            $html,
            'Must use CSP-safe Alpine.data() reference (no parens)'
        );

        $this->assertStringNotContainsString(
            'function bookingCreate()',
            $html,
            'Must not contain inline script function definition'
        );

        $this->assertStringNotContainsString(
            'x-data="bookingCreate()"',
            $html,
            'Must not use function-call syntax in x-data'
        );

        $this->assertStringNotContainsString(
            '<script>',
            $html,
            'Must not contain inline script block'
        );
    }

    public function test_booking_create_has_data_attributes(): void
    {
        $this->requireDatabaseForTemplates();
        $staff = [
            ['id' => 'staff-1', 'name' => 'Alice', 'title' => 'Stylist'],
            ['id' => 'staff-2', 'name' => 'Bob', 'title' => ''],
        ];

        $serviceStaffMap = ['svc-1' => ['staff-1']];

        $html = $this->renderBookingCreate([
            'staff'           => $staff,
            'serviceStaffMap' => $serviceStaffMap,
        ]);

        $this->assertStringContainsString(
            'data-slug=',
            $html,
            'Must contain data-slug attribute'
        );

        $this->assertStringContainsString(
            'data-staff-map=',
            $html,
            'Must contain data-staff-map attribute for staff filtering'
        );

        $this->assertStringContainsString(
            'data-all-staff=',
            $html,
            'Must contain data-all-staff attribute for staff options'
        );

        $this->assertStringContainsString(
            'data-old=',
            $html,
            'Must contain data-old attribute for form repopulation'
        );

        $this->assertStringContainsString(
            'data-locale=',
            $html,
            'Must contain data-locale attribute for time formatting'
        );

        // Verify staff JSON contains our staff members
        preg_match('/data-all-staff="([^"]+)"/', $html, $m);
        $this->assertNotEmpty($m, 'data-all-staff must have a value');

        $decoded = json_decode(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'), true);
        $this->assertIsArray($decoded);
        $this->assertCount(2, $decoded, 'Must contain 2 staff members');
        $this->assertSame('staff-1', $decoded[0]['id']);
        $this->assertSame('Alice — Stylist', $decoded[0]['label'], 'Staff with title must use em-dash separator');
        $this->assertSame('Bob', $decoded[1]['label'], 'Staff without title must show name only');
    }
}
