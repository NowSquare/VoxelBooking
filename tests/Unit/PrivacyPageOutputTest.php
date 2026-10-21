<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Template output tests for the public privacy page.
 *
 * Verifies demo-mode guard rendering on the privacy page
 * (export + deletion forms guarded by inline JS in demo mode).
 */
final class PrivacyPageOutputTest extends TestCase
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
    // Demo mode: inline JS guard on forms
    // ════════════════════════════════════════════════════════════════

    public function test_demo_mode_renders_form_submission_guard(): void
    {
        $tmpDir = sys_get_temp_dir() . '/vb-demo-privacy-' . mt_rand();
        @mkdir($tmpDir, 0755, true);
        touch($tmpDir . '/.demo');
        \App\Engine\DemoMode::reset();
        \App\Engine\DemoMode::init($tmpDir);

        $html = $this->renderPrivacyPage();

        // Demo guard JS must be present
        $this->assertStringContainsString('showDemoToast', $html, 'Demo mode must render showDemoToast guard');
        $this->assertStringContainsString('e.preventDefault()', $html, 'Demo mode must prevent form submissions');

        // Both action forms must still render (§6: visible but blocked)
        $this->assertStringContainsString('value="export"', $html, 'Export form must still be in DOM');
        $this->assertStringContainsString('value="delete"', $html, 'Delete form must still be in DOM');

        @unlink($tmpDir . '/.demo');
        @rmdir($tmpDir);
        \App\Engine\DemoMode::reset();
    }

    public function test_normal_mode_does_not_render_demo_guard(): void
    {
        \App\Engine\DemoMode::reset();
        \App\Engine\DemoMode::init(sys_get_temp_dir() . '/vb-no-demo-' . mt_rand());

        $html = $this->renderPrivacyPage();

        $this->assertStringNotContainsString('showDemoToast', $html, 'Normal mode must not render demo toast guard');

        // Action forms must render
        $this->assertStringContainsString('value="export"', $html, 'Export form must render');
        $this->assertStringContainsString('value="delete"', $html, 'Delete form must render');

        \App\Engine\DemoMode::reset();
    }

    private function renderPrivacyPage(): string
    {
        $tenant = [
            'id'          => 'test-tenant-id',
            'name'        => 'Test Tenant',
            'slug'        => 'test-tenant',
            'brand_color' => '#4F46E5',
        ];

        $customer = [
            'id'         => 'test-customer-id',
            'name'       => 'Emma Johnson',
            'email'      => 'emma@example.com',
            'phone'      => '+31 6 0000 0001',
            'created_at' => '2025-01-15 10:00:00',
        ];

        $bookings = [
            [
                'start_datetime'  => '2025-02-01 10:00:00',
                'status'          => 'confirmed',
                'booking_pattern' => 'timeslot',
                'party_size'      => '1',
                'source'          => 'web',
            ],
        ];

        $consentRecords = [];
        $csrfToken = 'test-csrf-token';
        $pageTitle = 'Your Data — Test Tenant';

        ob_start();
        include $this->templateDir . '/booking/privacy.php';
        return ob_get_clean() ?: '';
    }
}
