<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers;

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for slug validation in TenantsController.
 *
 * Tests the static isValidSlug() method via reflection, ensuring the regex
 * ^[a-z0-9]+(?:-[a-z0-9]+)*$ correctly validates URL slugs.
 *
 * Does not require database access — pure regex validation.
 */
final class TenantSlugValidationTest extends TestCase
{
    private static \ReflectionMethod $isValidSlug;

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

        $rc = new \ReflectionClass(\App\Controllers\Admin\TenantsController::class);
        self::$isValidSlug = $rc->getMethod('isValidSlug');
    }

    private function assertSlugValid(string $slug, string $message = ''): void
    {
        $this->assertTrue(
            self::$isValidSlug->invoke(null, $slug),
            $message ?: "Expected slug '{$slug}' to be VALID"
        );
    }

    private function assertSlugInvalid(string $slug, string $message = ''): void
    {
        $this->assertFalse(
            self::$isValidSlug->invoke(null, $slug),
            $message ?: "Expected slug '{$slug}' to be INVALID"
        );
    }

    // ═══════════════════════════════════════════════════
    // Valid slugs
    // ═══════════════════════════════════════════════════

    public function test_simple_slug(): void
    {
        $this->assertSlugValid('salon');
    }

    public function test_slug_with_numbers(): void
    {
        $this->assertSlugValid('salon123');
    }

    public function test_slug_with_hyphens(): void
    {
        $this->assertSlugValid('acme-hair-studio');
    }

    public function test_single_char_slug(): void
    {
        $this->assertSlugValid('a');
    }

    public function test_numeric_only_slug(): void
    {
        $this->assertSlugValid('42');
    }

    public function test_multi_segment_slug(): void
    {
        $this->assertSlugValid('my-super-great-salon-2025');
    }

    // ═══════════════════════════════════════════════════
    // Invalid slugs — format violations
    // ═══════════════════════════════════════════════════

    public function test_empty_string(): void
    {
        $this->assertSlugInvalid('');
    }

    public function test_uppercase_letters(): void
    {
        $this->assertSlugInvalid('Salon');
    }

    public function test_mixed_case(): void
    {
        $this->assertSlugInvalid('AcmeHair');
    }

    public function test_spaces(): void
    {
        $this->assertSlugInvalid('acme hair');
    }

    public function test_underscores(): void
    {
        $this->assertSlugInvalid('acme_hair');
    }

    public function test_leading_hyphen(): void
    {
        $this->assertSlugInvalid('-acme');
    }

    public function test_trailing_hyphen(): void
    {
        $this->assertSlugInvalid('acme-');
    }

    public function test_consecutive_hyphens(): void
    {
        $this->assertSlugInvalid('acme--hair');
    }

    public function test_dots(): void
    {
        $this->assertSlugInvalid('acme.hair');
    }

    public function test_slashes(): void
    {
        $this->assertSlugInvalid('acme/hair');
    }

    public function test_path_traversal(): void
    {
        $this->assertSlugInvalid('../etc/passwd');
    }

    public function test_query_chars(): void
    {
        $this->assertSlugInvalid('acme?foo=bar');
    }

    public function test_unicode(): void
    {
        $this->assertSlugInvalid('café');
    }

    public function test_special_chars(): void
    {
        $this->assertSlugInvalid('acme@hair');
    }

    public function test_hash(): void
    {
        $this->assertSlugInvalid('acme#section');
    }

    public function test_only_hyphen(): void
    {
        $this->assertSlugInvalid('-');
    }

    public function test_only_hyphens(): void
    {
        $this->assertSlugInvalid('---');
    }

    public function test_ampersand(): void
    {
        $this->assertSlugInvalid('acme&hair');
    }

    public function test_percent(): void
    {
        $this->assertSlugInvalid('acme%20hair');
    }
}
