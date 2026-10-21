<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Engine\Database;
use App\Engine\EnvLoader;
use App\Engine\Ulid;
use PHPUnit\Framework\TestCase;

/**
 * Focused integration tests for:
 * 1. Reorder boundary: active/inactive group isolation for services, staff, and resources.
 * 2. Service cover-image upload: create/edit must not commit partial state on upload failure.
 *
 * Uses real HTTP against the running app at APP_TEST_URL.
 */
final class ReorderBoundaryTest extends TestCase
{
    private static string $baseUrl;
    private static bool $appReachable = false;
    private static bool $dbReady = false;
    private static string $setupError = '';

    private static string $tenantId = '';
    private static string $operatorCookie = '';
    private static string $operatorCsrf = '';

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
            self::$setupError = 'App not reachable at ' . self::$baseUrl;
            return;
        }

        self::$appReachable = true;

        try {
            require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
            \Tests\Support\TestEnvironment::boot();
            Database::connect();
            Database::query('SELECT 1');
            self::$dbReady = true;
        } catch (\Throwable $e) {
            self::$setupError = 'DB init failed: ' . $e->getMessage();
            return;
        }

        try {
            Database::execute('TRUNCATE TABLE `rate_limits`');
        } catch (\Throwable) {
        }

        try {
            TestFixtures::provision();
            self::seedTenant();
            self::loginOperator();
        } catch (\Throwable $e) {
            self::$setupError = 'Provisioning failed: ' . $e->getMessage();
        }
    }

    protected function setUp(): void
    {
        if (!self::$appReachable) {
            $this->markTestSkipped(self::$setupError ?: 'App not reachable');
        }
        if (!self::$dbReady || self::$tenantId === '') {
            $this->markTestSkipped(self::$setupError ?: 'DB or tenant not ready');
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$tenantId === '') {
            return;
        }

        try {
            Database::execute('DELETE FROM `service_staff` WHERE `service_id` IN (SELECT `id` FROM `services` WHERE `tenant_id` = ?)', [self::$tenantId]);
            Database::execute('DELETE FROM `services` WHERE `tenant_id` = ?', [self::$tenantId]);
            Database::execute('DELETE FROM `staff` WHERE `tenant_id` = ?', [self::$tenantId]);
            Database::execute('DELETE FROM `resources` WHERE `tenant_id` = ?', [self::$tenantId]);
            Database::execute('DELETE FROM `tenants` WHERE `id` = ?', [self::$tenantId]);
        } catch (\Throwable) {
        }
    }

    // ════════════════════════════════════════════════════════════════
    // Reorder: services at active/inactive boundary
    // ════════════════════════════════════════════════════════════════

    public function testServiceReorderDoesNotCrossActiveInactiveBoundary(): void
    {
        // Seed: 2 active services (sort_order 0, 1), 2 inactive services (sort_order 2, 3)
        $activeA = Ulid::generate();
        $activeB = Ulid::generate();
        $inactiveC = Ulid::generate();
        $inactiveD = Ulid::generate();

        Database::execute(
            "INSERT INTO `services` (`id`, `tenant_id`, `name`, `duration_minutes`, `is_active`, `sort_order`) VALUES (?, ?, 'Active A', 30, 1, 0)",
            [$activeA, self::$tenantId]
        );
        Database::execute(
            "INSERT INTO `services` (`id`, `tenant_id`, `name`, `duration_minutes`, `is_active`, `sort_order`) VALUES (?, ?, 'Active B', 30, 1, 1)",
            [$activeB, self::$tenantId]
        );
        Database::execute(
            "INSERT INTO `services` (`id`, `tenant_id`, `name`, `duration_minutes`, `is_active`, `sort_order`) VALUES (?, ?, 'Inactive C', 30, 0, 2)",
            [$inactiveC, self::$tenantId]
        );
        Database::execute(
            "INSERT INTO `services` (`id`, `tenant_id`, `name`, `duration_minutes`, `is_active`, `sort_order`) VALUES (?, ?, 'Inactive D', 30, 0, 3)",
            [$inactiveD, self::$tenantId]
        );

        // Get a fresh CSRF
        $page = self::httpGet("/admin/tenants/" . self::$tenantId . "/services", 'operator');
        self::extractCsrf($page['body']);

        // Try to move last active row (B) DOWN — should NOT swap with inactive C
        $res = self::httpPost("/admin/tenants/" . self::$tenantId . "/services/{$activeB}/reorder", [
            '_csrf_token' => self::$operatorCsrf,
            'direction'   => 'down',
        ], 'operator');

        $this->assertSame(302, $res['code']);

        // Verify: Active B should still have sort_order=1 (no swap occurred)
        $rowB = Database::query('SELECT `sort_order` FROM `services` WHERE `id` = ?', [$activeB]);
        $this->assertSame(1, (int) $rowB[0]['sort_order'], 'Last active service must not swap down into inactive group');

        // Verify: Inactive C should still have sort_order=2
        $rowC = Database::query('SELECT `sort_order` FROM `services` WHERE `id` = ?', [$inactiveC]);
        $this->assertSame(2, (int) $rowC[0]['sort_order'], 'First inactive service must not be affected by active reorder');

        // Now try to move first inactive row (C) UP — should NOT swap with active B
        $page2 = self::httpGet("/admin/tenants/" . self::$tenantId . "/services", 'operator');
        self::extractCsrf($page2['body']);

        $res2 = self::httpPost("/admin/tenants/" . self::$tenantId . "/services/{$inactiveC}/reorder", [
            '_csrf_token' => self::$operatorCsrf,
            'direction'   => 'up',
        ], 'operator');

        $this->assertSame(302, $res2['code']);

        // After normalization, the inactive group gets sequential sort_order 0,1 (not 2,3).
        // The key invariant: C is still FIRST in its inactive group (no swap with active).
        $rowC2 = Database::query('SELECT `sort_order` FROM `services` WHERE `id` = ?', [$inactiveC]);
        $rowD2 = Database::query('SELECT `sort_order` FROM `services` WHERE `id` = ?', [$inactiveD]);
        $this->assertTrue(
            (int) $rowC2[0]['sort_order'] < (int) $rowD2[0]['sort_order'],
            'First inactive service must remain before second inactive after failed up-swap'
        );

        // Verify valid within-group swap still works: move Active A down
        $page3 = self::httpGet("/admin/tenants/" . self::$tenantId . "/services", 'operator');
        self::extractCsrf($page3['body']);

        $res3 = self::httpPost("/admin/tenants/" . self::$tenantId . "/services/{$activeA}/reorder", [
            '_csrf_token' => self::$operatorCsrf,
            'direction'   => 'down',
        ], 'operator');

        $this->assertSame(302, $res3['code']);

        $rowA = Database::query('SELECT `sort_order` FROM `services` WHERE `id` = ?', [$activeA]);
        $rowBAfter = Database::query('SELECT `sort_order` FROM `services` WHERE `id` = ?', [$activeB]);
        $this->assertSame(1, (int) $rowA[0]['sort_order'], 'Active A must have moved to sort_order 1');
        $this->assertSame(0, (int) $rowBAfter[0]['sort_order'], 'Active B must have moved to sort_order 0');

        // Cleanup
        Database::execute('DELETE FROM `services` WHERE `id` IN (?, ?, ?, ?)', [$activeA, $activeB, $inactiveC, $inactiveD]);
    }

    // ════════════════════════════════════════════════════════════════
    // Reorder: staff at active/inactive boundary
    // ════════════════════════════════════════════════════════════════

    public function testStaffReorderDoesNotCrossActiveInactiveBoundary(): void
    {
        $activeA = Ulid::generate();
        $inactiveB = Ulid::generate();

        Database::execute(
            "INSERT INTO `staff` (`id`, `tenant_id`, `name`, `email`, `is_active`, `sort_order`) VALUES (?, ?, 'Staff Active', 'reorder-a@test.test', 1, 0)",
            [$activeA, self::$tenantId]
        );
        Database::execute(
            "INSERT INTO `staff` (`id`, `tenant_id`, `name`, `email`, `is_active`, `sort_order`) VALUES (?, ?, 'Staff Inactive', 'reorder-b@test.test', 0, 1)",
            [$inactiveB, self::$tenantId]
        );

        $page = self::httpGet("/admin/tenants/" . self::$tenantId . "/staff", 'operator');
        self::extractCsrf($page['body']);

        // Try to move active staff DOWN — should not find inactive sibling
        $res = self::httpPost("/admin/tenants/" . self::$tenantId . "/staff/{$activeA}/reorder", [
            '_csrf_token' => self::$operatorCsrf,
            'direction'   => 'down',
        ], 'operator');

        $this->assertSame(302, $res['code']);

        $rowA = Database::query('SELECT `sort_order` FROM `staff` WHERE `id` = ?', [$activeA]);
        $this->assertSame(0, (int) $rowA[0]['sort_order'], 'Only active staff must not have its sort_order changed');

        $rowB = Database::query('SELECT `sort_order` FROM `staff` WHERE `id` = ?', [$inactiveB]);
        $this->assertSame(1, (int) $rowB[0]['sort_order'], 'Inactive staff must not be affected');

        // Cleanup
        Database::execute('DELETE FROM `staff` WHERE `id` IN (?, ?)', [$activeA, $inactiveB]);
    }

    // ════════════════════════════════════════════════════════════════
    // Reorder: resources at active/inactive boundary
    // ════════════════════════════════════════════════════════════════

    public function testResourceReorderDoesNotCrossActiveInactiveBoundary(): void
    {
        $activeA = Ulid::generate();
        $inactiveB = Ulid::generate();

        Database::execute(
            "INSERT INTO `resources` (`id`, `tenant_id`, `name`, `capacity`, `min_stay_nights`, `max_stay_nights`, `is_active`, `sort_order`) VALUES (?, ?, 'Room Active', 2, 1, 7, 1, 0)",
            [$activeA, self::$tenantId]
        );
        Database::execute(
            "INSERT INTO `resources` (`id`, `tenant_id`, `name`, `capacity`, `min_stay_nights`, `max_stay_nights`, `is_active`, `sort_order`) VALUES (?, ?, 'Room Inactive', 2, 1, 7, 0, 1)",
            [$inactiveB, self::$tenantId]
        );

        $page = self::httpGet("/admin/tenants/" . self::$tenantId . "/resources", 'operator');
        self::extractCsrf($page['body']);

        $res = self::httpPost("/admin/tenants/" . self::$tenantId . "/resources/{$activeA}/reorder", [
            '_csrf_token' => self::$operatorCsrf,
            'direction'   => 'down',
        ], 'operator');

        $this->assertSame(302, $res['code']);

        $rowA = Database::query('SELECT `sort_order` FROM `resources` WHERE `id` = ?', [$activeA]);
        $this->assertSame(0, (int) $rowA[0]['sort_order'], 'Only active resource must not have its sort_order changed');

        $rowB = Database::query('SELECT `sort_order` FROM `resources` WHERE `id` = ?', [$inactiveB]);
        $this->assertSame(1, (int) $rowB[0]['sort_order'], 'Inactive resource must not be affected');

        // Cleanup
        Database::execute('DELETE FROM `resources` WHERE `id` IN (?, ?)', [$activeA, $inactiveB]);
    }

    // ════════════════════════════════════════════════════════════════
    // Service cover upload: create must not leave orphan on failure
    // ════════════════════════════════════════════════════════════════

    public function testServiceCreateWithValidImageUsesNativeMediaPermission(): void
    {
        $page = self::httpGet('/admin/tenants/' . self::$tenantId . '/services/create', 'operator');
        self::extractCsrf($page['body']);
        $tmpFile = tempnam(sys_get_temp_dir(), 'vb_image_');
        $image = imagecreatetruecolor(2, 2);
        imagepng($image, $tmpFile);
        $name = 'Native upload ' . Ulid::generate();
        $storedPath = null;
        try {
            $response = self::httpMultipart('/admin/tenants/' . self::$tenantId . '/services', [
                '_csrf_token' => self::$operatorCsrf,
                'name' => $name,
                'duration_minutes' => '30',
                'cover_image' => new \CURLFile($tmpFile, 'image/png', 'cover.png'),
            ]);
            $this->assertSame(302, $response['code']);
            $this->assertMatchesRegularExpression(
                '#Location: [^\r\n]*/admin/tenants/' . self::$tenantId . '/services\r?\n#i',
                $response['headers'],
            );
            $row = Database::query('SELECT cover_image_path FROM services WHERE tenant_id = ? AND name = ?', [self::$tenantId, $name])[0] ?? null;
            $this->assertNotNull($row, 'The native form must commit the service with its uploaded cover.');
            $this->assertNotEmpty($row['cover_image_path']);
            $storedPath = dirname(__DIR__, 2) . '/public/' . $row['cover_image_path'];
            $this->assertFileExists($storedPath);
            $this->assertSame('image/png', getimagesize($storedPath)['mime']);
        } finally {
            @unlink($tmpFile);
            if ($storedPath !== null) {
                @unlink($storedPath);
            }
            Database::execute('DELETE FROM services WHERE tenant_id = ? AND name = ?', [self::$tenantId, $name]);
        }
    }

    public function testServiceCreateWithInvalidImageDoesNotCreateService(): void
    {
        $page = self::httpGet("/admin/tenants/" . self::$tenantId . "/services/create", 'operator');
        self::extractCsrf($page['body']);

        // Simulate a multipart POST with an invalid file (non-image)
        // We use cURL's CURLFile to send a file with a .txt extension
        $tmpFile = tempnam(sys_get_temp_dir(), 'vb_test_') . '.txt';
        file_put_contents($tmpFile, 'This is not an image');

        $res = self::httpMultipart("/admin/tenants/" . self::$tenantId . "/services", [
            '_csrf_token'      => self::$operatorCsrf,
            'name'             => 'Upload Fail Create Test',
            'duration_minutes' => '30',
            'cover_image'      => new \CURLFile($tmpFile, 'text/plain', 'bad.txt'),
        ]);

        @unlink($tmpFile);

        // Should redirect back to create form (302 to /create), NOT to index
        $this->assertSame(302, $res['code'], 'Invalid upload must redirect');
        $this->assertStringContainsString('/create', $res['headers'], 'Must redirect back to create form, not proceed');

        // No service must be created
        $rows = Database::query(
            "SELECT COUNT(*) as cnt FROM `services` WHERE `tenant_id` = ? AND `name` = 'Upload Fail Create Test'",
            [self::$tenantId]
        );
        $this->assertSame(0, (int) $rows[0]['cnt'], 'Service must not be created when upload fails');
    }

    public function testServiceEditWithInvalidImageDoesNotUpdateService(): void
    {
        // Seed a service
        $svcId = Ulid::generate();
        Database::execute(
            "INSERT INTO `services` (`id`, `tenant_id`, `name`, `duration_minutes`, `is_active`, `sort_order`) VALUES (?, ?, 'Pre-Edit Name', 30, 1, 0)",
            [$svcId, self::$tenantId]
        );

        $page = self::httpGet("/admin/tenants/" . self::$tenantId . "/services/{$svcId}/edit", 'operator');
        self::extractCsrf($page['body']);

        $tmpFile = tempnam(sys_get_temp_dir(), 'vb_test_') . '.txt';
        file_put_contents($tmpFile, 'This is not an image');

        $res = self::httpMultipart("/admin/tenants/" . self::$tenantId . "/services/{$svcId}", [
            '_csrf_token'      => self::$operatorCsrf,
            'name'             => 'Post-Edit Name',
            'duration_minutes' => '60',
            'cover_image'      => new \CURLFile($tmpFile, 'text/plain', 'bad.txt'),
        ]);

        @unlink($tmpFile);

        // Should redirect back to edit form
        $this->assertSame(302, $res['code'], 'Invalid upload on edit must redirect');
        $this->assertStringContainsString('/edit', $res['headers'], 'Must redirect back to edit form');

        // Service must remain unchanged
        $row = Database::query('SELECT `name`, `duration_minutes` FROM `services` WHERE `id` = ?', [$svcId]);
        $this->assertSame('Pre-Edit Name', $row[0]['name'], 'Service name must not change on upload failure');
        $this->assertSame(30, (int) $row[0]['duration_minutes'], 'Duration must not change on upload failure');

        // Cleanup
        Database::execute('DELETE FROM `services` WHERE `id` = ?', [$svcId]);
    }

    // ════════════════════════════════════════════════════════════════
    // Seed + auth helpers
    // ════════════════════════════════════════════════════════════════

    private static function seedTenant(): void
    {
        self::$tenantId = Ulid::generate();

        Database::execute(
            "INSERT INTO `tenants`
             (`id`, `slug`, `name`, `email`, `booking_pattern`, `status`, `brand_color`, `timezone`, `currency`)
             VALUES (?, ?, 'Reorder Test Tenant', 'reorder@test.test', 'timeslot', 'active', '#6366F1', 'UTC', 'EUR')",
            [self::$tenantId, 'test-reorder-' . substr(self::$tenantId, -8)]
        );
    }

    private static function loginOperator(): void
    {
        $loginPage = self::httpGet('/admin/login', 'operator');
        self::extractCsrf($loginPage['body']);

        $res = self::httpPost('/admin/login', [
            '_csrf_token' => self::$operatorCsrf,
            'email'       => TestFixtures::OPERATOR_EMAIL,
            'password'    => TestFixtures::OPERATOR_PASSWORD,
        ], 'operator');

        if (preg_match('/vb_session=([^;]+)/', $res['headers'], $m)) {
            self::$operatorCookie = 'vb_session=' . $m[1];
        }

        $dashboard = self::httpGet('/admin', 'operator');
        self::extractCsrf($dashboard['body']);
    }

    private static function extractCsrf(string $html): void
    {
        if (preg_match('/name="_csrf_token"\s+value="([^"]+)"/', $html, $m)) {
            self::$operatorCsrf = $m[1];
        }
    }

    // ════════════════════════════════════════════════════════════════
    // HTTP helpers
    // ════════════════════════════════════════════════════════════════

    /** @return array{code: int, headers: string, body: string} */
    private static function httpGet(string $path, string $role = 'operator'): array
    {
        return self::http('GET', $path, []);
    }

    /** @return array{code: int, headers: string, body: string} */
    private static function httpPost(string $path, array $data, string $role = 'operator'): array
    {
        return self::http('POST', $path, $data);
    }

    /** @return array{code: int, headers: string, body: string} */
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

        if (self::$operatorCookie) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Cookie: ' . self::$operatorCookie]);
        }

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            $data = TestFixtures::withCatalogVersion($path, $data, self::$operatorCookie);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        }

        $response = (string) curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        unset($ch);

        $responseHeaders = substr($response, 0, $headerSize);

        if (preg_match('/vb_session=([^;]+)/', $responseHeaders, $m)) {
            self::$operatorCookie = 'vb_session=' . $m[1];
        }

        return [
            'code'    => $code,
            'headers' => $responseHeaders,
            'body'    => substr($response, $headerSize),
        ];
    }

    /**
     * Multipart POST (for file uploads via CURLFile).
     * @return array{code: int, headers: string, body: string}
     */
    private static function httpMultipart(string $path, array $data): array
    {
        $data = TestFixtures::withCatalogVersion($path, $data, self::$operatorCookie);
        $ch = curl_init(self::$baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADER         => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $data, // cURL auto-encodes as multipart when array contains CURLFile
        ]);

        if (self::$operatorCookie) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Cookie: ' . self::$operatorCookie]);
        }

        $response = (string) curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        unset($ch);

        $responseHeaders = substr($response, 0, $headerSize);

        if (preg_match('/vb_session=([^;]+)/', $responseHeaders, $m)) {
            self::$operatorCookie = 'vb_session=' . $m[1];
        }

        return [
            'code'    => $code,
            'headers' => $responseHeaders,
            'body'    => substr($response, $headerSize),
        ];
    }
}
