<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Output tests for the availability page template.
 *
 * Renders templates/admin/tenants/availability/index.php and asserts
 * the CSP-safe Alpine.js markup is correct.
 */
final class AvailabilityOutputTest extends TestCase
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
    private function renderAvailability(array $vars = []): string
    {
        // Bootstrap translation engine with app basePath
        $basePath = dirname(__DIR__, 2);
        \App\Engine\Locale::init($basePath);

        if (!function_exists('__')) {
            require_once $basePath . '/app/helpers.php';
        }

        if (!function_exists('app_name')) {
            require_once $basePath . '/app/helpers.php';
        }

        // Stub required template variables
        $tenant = $vars['tenant'] ?? ['name' => 'Test', 'booking_pattern' => 'timeslot'];
        $schedule = $vars['schedule'] ?? array_fill(0, 7, []);
        $staff = $vars['staff'] ?? [];
        $staffOverrides = $vars['staffOverrides'] ?? [];
        $currentStaffId = $vars['currentStaffId'] ?? null;
        $currentStaff = $vars['currentStaff'] ?? null;
        $hasOverride = $vars['hasOverride'] ?? false;
        $tenantId = $vars['tenantId'] ?? 'test-tenant-id';
        $csrfToken = $vars['csrfToken'] ?? 'test-csrf-token';
        $flash = $vars['flash'] ?? null;

        // Stub layout: the template calls ob_start/ob_get_clean and includes layout.
        // We intercept by capturing the output before the layout include.
        ob_start();
        include dirname(__DIR__, 2) . '/templates/admin/tenants/availability/index.php';
        $output = ob_get_clean() ?: '';

        // The template stores content in $content and includes layout.
        // Since the layout may not render cleanly in unit tests, we
        // also check if $content was set.
        if (isset($content) && $content !== '') {
            return $content;
        }

        return $output;
    }

    public function test_availability_page_renders_day_labels(): void
    {
        $this->requireDatabaseForTemplates();
        // Provide a schedule with Mon having a window so we test both states
        $schedule = array_fill(0, 7, []);
        $schedule[0] = [['start' => '09:00', 'end' => '17:00']];

        $html = $this->renderAvailability(['schedule' => $schedule]);

        // The day labels are rendered server-side via data-day-labels JSON attribute.
        // Assert each translated day name is present in the JSON payload.
        $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
        foreach ($days as $day) {
            $this->assertStringContainsString(
                $day,
                $html,
                "Rendered page must contain translated day label '{$day}'"
            );
        }
    }

    public function test_availability_page_uses_csp_safe_alpine(): void
    {
        $this->requireDatabaseForTemplates();
        $html = $this->renderAvailability();

        $this->assertStringContainsString(
            'x-data="availabilityGrid"',
            $html,
            'Must use CSP-safe Alpine.data() reference (no parens)'
        );

        $this->assertStringNotContainsString(
            'function availabilityGrid()',
            $html,
            'Must not contain inline script function definition'
        );

        $this->assertStringNotContainsString(
            'x-data="availabilityGrid()"',
            $html,
            'Must not use function-call syntax in x-data'
        );
    }

    public function test_availability_page_has_data_schedule(): void
    {
        $this->requireDatabaseForTemplates();
        $schedule = array_fill(0, 7, []);
        $schedule[2] = [['start' => '10:00', 'end' => '14:00']];

        $html = $this->renderAvailability(['schedule' => $schedule]);

        $this->assertStringContainsString(
            'data-schedule=',
            $html,
            'Must contain data-schedule attribute for Alpine hydration'
        );

        // Extract and validate the JSON
        preg_match('/data-schedule="([^"]+)"/', $html, $m);
        $this->assertNotEmpty($m, 'data-schedule attribute must have a value');

        $decoded = json_decode(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'), true);
        $this->assertIsArray($decoded);
        $this->assertCount(7, $decoded, 'Schedule JSON must have exactly 7 day entries');

        // Verify Wednesday (index 2) has our window
        $this->assertCount(1, $decoded[2], 'Wednesday should have 1 window');
        $this->assertSame('10:00', $decoded[2][0]['start']);
        $this->assertSame('14:00', $decoded[2][0]['end']);
    }
}
