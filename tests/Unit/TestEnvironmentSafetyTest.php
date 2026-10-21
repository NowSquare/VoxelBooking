<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\TestEnvironment;

final class TestEnvironmentSafetyTest extends TestCase
{
    public function testSafetyEnvironmentIsAvailable(): void
    {
        self::assertTrue(class_exists(TestEnvironment::class), 'A test environment guard must protect database fixtures.');
    }

    public function testMissingManifestIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('test-isolated.php');
        TestEnvironment::readManifest('');
    }

    public function testOrdinaryDatabaseIsRejectedBeforeConnecting(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('database');
        TestEnvironment::validateManifest([
            'version' => 1,
            'run_id' => str_repeat('a', 32),
            'database' => ['DB_DATABASE' => 'voxelbooking'],
        ]);
    }

    public function testDatabaseConfigurationCannotChangeAfterValidation(): void
    {
        $expected = ['DB_HOST' => '127.0.0.1', 'DB_PORT' => '14000', 'DB_DATABASE' => 'voxelbooking_test_' . str_repeat('a', 32), 'DB_USERNAME' => 'vb_test', 'DB_PASSWORD' => 'synthetic'];
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('DB_DATABASE');
        TestEnvironment::assertConfiguration($expected, array_replace($expected, ['DB_DATABASE' => 'voxelbooking']));
    }

    public function testSameDatabaseNameWithDifferentUserIsRejected(): void
    {
        $expected = ['DB_HOST' => '127.0.0.1', 'DB_PORT' => '14000', 'DB_DATABASE' => 'voxelbooking_test_' . str_repeat('a', 32), 'DB_USERNAME' => 'vb_test', 'DB_PASSWORD' => 'synthetic'];
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('DB_USERNAME');
        TestEnvironment::assertConfiguration($expected, array_replace($expected, ['DB_USERNAME' => 'root']));
    }

    public function testMatchingConfigurationPasses(): void
    {
        $expected = ['DB_HOST' => '127.0.0.1', 'DB_PORT' => '14000', 'DB_DATABASE' => 'voxelbooking_test_' . str_repeat('a', 32), 'DB_USERNAME' => 'vb_test', 'DB_PASSWORD' => 'synthetic'];
        TestEnvironment::assertConfiguration($expected, $expected);
        self::assertTrue(true);
    }

    public function testEveryConnectionSettingIsBoundToTheRun(): void
    {
        $expected = ['DB_HOST' => '127.0.0.1', 'DB_PORT' => '14000', 'DB_DATABASE' => 'voxelbooking_test_' . str_repeat('a', 32), 'DB_USERNAME' => 'vb_test', 'DB_PASSWORD' => 'synthetic'];
        foreach (array_keys($expected) as $key) {
            try {
                TestEnvironment::assertConfiguration($expected, array_replace($expected, [$key => 'changed']));
                self::fail('Changed ' . $key . ' must be rejected.');
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString($key, $exception->getMessage());
                self::assertStringNotContainsString('synthetic', $exception->getMessage());
            }
        }
    }

    public function testCheckoutConfigurationIsNotLoadedByBootstrap(): void
    {
        $bootstrap = file_get_contents(dirname(__DIR__) . '/bootstrap.php');
        self::assertStringNotContainsString('file_put_contents', $bootstrap);
        self::assertStringNotContainsString('new PDO', $bootstrap);
        self::assertStringNotContainsString('EnvLoader::load', $bootstrap);
    }

    public function testDefaultPhpunitConfigurationContainsNoDevelopmentDatabase(): void
    {
        $xml = file_get_contents(dirname(__DIR__, 2) . '/phpunit.xml');
        foreach (['DB_DATABASE', 'DB_USERNAME', 'DB_HOST', 'DB_PASSWORD'] as $key) {
            self::assertStringNotContainsString('name="' . $key . '"', $xml);
        }
    }
}
