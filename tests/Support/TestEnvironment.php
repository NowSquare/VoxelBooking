<?php

declare(strict_types=1);

namespace Tests\Support;

/** Binds the test process and HTTP server to one disposable application. */
final class TestEnvironment
{
    private static ?array $manifest = null;

    public static function boot(): void
    {
        if (self::$manifest !== null) {
            return;
        }
        $manifest = self::readManifest(getenv('VOXELBOOKING_TEST_MANIFEST') ?: '');
        if (realpath(dirname(__DIR__, 2)) !== realpath($manifest['app_root'])) {
            throw new \RuntimeException('Tests must run from their disposable application copy. Use php scripts/test-isolated.php.');
        }
        self::$manifest = $manifest;
        if (!defined('VOXELBOOKING_TEST_PROCESS')) {
            define('VOXELBOOKING_TEST_PROCESS', true);
        }
        foreach (array_merge($manifest['environment'], $manifest['database']) as $key => $value) {
            $_ENV[$key] = $_SERVER[$key] = (string) $value;
            putenv($key . '=' . $value);
        }
        date_default_timezone_set('UTC');
    }

    public static function readManifest(string $path): array
    {
        if ($path === '' || !is_file($path)) {
            throw new \RuntimeException('A disposable test environment is required. Use php scripts/test-isolated.php.');
        }
        $manifest = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($manifest)) {
            throw new \RuntimeException('Invalid disposable test manifest.');
        }
        self::validateManifest($manifest);
        $root = realpath((string) ($manifest['run_root'] ?? ''));
        $temporaryRoot = realpath(sys_get_temp_dir());
        if ($root === false || $temporaryRoot === false
            || !str_starts_with($root, $temporaryRoot . DIRECTORY_SEPARATOR . 'voxelbooking-test-')
            || realpath(dirname($path)) !== $root
            || !is_file($root . '/.test-run')
            || !hash_equals($manifest['run_id'], trim((string) file_get_contents($root . '/.test-run')))
        ) {
            throw new \RuntimeException('Test manifest must belong to a private disposable directory.');
        }
        if (realpath((string) ($manifest['app_root'] ?? '')) !== $root . '/app') {
            throw new \RuntimeException('Test application must belong to the disposable directory.');
        }
        return $manifest;
    }

    public static function validateManifest(array $manifest): void
    {
        $id = $manifest['run_id'] ?? '';
        $database = $manifest['database'] ?? [];
        if (($manifest['version'] ?? null) !== 1 || !is_string($id) || !preg_match('/^[a-f0-9]{32}$/D', $id)
            || ($database['DB_DATABASE'] ?? '') !== 'voxelbooking_test_' . $id
        ) {
            throw new \RuntimeException('The disposable test database name must match its run.');
        }
        if (($database['DB_HOST'] ?? '') !== '127.0.0.1'
            || !ctype_digit((string) ($database['DB_PORT'] ?? ''))
            || (int) $database['DB_PORT'] < 1024 || (int) $database['DB_PORT'] > 65535
            || ($database['DB_USERNAME'] ?? '') !== 'vb_test'
            || strlen((string) ($database['DB_PASSWORD'] ?? '')) < 32
        ) {
            throw new \RuntimeException('The disposable database requires loopback access and its own restricted credentials.');
        }
        $url = parse_url((string) ($manifest['environment']['APP_TEST_URL'] ?? ''));
        if (!is_array($url) || ($url['scheme'] ?? '') !== 'http' || ($url['host'] ?? '') !== '127.0.0.1'
            || ($url['port'] ?? 0) < 1024
        ) {
            throw new \RuntimeException('The test application URL must use its disposable loopback server.');
        }
    }

    public static function assertConfiguration(array $expected, array $actual): void
    {
        foreach (['DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'] as $key) {
            if (!isset($expected[$key]) || !hash_equals((string) $expected[$key], (string) ($actual[$key] ?? ''))) {
                throw new \RuntimeException('Unsafe test database configuration: ' . $key . ' differs from the disposable run.');
            }
        }
    }

    public static function assertDatabase(): void
    {
        self::assertActive();
        self::assertConfiguration(self::$manifest['database'], $_ENV);
    }

    public static function assertActive(): void
    {
        if (self::$manifest === null || !defined('VOXELBOOKING_TEST_PROCESS')) {
            throw new \RuntimeException('Database fixtures require php scripts/test-isolated.php.');
        }
    }

    public static function manifest(): array
    {
        self::assertActive();
        return self::$manifest;
    }

    public static function captureMail(array $message): void
    {
        self::assertActive();
        if (file_put_contents(self::$manifest['run_root'] . '/mail.jsonl', json_encode($message, JSON_THROW_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX) === false) {
            throw new \RuntimeException('Could not capture test mail.');
        }
    }

    public static function failMail(?string $reason): void
    {
        self::assertActive();
        $path = self::$manifest['run_root'] . '/mail-failure';
        if ($reason === null) {
            if (is_file($path)) {
                unlink($path);
            }
            return;
        }
        file_put_contents($path, $reason, LOCK_EX);
    }

    public static function mailFailure(): ?string
    {
        self::assertActive();
        $path = self::$manifest['run_root'] . '/mail-failure';
        return is_file($path) ? (string) file_get_contents($path) : null;
    }
}
