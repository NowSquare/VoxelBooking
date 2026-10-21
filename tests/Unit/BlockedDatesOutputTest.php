<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Output tests for the blocked dates template.
 *
 * Renders templates/admin/tenants/blocked-dates/index.php and asserts
 * the CSP-safe Alpine.js markup.
 */
final class BlockedDatesOutputTest extends TestCase
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
    private function renderBlockedDates(array $vars = []): string
    {
        $basePath = dirname(__DIR__, 2);
        \App\Engine\Locale::init($basePath);

        if (!function_exists('__')) {
            require_once $basePath . '/app/helpers.php';
        }

        $tenant    = $vars['tenant'] ?? ['name' => 'Test', 'booking_pattern' => 'timeslot'];
        $tenantId  = $vars['tenantId'] ?? 'test-tenant-id';
        $upcoming  = $vars['upcoming'] ?? [];
        $past      = $vars['past'] ?? [];
        $staff     = $vars['staff'] ?? [];
        $csrfToken = $vars['csrfToken'] ?? 'test-csrf-token';
        $flash     = $vars['flash'] ?? null;

        ob_start();
        include $basePath . '/templates/admin/tenants/blocked-dates/index.php';
        $output = ob_get_clean() ?: '';

        if (isset($content) && $content !== '') {
            return $content;
        }

        return $output;
    }

    public function test_blocked_dates_uses_csp_safe_alpine(): void
    {
        $this->requireDatabaseForTemplates();
        $html = $this->renderBlockedDates();

        $this->assertStringContainsString(
            'x-data="blockedDateScope"',
            $html,
            'Must use CSP-safe Alpine.data() reference'
        );

        $this->assertStringNotContainsString(
            'x-data="{ scope',
            $html,
            'Must not use inline object literal in x-data'
        );

        $this->assertStringContainsString(
            '@change="onScopeChange()"',
            $html,
            'Must use method call instead of inline handler'
        );
    }
}
