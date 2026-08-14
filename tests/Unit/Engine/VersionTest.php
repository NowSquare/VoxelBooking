<?php

declare(strict_types=1);

namespace Tests\Unit\Engine;

use App\Engine\Version;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the Version engine class.
 *
 * Verifies that the root VERSION file is the single canonical
 * source of truth for the product version.
 */
final class VersionTest extends TestCase
{
    protected function setUp(): void
    {
        Version::reset();
    }

    protected function tearDown(): void
    {
        Version::reset();
    }

    public function testReadsVersionFromFile(): void
    {
        $version = Version::get();

        $this->assertNotEmpty($version);
        $this->assertNotSame('0.0.0', $version, 'VERSION file must be readable');
    }

    public function testReturnsSemverFormat(): void
    {
        $version = Version::get();

        $this->assertMatchesRegularExpression(
            '/^\d+\.\d+\.\d+$/',
            $version,
            'Version must be semver (X.Y.Z)'
        );
    }

    /**
     * Pinning the literal version here made the test fail on every release.
     * The VERSION file is the canonical source, so compare against it.
     */
    public function testMatchesTheVersionFile(): void
    {
        $this->assertSame(trim(file_get_contents(Version::filePath())), Version::get());
    }

    public function testVersionFileExists(): void
    {
        $path = Version::filePath();

        $this->assertFileExists($path);
        $this->assertFileIsReadable($path);
    }

    public function testVersionIsCached(): void
    {
        $first = Version::get();
        $second = Version::get();

        $this->assertSame($first, $second);
    }

    public function testResetClearsCache(): void
    {
        $first = Version::get();
        Version::reset();
        $second = Version::get();

        // Should still return the same value (file hasn't changed)
        $this->assertSame($first, $second);
    }

    public function testVersionFileHasNoLeadingOrTrailingWhitespace(): void
    {
        $raw = file_get_contents(Version::filePath());
        $trimmed = trim($raw);

        $this->assertSame($trimmed, Version::get());
    }
}
