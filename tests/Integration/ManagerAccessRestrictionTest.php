<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Engine\Database;
use App\Engine\EnvLoader;
use PHPUnit\Framework\TestCase;

/**
 * Verify manager role is restricted from owner/operator surfaces.
 *
 * Creates a test manager via DB insert and verifies 403 on restricted routes.
 *
 * Requires: The app running at APP_TEST_URL.
 */
final class ManagerAccessRestrictionTest extends TestCase
{
    private static string $baseUrl;
    private static string $csrfToken = '';
    private static string $sessionCookie = '';
    private static bool $appReachable = false;
    private static bool $dbReady = false;
    private static string $tenantId = '';
    private static string $managerId = '';
    private static string $managerEmail = '';
    private static string $managerPassword = '';

    public static function setUpBeforeClass(): void
    {
        self::$baseUrl = rtrim($_ENV['APP_TEST_URL'] ?? 'https://voxelbooking-app.test', '/');

        $ch = curl_init(self::$baseUrl . '/health');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT        => 5,
        ]);
        $result = curl_exec($ch);
        $code = ($result !== false) ? curl_getinfo($ch, CURLINFO_HTTP_CODE) : 0;
        unset($ch);

        if ($code === 0) {
            return;
        }

        self::$appReachable = true;

        try {
            require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
            \Tests\Support\TestEnvironment::boot();
            Database::connect();
            Database::query('SELECT 1');
            self::$dbReady = true;
        } catch (\Throwable) {
            return;
        }

        if (!self::$dbReady) {
            return;
        }

        // Use the seeded test tenant
        $tenants = Database::query("SELECT `id` FROM `tenants` LIMIT 1");
        if (empty($tenants)) {
            return;
        }
        self::$tenantId = $tenants[0]['id'];

        // Create a test manager via DB insert
        self::$managerEmail = 'test-mgr-restrict-' . bin2hex(random_bytes(4)) . '@test.test';
        self::$managerPassword = 'MgrRestrict123!';
        $managerId = \App\Engine\Ulid::generate();
        $hash = password_hash(self::$managerPassword, PASSWORD_BCRYPT, ['cost' => 12]);

        Database::execute(
            "INSERT INTO `business_users` (`id`, `tenant_id`, `name`, `email`, `password_hash`, `role`, `is_active`, `force_password_change`)
             VALUES (?, ?, 'Test Manager', ?, ?, 'manager', 1, 0)",
            [$managerId, self::$tenantId, self::$managerEmail, $hash]
        );
        self::$managerId = $managerId;

        // Login as manager
        self::managerLogin();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$managerId !== '') {
            try {
                Database::execute('DELETE FROM `business_users` WHERE `id` = ?', [self::$managerId]);
            } catch (\Throwable) {
            }
        }
    }

    protected function setUp(): void
    {
        if (!self::$appReachable) {
            $this->markTestSkipped('App not reachable');
        }
        if (!self::$dbReady || self::$tenantId === '' || self::$managerId === '') {
            $this->markTestSkipped('Database or test manager not available');
        }
    }

    // ════════════════════════════════════════════════════════════════
    // Tests: Restricted routes (manager gets 403 or redirect)
    // ════════════════════════════════════════════════════════════════

    /**
     * Manager is denied access to operator-only and owner-only routes.
     */
    public function testManagerDeniedOnRestrictedRoutes(): void
    {
        $restrictedRoutes = [
            '/admin/settings'                                      => '403',
            '/admin/deletion-queue'                                => '403',
            '/admin/tenants'                                       => '403',
            '/admin/tenants/create'                                => '403',
            '/admin/bookings'                                      => '403',
            "/admin/tenants/" . self::$tenantId . "/users"         => '403',
            "/admin/tenants/" . self::$tenantId . "/users/invite"  => '403',
        ];

        foreach ($restrictedRoutes as $path => $expected) {
            $res = self::httpGet($path);
            $this->assertContains(
                $res['code'],
                [403, 302],
                "Manager must be denied on {$path}, got {$res['code']}"
            );
        }
    }

    /**
     * Manager can access tenant dashboard, bookings, customers, and calendar.
     */
    public function testManagerAllowedOnTenantRoutes(): void
    {
        $allowedRoutes = [
            "/admin/tenants/" . self::$tenantId                     => 200,
            "/admin/tenants/" . self::$tenantId . "/bookings"       => 200,
            "/admin/tenants/" . self::$tenantId . "/customers"      => 200,
            "/admin/tenants/" . self::$tenantId . "/calendar"       => 200,
            "/admin/tenants/" . self::$tenantId . "/calendar/week"  => 200,
        ];

        foreach ($allowedRoutes as $path => $expectedCode) {
            $res = self::httpGet($path);
            $this->assertSame(
                $expectedCode,
                $res['code'],
                "Manager must get {$expectedCode} on {$path}, got {$res['code']}"
            );
        }
    }

    // ════════════════════════════════════════════════════════════════
    // Helpers
    // ════════════════════════════════════════════════════════════════

    private static function managerLogin(): void
    {
        $loginPage = self::httpGet('/admin/login');
        self::extractCsrf($loginPage['body']);

        $res = self::httpPost('/admin/login', [
            '_csrf_token' => self::$csrfToken,
            'email'       => self::$managerEmail,
            'password'    => self::$managerPassword,
        ]);

        if (preg_match('/vb_session=([^;]+)/', $res['headers'], $m)) {
            self::$sessionCookie = 'vb_session=' . $m[1];
        }

        // Follow redirect to get dashboard (which will redirect manager to tenant)
        $dashboard = self::httpGet('/admin');
        self::extractCsrf($dashboard['body']);
    }

    private static function extractCsrf(string $html): void
    {
        if (preg_match('/name="_csrf_token"\s+value="([^"]+)"/', $html, $m)) {
            self::$csrfToken = $m[1];
        }
    }

    private static function httpGet(string $path): array
    {
        return self::http('GET', $path);
    }

    private static function httpPost(string $path, array $data = []): array
    {
        return self::http('POST', $path, $data);
    }

    /**
     * @return array{code: int, headers: string, body: string}
     */
    private static function http(string $method, string $path, array $data = []): array
    {
        $ch = curl_init(self::$baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADER         => true,
        ]);

        $headers = [];
        if (self::$sessionCookie) {
            $headers[] = 'Cookie: ' . self::$sessionCookie;
        }
        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        }

        $response = (string) curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        unset($ch);

        $responseHeaders = substr($response, 0, $headerSize);

        if (preg_match('/vb_session=([^;]+)/', $responseHeaders, $m)) {
            self::$sessionCookie = 'vb_session=' . $m[1];
        }

        return [
            'code'    => $code,
            'headers' => $responseHeaders,
            'body'    => substr($response, $headerSize),
        ];
    }
}
