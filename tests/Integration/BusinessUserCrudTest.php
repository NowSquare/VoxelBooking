<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Engine\Database;
use App\Engine\EnvLoader;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for business user CRUD (Team management).
 *
 * Covers: list, invite, invite validation, deactivate, activate, manager 403.
 *
 * Uses a shared tenant (created via direct DB insert) and a single operator
 * session to minimize HTTP requests and stay under the 120/min rate limit.
 *
 * Requires: The app running at APP_TEST_URL (defaults to https://voxelbooking-app.test).
 */
final class BusinessUserCrudTest extends TestCase
{
    private static string $baseUrl;
    private static string $csrfToken = '';
    private static string $sessionCookie = '';
    private static bool $appReachable = false;
    private static bool $dbReady = false;
    private static string $tenantId = '';

    /** @var list<string> IDs of business_users to clean up after class */
    private static array $userCleanupIds = [];

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
        curl_close($ch);

        if ($code === 0) {
            return;
        }

        self::$appReachable = true;

        try {
            require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
            EnvLoader::load(dirname(__DIR__, 2) . '/.env');
            Database::connect();
            Database::query('SELECT 1');
            self::$dbReady = true;
        } catch (\Throwable) {
            return;
        }

        if (!self::$dbReady) {
            return;
        }

        // Create shared test tenant via DB (no HTTP, no rate limit)
        $tenantId = \App\Engine\Ulid::generate();
        $slug = 'test-team-' . substr(bin2hex(random_bytes(4)), 0, 8);
        Database::execute(
            "INSERT INTO `tenants` (`id`, `name`, `slug`, `email`, `booking_pattern`, `timezone`, `currency`, `brand_color`, `status`)
             VALUES (?, ?, ?, ?, 'timeslot', 'UTC', 'EUR', '#2563EB', 'active')",
            [$tenantId, 'Team Test Tenant', $slug, $slug . '@test.test']
        );
        self::$tenantId = $tenantId;

        // Login as operator once for all tests
        self::operatorLogin();
    }

    public static function tearDownAfterClass(): void
    {
        // Clean up business users first (FK constraint)
        foreach (self::$userCleanupIds as $userId) {
            try {
                Database::execute('DELETE FROM `business_users` WHERE `id` = ?', [$userId]);
            } catch (\Throwable) {
            }
        }

        // Clean up tenant
        if (self::$tenantId !== '') {
            try {
                Database::execute('DELETE FROM `tenants` WHERE `id` = ?', [self::$tenantId]);
            } catch (\Throwable) {
            }
        }
    }

    protected function setUp(): void
    {
        if (!self::$appReachable) {
            $this->markTestSkipped('App not reachable at ' . self::$baseUrl);
        }
        if (!self::$dbReady || self::$tenantId === '') {
            $this->markTestSkipped('Database or test tenant not available');
        }
    }

    // ════════════════════════════════════════════════════════════════
    // Tests (ordered to minimize HTTP requests)
    // ════════════════════════════════════════════════════════════════

    /**
     * Operator can view user list and invite form.
     */
    public function testListAndInviteFormLoad(): void
    {
        // List page
        $res = self::httpGet("/admin/tenants/" . self::$tenantId . "/users");
        $this->assertSame(200, $res['code'], 'User list must return 200');
        $this->assertStringContainsString('Team', $res['body']);
        $this->assertStringContainsString('Invite user', $res['body']);
        $this->assertStringContainsString('href="/admin/tenants/' . self::$tenantId . '/users/invite"', $res['body'],
            'The team list must link to its own tenant invite form');

        // Invite form
        $res = self::httpGet("/admin/tenants/" . self::$tenantId . "/users/invite");
        $this->assertSame(200, $res['code'], 'Invite form must return 200');
        $this->assertStringContainsString('Owner', $res['body']);
        $this->assertStringContainsString('Manager', $res['body']);
    }

    /**
     * Invite creates a user, user can log in, deactivate blocks login, reactivate restores it.
     */
    public function testInviteDeactivateActivateLifecycle(): void
    {
        $email = 'test-lifecycle-' . bin2hex(random_bytes(4)) . '@test.test';
        $password = 'LifecycleTest123!';

        // Invite
        $res = self::postForm("/admin/tenants/" . self::$tenantId . "/users/invite", [
            'name'       => 'Lifecycle User',
            'email'      => $email,
            'role'       => 'owner',
            'password'   => $password,
            'send_email' => '0',
        ]);
        $this->assertRedirect($res, "/admin/tenants/" . self::$tenantId . "/users");

        // Verify in DB
        $users = Database::query(
            'SELECT `id`, `role`, `is_active`, `force_password_change` FROM `business_users` WHERE `email` = ? AND `tenant_id` = ?',
            [$email, self::$tenantId]
        );
        $this->assertCount(1, $users, 'Business user must be created');
        $userId = $users[0]['id'];
        self::$userCleanupIds[] = $userId;

        $this->assertSame('owner', $users[0]['role']);
        $this->assertEquals(1, $users[0]['is_active']);
        $this->assertEquals(1, $users[0]['force_password_change']);

        // User can log in
        $loginRes = self::loginAs($email, $password);
        $this->assertStringContainsString(
            "/admin/tenants/" . self::$tenantId,
            $loginRes['headers'],
            'New user must be redirected to their tenant'
        );

        // Re-login as operator
        self::operatorLogin();

        // Deactivate
        $deactRes = self::postForm("/admin/tenants/" . self::$tenantId . "/users/{$userId}/deactivate", []);
        $this->assertRedirect($deactRes, "/admin/tenants/" . self::$tenantId . "/users");

        $user = Database::query('SELECT `is_active` FROM `business_users` WHERE `id` = ?', [$userId]);
        $this->assertEquals(0, $user[0]['is_active'], 'User must be deactivated');

        // Deactivated user can't log in
        $loginRes = self::loginAs($email, $password);
        $this->assertStringNotContainsString(
            "/admin/tenants/" . self::$tenantId,
            $loginRes['headers'],
            'Deactivated user must not redirect to tenant'
        );

        // Re-login as operator, reactivate
        self::operatorLogin();
        $actRes = self::postForm("/admin/tenants/" . self::$tenantId . "/users/{$userId}/activate", []);
        $this->assertRedirect($actRes, "/admin/tenants/" . self::$tenantId . "/users");

        $user = Database::query('SELECT `is_active` FROM `business_users` WHERE `id` = ?', [$userId]);
        $this->assertEquals(1, $user[0]['is_active'], 'User must be reactivated');

        // Reactivated user can log in
        $loginRes = self::loginAs($email, $password);
        $this->assertStringContainsString(
            "/admin/tenants/" . self::$tenantId,
            $loginRes['headers'],
            'Reactivated user must be able to log in'
        );

        // Restore operator session for remaining tests
        self::operatorLogin();
    }

    /**
     * Validation: duplicate email, missing name.
     */
    public function testInviteValidation(): void
    {
        $email = 'test-val-' . bin2hex(random_bytes(4)) . '@test.test';

        // Create user
        $res = self::postForm("/admin/tenants/" . self::$tenantId . "/users/invite", [
            'name'       => 'Val User',
            'email'      => $email,
            'role'       => 'manager',
            'password'   => 'ValTest123!',
            'send_email' => '0',
        ]);
        $this->assertRedirect($res, "/admin/tenants/" . self::$tenantId . "/users");

        $users = Database::query('SELECT `id` FROM `business_users` WHERE `email` = ?', [$email]);
        $this->assertCount(1, $users);
        self::$userCleanupIds[] = $users[0]['id'];

        // Duplicate email
        $dupRes = self::postForm("/admin/tenants/" . self::$tenantId . "/users/invite", [
            'name'       => 'Dup User',
            'email'      => $email,
            'role'       => 'owner',
            'password'   => 'DupTest456!',
            'send_email' => '0',
        ]);
        $this->assertRedirect($dupRes, "/admin/tenants/" . self::$tenantId . "/users/invite");
        $count = Database::query('SELECT COUNT(*) as cnt FROM `business_users` WHERE `email` = ?', [$email]);
        $this->assertEquals(1, $count[0]['cnt'], 'Duplicate email must not create second user');

        // Missing name
        $noNameEmail = 'test-noname-' . bin2hex(random_bytes(4)) . '@test.test';
        $noNameRes = self::postForm("/admin/tenants/" . self::$tenantId . "/users/invite", [
            'name'       => '',
            'email'      => $noNameEmail,
            'role'       => 'manager',
            'password'   => 'NoName123!',
            'send_email' => '0',
        ]);
        $this->assertRedirect($noNameRes, "/admin/tenants/" . self::$tenantId . "/users/invite");
        $empty = Database::query('SELECT `id` FROM `business_users` WHERE `email` = ?', [$noNameEmail]);
        $this->assertEmpty($empty, 'Missing name must not create user');
    }

    /**
     * Manager cannot access user list (403).
     */
    public function testManagerCannotAccessUserList(): void
    {
        $email = 'test-mgr403-' . bin2hex(random_bytes(4)) . '@test.test';
        $password = 'MgrTest123!';

        // Create manager
        self::postForm("/admin/tenants/" . self::$tenantId . "/users/invite", [
            'name'       => 'Manager 403',
            'email'      => $email,
            'role'       => 'manager',
            'password'   => $password,
            'send_email' => '0',
        ]);

        $users = Database::query('SELECT `id` FROM `business_users` WHERE `email` = ?', [$email]);
        $this->assertNotEmpty($users);
        self::$userCleanupIds[] = $users[0]['id'];

        // Login as manager
        self::loginAs($email, $password);

        // Try user list — must be 403
        $res = self::httpGet("/admin/tenants/" . self::$tenantId . "/users");
        $this->assertSame(403, $res['code'], 'Manager must get 403 on user list');

        // Restore operator session
        self::operatorLogin();
    }

    // ════════════════════════════════════════════════════════════════
    // HTTP Helpers (static, shared session)
    // ════════════════════════════════════════════════════════════════

    private static function operatorLogin(): void
    {
        $loginPage = self::httpGet('/admin/login');
        self::extractCsrf($loginPage['body']);

        $loginRes = self::httpPost('/admin/login', [
            '_csrf_token' => self::$csrfToken,
            'email'       => 'operator@example.com',
            'password'    => 'welcome3210',
        ]);

        if (preg_match('/vb_session=([^;]+)/', $loginRes['headers'], $m)) {
            self::$sessionCookie = 'vb_session=' . $m[1];
        }

        $dashboard = self::httpGet('/admin');
        self::extractCsrf($dashboard['body']);
    }

    private static function loginAs(string $email, string $password): array
    {
        $loginPage = self::httpGet('/admin/login');
        self::extractCsrf($loginPage['body']);

        $res = self::httpPost('/admin/login', [
            '_csrf_token' => self::$csrfToken,
            'email'       => $email,
            'password'    => $password,
        ]);

        if (preg_match('/vb_session=([^;]+)/', $res['headers'], $m)) {
            self::$sessionCookie = 'vb_session=' . $m[1];
        }

        return $res;
    }

    private static function postForm(string $path, array $data): array
    {
        $page = self::httpGet($path);
        self::extractCsrf($page['body']);
        $data['_csrf_token'] = self::$csrfToken;

        return self::httpPost($path, $data);
    }

    private static function extractCsrf(string $html): void
    {
        if (preg_match('/name="_csrf_token"\s+value="([^"]+)"/', $html, $m)) {
            self::$csrfToken = $m[1];
        }
    }

    private function assertRedirect(array $response, string $expectedPath): void
    {
        $this->assertGreaterThanOrEqual(300, $response['code']);
        $this->assertLessThan(400, $response['code']);
        $this->assertStringContainsString(
            $expectedPath,
            $response['headers'],
            "Expected redirect to {$expectedPath}"
        );
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
        curl_close($ch);

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
