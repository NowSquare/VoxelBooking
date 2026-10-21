<?php

declare(strict_types=1);

namespace Tests\Unit\Engine;

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for welcome email rendering and sender identity.
 *
 * Tests the renderWelcomeEmail() and renderWelcomePlainText() private methods
 * via reflection to verify the template produces correct HTML structure and
 * the plain-text version contains required credential fields.
 *
 * Does not require SMTP or database access — pure rendering tests.
 */
final class WelcomeEmailRenderTest extends TestCase
{
    private static \ReflectionMethod $renderHtml;
    private static \ReflectionMethod $renderPlain;
    private static bool $ready = false;

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

        // Bootstrap locale so __() calls return the key as fallback
        try {
            \App\Engine\Locale::setLocale('en');
        } catch (\Throwable) {
            // Best effort — tests work even if locale init fails
        }

        $rc = new \ReflectionClass(\App\Engine\Mailer::class);

        self::$renderHtml = $rc->getMethod('renderWelcomeEmail');

        self::$renderPlain = $rc->getMethod('renderWelcomePlainText');

        self::$ready = true;
    }

    protected function setUp(): void
    {
        if (!self::$ready) {
            $this->markTestSkipped('Mailer class not available');
        }
    }

    private function renderHtml(
        string $tenantName = 'Salon Bella',
        string $userName = 'Emma',
        string $userEmail = 'emma@example.com',
        string $password = 'kR9x!mP2qL5n',
        string $loginUrl = 'https://app.example.com/admin/login',
        string $bookingUrl = 'https://app.example.com/book/salon-bella',
        string $appName = 'VoxelBooking',
    ): string {
        return self::$renderHtml->invoke(
            null, $tenantName, $userName, $userEmail, $password,
            $loginUrl, $bookingUrl, $appName,
        );
    }

    private function renderPlain(
        string $tenantName = 'Salon Bella',
        string $userName = 'Emma',
        string $userEmail = 'emma@example.com',
        string $password = 'kR9x!mP2qL5n',
        string $loginUrl = 'https://app.example.com/admin/login',
        string $bookingUrl = 'https://app.example.com/book/salon-bella',
        string $appName = 'VoxelBooking',
    ): string {
        return self::$renderPlain->invoke(
            null, $tenantName, $userName, $userEmail, $password,
            $loginUrl, $bookingUrl, $appName,
        );
    }

    // ═══════════════════════════════════════════════════
    // HTML template tests
    // ═══════════════════════════════════════════════════

    public function test_html_contains_user_email(): void
    {
        $html = $this->renderHtml();
        $this->assertStringContainsString('emma@example.com', $html);
    }

    public function test_html_contains_login_url(): void
    {
        $html = $this->renderHtml();
        $this->assertStringContainsString('https://app.example.com/admin/login', $html);
    }

    public function test_html_contains_password_in_code_tag(): void
    {
        $html = $this->renderHtml();
        $this->assertStringContainsString('<code', $html);
        $this->assertStringContainsString('kR9x!mP2qL5n', $html);
    }

    public function test_html_contains_booking_page_url(): void
    {
        $html = $this->renderHtml();
        $this->assertStringContainsString('https://app.example.com/book/salon-bella', $html);
    }

    public function test_html_omits_booking_section_when_empty(): void
    {
        $html = $this->renderHtml(bookingUrl: '');
        $this->assertStringNotContainsString('/book/salon-bella', $html);
    }

    public function test_html_has_system_accent_color(): void
    {
        $html = $this->renderHtml();
        // Uses system indigo, NOT tenant brand color
        $this->assertStringContainsString('#2563EB', $html);
    }

    public function test_html_has_dark_mode_meta(): void
    {
        $html = $this->renderHtml();
        $this->assertStringContainsString('color-scheme', $html);
        $this->assertStringContainsString('prefers-color-scheme: dark', $html);
    }

    public function test_html_uses_responsive_max_width(): void
    {
        $html = $this->renderHtml();
        $this->assertStringContainsString('max-width: 560px', $html);
        $this->assertStringContainsString('width: 100%', $html);
    }

    public function test_html_uses_word_break_for_urls(): void
    {
        $html = $this->renderHtml();
        $this->assertStringContainsString('word-break: break-all', $html);
    }

    public function test_html_uses_css_icon_not_emoji(): void
    {
        $html = $this->renderHtml();
        // Should NOT contain emoji characters
        $this->assertStringNotContainsString('🔑', $html);
        // Should contain the CSS-safe four-pointed star
        $this->assertStringContainsString('&#x2726;', $html);
    }

    public function test_html_escapes_xss_in_tenant_name(): void
    {
        $html = $this->renderHtml(tenantName: '<script>alert("xss")</script>');
        $this->assertStringNotContainsString('<script>', $html);
    }

    // ═══════════════════════════════════════════════════
    // Plain-text template tests
    // ═══════════════════════════════════════════════════

    public function test_plain_contains_user_email(): void
    {
        $plain = $this->renderPlain();
        $this->assertStringContainsString('emma@example.com', $plain);
    }

    public function test_plain_contains_password(): void
    {
        $plain = $this->renderPlain();
        $this->assertStringContainsString('kR9x!mP2qL5n', $plain);
    }

    public function test_plain_contains_login_url(): void
    {
        $plain = $this->renderPlain();
        $this->assertStringContainsString('https://app.example.com/admin/login', $plain);
    }

    public function test_plain_contains_booking_url(): void
    {
        $plain = $this->renderPlain();
        $this->assertStringContainsString('https://app.example.com/book/salon-bella', $plain);
    }

    public function test_plain_omits_booking_url_when_empty(): void
    {
        $plain = $this->renderPlain(bookingUrl: '');
        $this->assertStringNotContainsString('/book/salon-bella', $plain);
    }
}
