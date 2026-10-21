<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Template output tests for the public booking page shell.
 *
 * Verifies resource/capacity-specific back labels and guest-limit hints
 * are wired to the correct states in the template.
 */
final class BookingPageOutputTest extends TestCase
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

    public function test_resource_date_step_uses_generic_back_label(): void
    {
        $html = $this->renderPage();

        $this->assertMatchesRegularExpression(
            '/goBack\(resourceDateBackTarget\)".*t\(\'back\.generic\'\)/s',
            $html
        );
    }

    public function test_resource_guest_step_renders_max_guest_hint(): void
    {
        $html = $this->renderPage();

        $this->assertStringContainsString('guestCount >= guestMax', $html);
        $this->assertStringContainsString("t('resource.max_guests_reached').replace(':count', guestMax)", $html);
    }

    public function test_details_step_uses_pattern_aware_back_label(): void
    {
        $html = $this->renderPage();

        $this->assertMatchesRegularExpression(
            '/goBack\(activeDetailsBackTarget\)".*activeDetailsBackLabel/s',
            $html
        );
    }

    public function test_capacity_party_size_step_renders_max_party_hint(): void
    {
        $html = $this->renderPage();

        $this->assertStringContainsString('partySize >= maxPartySize', $html);
        $this->assertStringContainsString("t('capacity.max_party_size_reached').replace(':count', maxPartySize)", $html);
    }

    private function renderPage(array $overrides = []): string
    {
        $tenant = array_merge([
            'id'                       => 'test-tenant-id',
            'slug'                     => 'test-tenant',
            'name'                     => 'Test Tenant',
            'logo_path'                => null,
            'booking_page_heading'     => null,
            'booking_page_description' => null,
        ], $overrides['tenant'] ?? []);

        $tenantConfig = array_merge([
            'slug'            => 'test-tenant',
            'locale'          => 'en',
            'booking_pattern' => 'resource',
            'timezone'        => 'Europe/Amsterdam',
            'is_demo'         => false,
        ], $overrides['tenantConfig'] ?? []);

        $vars = array_merge([
            'tenant'       => $tenant,
            'tenantConfig' => $tenantConfig,
            'brandStyle'   => ':root { --vb-brand: #2563EB; }',
            'translations' => [],
            'formatting'   => [],
            'csrfToken'    => 'test-csrf-token',
        ], $overrides);

        ob_start();
        extract($vars, EXTR_SKIP);
        require $this->templateDir . '/booking/page.php';
        return (string) ob_get_clean();
    }

    /**
     * Regression: a non-blue tenant brand_color produces matching --vb-brand
     * tokens in the rendered booking page HTML, not the default blue.
     */
    public function test_nonblue_brand_emits_correct_tokens_in_html(): void
    {
        $hex = '#E11D48'; // rose-600 — distinctly non-blue
        $brandStyle = \App\Engine\BrandColorHelper::inlineStyle($hex);

        $html = $this->renderPage([
            'brandStyle' => $brandStyle,
        ]);

        // The inline style tag must contain the exact brand color
        $this->assertStringContainsString('--vb-brand: #E11D48', $html,
            'Rendered HTML must emit --vb-brand matching the tenant brand_color');

        // Must NOT contain the default blue
        $this->assertStringNotContainsString('--vb-brand: #2563EB', $html,
            'Rendered HTML must not fall back to default blue when a custom brand is set');

        // brand-text should be white (rose-600 is dark enough)
        $this->assertStringContainsString('--vb-brand-text: #FFFFFF', $html,
            'Rendered HTML must emit auto-derived --vb-brand-text');
    }

    /**
     * Regression: BrandColorHelper derives correct auto-text for a
     * light brand color (high luminance → dark text).
     */
    public function test_light_brand_derives_dark_text(): void
    {
        $tokens = \App\Engine\BrandColorHelper::derive('#FACC15'); // yellow-400

        $this->assertSame('#111827', $tokens['brand_text'],
            'High-luminance brand must derive dark text for WCAG contrast');

        // Verify inlineStyle includes all emitted tokens
        // (brand-light is NOT emitted — it's CSS-derived from brand-rgb)
        $style = \App\Engine\BrandColorHelper::inlineStyle('#FACC15');
        $this->assertStringContainsString('--vb-brand: #FACC15', $style);
        $this->assertStringContainsString('--vb-brand-text: #111827', $style);
        $this->assertStringContainsString('--vb-brand-hover:', $style);
        $this->assertStringNotContainsString('--vb-brand-light:', $style,
            'brand-light must NOT be in inline style — it is CSS-derived from brand-rgb');
        $this->assertStringContainsString('--vb-brand-rgb:', $style);
    }
}
