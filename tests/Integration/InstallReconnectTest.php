<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Controllers\Install\WizardController;
use App\Engine\Database;
use App\Engine\Locale;
use App\Engine\Request;
use App\Engine\View;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for the installation wizard reconnect decision flow.
 *
 * Covers:
 * - Existing DB → reconnect choice screen rendered
 * - keep path → login redirect with data preserved
 * - refresh path without checkbox confirmation → blocked
 * - refresh path with checkbox confirmation → DB recreated
 */
final class InstallReconnectTest extends TestCase
{
    private array $originalEnv;
    private array $originalPost;
    private array $originalServer;

    protected function setUp(): void
    {
        $this->originalEnv = [
            'DB_HOST'     => $_ENV['DB_HOST'] ?? null,
            'DB_PORT'     => $_ENV['DB_PORT'] ?? null,
            'DB_DATABASE' => $_ENV['DB_DATABASE'] ?? null,
            'DB_USERNAME' => $_ENV['DB_USERNAME'] ?? null,
            'DB_PASSWORD' => $_ENV['DB_PASSWORD'] ?? null,
        ];
        $this->originalPost = $_POST;
        $this->originalServer = $_SERVER;

        $this->requireDatabase();
        $this->ensureInstalledAt();

        // Init View and Locale so templates can render
        $basePath = dirname(__DIR__, 2);
        View::init($basePath . '/templates');
        Locale::init($basePath . '/lang');
    }

    protected function tearDown(): void
    {
        foreach ($this->originalEnv as $key => $value) {
            if ($value === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $value;
            }
        }
        $_POST = $this->originalPost;
        $_SERVER = $this->originalServer;
        Database::reset();

        // Always restore installed_at
        try {
            Database::execute(
                "INSERT INTO `settings` (`key`, `value`, `updated_at`) VALUES ('installed_at', ?, NOW())
                 ON DUPLICATE KEY UPDATE `value` = ?",
                ['2026-03-27 10:00:00', '2026-03-27 10:00:00']
            );
        } catch (\Throwable) {
            // DB might be gone after refresh test — bootstrap will restore
        }
    }

    /**
     * Safety net: restore installed_at after all tests in this class.
     */
    public static function tearDownAfterClass(): void
    {
        try {
            \Tests\Support\TestEnvironment::assertDatabase();
            $host     = $_ENV['DB_HOST']     ?? '127.0.0.1';
            $port     = $_ENV['DB_PORT']     ?? '3309';
            $database = $_ENV['DB_DATABASE'] ?? 'voxelbooking';
            $username = $_ENV['DB_USERNAME'] ?? 'root';
            $password = $_ENV['DB_PASSWORD'] ?? '';

            $dsn = "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4";
            $pdo = new \PDO($dsn, $username, $password, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            ]);

            $pdo->prepare(
                "INSERT INTO `settings` (`key`, `value`, `updated_at`) VALUES ('installed_at', ?, NOW())
                 ON DUPLICATE KEY UPDATE `value` = IF(`value` = '' OR `value` IS NULL, VALUES(`value`), `value`)"
            )->execute(['2026-03-27 10:00:00']);
        } catch (\Throwable) {
            // Suppress — bootstrap will handle next run
        }
    }

    // ── Tests ──

    public function testReconnectDetectsExistingDbAndShowsChoiceScreen(): void
    {
        $_POST = $this->makeDbPostData();
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/install/step/2';

        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }

        $controller = new WizardController();
        $request = new Request();
        $response = $controller->stepTwo($request);

        $this->assertSame(200, $response->getStatusCode());
        $body = $response->getBody();
        // The reconnect screen renders the two-form choice layout
        $this->assertStringContainsString('vb-form-reconnect-keep', $body);
        $this->assertStringContainsString('vb-form-reconnect-refresh', $body);
        $this->assertStringContainsString('_reconnect_action', $body);
    }

    public function testReconnectKeepPathRedirectsToLogin(): void
    {
        $_POST = array_merge($this->makeDbPostData(), [
            '_reconnect_action' => 'keep',
        ]);
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/install/step/2';

        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }

        $controller = new WizardController();
        $request = new Request();
        $response = $controller->stepTwo($request);

        // Should redirect to /admin/login
        $this->assertSame(302, $response->getStatusCode());
        $headers = $response->getHeaders();
        $this->assertStringContainsString('/admin/login', $headers['Location'] ?? '');

        // Data should still be in the database
        $installed = Database::query(
            "SELECT `value` FROM `settings` WHERE `key` = 'installed_at' LIMIT 1"
        );
        $this->assertNotEmpty($installed[0]['value'] ?? '');
    }

    public function testReconnectRefreshBlockedWithoutConfirmation(): void
    {
        $_POST = array_merge($this->makeDbPostData(), [
            '_reconnect_action' => 'refresh',
            // No confirm_refresh field — should be blocked
        ]);
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/install/step/2';

        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }

        $controller = new WizardController();
        $request = new Request();
        $response = $controller->stepTwo($request);

        // Should return the reconnect screen with an error, NOT drop tables
        $this->assertSame(200, $response->getStatusCode());
        $body = $response->getBody();
        $this->assertStringContainsString('vb-form-reconnect-keep', $body);

        // Verify tables still exist
        $installed = Database::query(
            "SELECT `value` FROM `settings` WHERE `key` = 'installed_at' LIMIT 1"
        );
        $this->assertNotEmpty($installed[0]['value'] ?? '');
    }

    public function testReconnectRefreshBlockedWithEmptyConfirmation(): void
    {
        $_POST = array_merge($this->makeDbPostData(), [
            '_reconnect_action' => 'refresh',
            'confirm_refresh' => '',  // checkbox unchecked sends empty
        ]);
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/install/step/2';

        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }

        $controller = new WizardController();
        $request = new Request();
        $response = $controller->stepTwo($request);

        // Should return the reconnect screen with an error
        $this->assertSame(200, $response->getStatusCode());
        $body = $response->getBody();
        $this->assertStringContainsString('vb-form-reconnect-keep', $body);

        // Verify tables still exist
        $installed = Database::query(
            "SELECT `value` FROM `settings` WHERE `key` = 'installed_at' LIMIT 1"
        );
        $this->assertNotEmpty($installed[0]['value'] ?? '');
    }

    public function testReconnectRefreshSucceedsWithCorrectConfirmation(): void
    {
        $basePath = dirname(__DIR__, 2);
        $envPath = $basePath . '/.env';

        // Back up the original .env before the controller overwrites it
        $envBackup = is_file($envPath) ? file_get_contents($envPath) : null;

        // Submit the REAL destructive refresh: installed_at is present,
        // _reconnect_action=refresh, confirm_refresh=1 (checkbox checked).
        $_POST = array_merge($this->makeDbPostData(), [
            '_reconnect_action' => 'refresh',
            'confirm_refresh'  => '1',
        ]);
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/install/step/2';

        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }

        $controller = new WizardController();
        $request = new Request();
        $response = $controller->stepTwo($request);

        // The refresh branch drops all tables, re-runs migrations, then
        // redirects to step 3 — proving the guard passed and the
        // destructive path executed successfully.
        $this->assertSame(302, $response->getStatusCode());
        $headers = $response->getHeaders();
        $this->assertStringContainsString('/install?step=3', $headers['Location'] ?? '');

        // Verify tables were recreated by migrations (settings table exists)
        $tables = Database::query(
            'SELECT table_name AS tbl FROM information_schema.tables WHERE table_schema = ?',
            [$_ENV['DB_DATABASE'] ?? 'voxelbooking']
        );
        $tableNames = array_column($tables, 'tbl');
        $this->assertContains('settings', $tableNames, 'settings table should be recreated');
        $this->assertContains('operators', $tableNames, 'operators table should be recreated');

        // ── Restore everything for subsequent tests ──
        // 1. Restore the original .env file
        if ($envBackup !== null) {
            file_put_contents($envPath, $envBackup);
        }

        // 2. Reset Database singleton so it reconnects fresh
        Database::reset();
        Database::connect();

        // 3. Insert installed_at (tables are empty after migrations)
        Database::execute(
            "INSERT INTO `settings` (`key`, `value`, `updated_at`) VALUES ('installed_at', ?, NOW())
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
            ['2026-03-27 10:00:00']
        );

        // 4. Reset TestFixtures::$provisioned flag and re-provision
        $ref = new \ReflectionClass(TestFixtures::class);
        $prop = $ref->getProperty('provisioned');
        $prop->setValue(null, false);
        TestFixtures::provision();
    }

    public function testFinishInstallationRedirectsToAdminLogin(): void
    {
        // clear installed_at so finishInstallation() can set it
        Database::execute(
            "UPDATE `settings` SET `value` = '' WHERE `key` = 'installed_at'"
        );

        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }

        $controller = new WizardController();
        $request = new Request();
        $response = $controller->complete($request);

        // Must redirect to /admin/login, not /install?step=complete
        $this->assertSame(302, $response->getStatusCode());
        $headers = $response->getHeaders();
        $this->assertSame('/admin/login', $headers['Location'] ?? '');

        // installed_at must be set
        $row = Database::query(
            "SELECT `value` FROM `settings` WHERE `key` = 'installed_at' LIMIT 1"
        );
        $this->assertNotEmpty($row[0]['value'] ?? '');
    }

    // ── Helpers ──

    private function requireDatabase(): void
    {
        $_ENV['DB_HOST']     = $this->originalEnv['DB_HOST']     ?? '127.0.0.1';
        $_ENV['DB_PORT']     = $this->originalEnv['DB_PORT']     ?? '3309';
        $_ENV['DB_DATABASE'] = $this->originalEnv['DB_DATABASE'] ?? 'voxelbooking';
        $_ENV['DB_USERNAME'] = $this->originalEnv['DB_USERNAME'] ?? 'root';
        $_ENV['DB_PASSWORD'] = $this->originalEnv['DB_PASSWORD'] ?? '';

        Database::reset();

        try {
            Database::connect();
            Database::query('SELECT 1');
        } catch (\Throwable $e) {
            $this->markTestSkipped(
                'Database not available. Requires MySQL at '
                . $_ENV['DB_HOST'] . ':' . $_ENV['DB_PORT']
                . '. Reason: ' . $e->getMessage()
            );
        }
    }

    private function ensureInstalledAt(): void
    {
        Database::execute(
            "INSERT INTO `settings` (`key`, `value`, `updated_at`) VALUES ('installed_at', ?, NOW())
             ON DUPLICATE KEY UPDATE `value` = ?",
            ['2026-03-27 10:00:00', '2026-03-27 10:00:00']
        );
    }

    private function makeDbPostData(): array
    {
        return [
            'db_host'     => $_ENV['DB_HOST']     ?? '127.0.0.1',
            'db_port'     => $_ENV['DB_PORT']     ?? '3309',
            'db_database' => $_ENV['DB_DATABASE'] ?? 'voxelbooking',
            'db_username' => $_ENV['DB_USERNAME'] ?? 'root',
            'db_password' => $_ENV['DB_PASSWORD'] ?? '',
        ];
    }
}
