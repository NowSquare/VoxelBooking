<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Engine\Database;
use App\Engine\EnvLoader;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for the privacy and retention endpoints.
 *
 * Verifies:
 * - Privacy endpoint returns 404 for invalid tenant/customer
 * - Cron retention endpoint requires valid token
 * - Cron retention endpoint rejects requests without token
 *
 * Requires the app running at APP_TEST_URL.
 */
final class PrivacyEndpointTest extends TestCase
{
    private string $baseUrl;
    private static bool $appReachable = false;

    public static function setUpBeforeClass(): void
    {
        $baseUrl = rtrim($_ENV['APP_TEST_URL'] ?? 'https://voxelbooking-app.test', '/');

        $ch = curl_init($baseUrl . '/health');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_TIMEOUT => 5]);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);

        if ($code === 0) {
            return;
        }

        self::$appReachable = true;

        // Clear rate limit records for test IP
        try {
            require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
            \Tests\Support\TestEnvironment::boot();
            Database::connect();
            Database::query('SELECT 1');
            Database::execute('TRUNCATE TABLE `rate_limits`');
        } catch (\Throwable) {
            // best-effort
        }
    }

    protected function setUp(): void
    {
        $this->baseUrl = rtrim($_ENV['APP_TEST_URL'] ?? 'https://voxelbooking-app.test', '/');

        if (!self::$appReachable) {
            $this->markTestSkipped('App not reachable at ' . $this->baseUrl);
        }
    }

    /**
     * Privacy endpoint for non-existent tenant returns 404.
     */
    public function testPrivacyEndpointInvalidTenantReturns404(): void
    {
        $response = $this->request('GET', '/book/nonexistent-tenant/privacy/01ABCDEFGHJKLMNPQRSTWXYZ');
        $this->assertSame(404, $response['code']);
    }

    /**
     * Privacy endpoint for non-existent customer returns 404.
     */
    public function testPrivacyEndpointInvalidCustomerReturns404(): void
    {
        // Even if the tenant existed, a random customer ID should 404
        $response = $this->request('GET', '/book/test-salon/privacy/01ABCDEFGHJKLMNPQRSTWXYZ');
        $this->assertSame(404, $response['code']);
    }

    /**
     * Cron retention endpoint without token returns 403.
     */
    public function testRetentionCronRequiresToken(): void
    {
        $response = $this->request('GET', '/cron/retention');
        $this->assertSame(403, $response['code']);
    }

    /**
     * Cron retention endpoint with invalid token returns 403.
     */
    public function testRetentionCronRejectsInvalidToken(): void
    {
        $response = $this->request('GET', '/cron/retention?token=invalid-token-12345');
        $this->assertSame(403, $response['code']);
    }

    // ── Successful-path tests ──

    /**
     * Full privacy flow: GET returns page with customer data and CSRF token,
     * then POST export with CSRF token returns JSON download.
     */
    public function testPrivacyExportFlowWorks(): void
    {
        $ids = $this->seedTestData();
        if ($ids === null) {
            $this->markTestSkipped('Could not seed test data');
        }

        $cookieJar = tempnam(sys_get_temp_dir(), 'vb_csrf_');
        \Tests\Support\CustomerAccessTest::verifyBrowser($ids['tenant_id'], $ids['slug'], $ids['customer_id'], \App\Engine\CustomerCapability::PRIVACY, $cookieJar);

        // Step 1: GET the privacy page — should return 200 with customer data
        $getResponse = $this->request('GET', "/book/{$ids['slug']}/privacy/{$ids['customer_id']}", [], $cookieJar);
        $this->assertSame(200, $getResponse['code'], 'Privacy GET should return 200');
        $this->assertStringContainsString('test-privacy@example.com', $getResponse['body'], 'Page must show customer email');
        $this->assertStringContainsString('_csrf_token', $getResponse['body'], 'Page must contain CSRF token field');
        $this->assertDoesNotMatchRegularExpression('/\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', $getResponse['body'], 'Dates go through the Locale engine, never raw database values');

        // Extract CSRF token from form
        preg_match('/name="_csrf_token"\s+value="([^"]+)"/', $getResponse['body'], $matches);
        $this->assertNotEmpty($matches[1] ?? '', 'Must find CSRF token in form');
        $csrfToken = $matches[1];

        // Step 2: POST export with CSRF token — should return JSON download
        $postResponse = $this->request('POST', "/book/{$ids['slug']}/privacy/{$ids['customer_id']}", [
            '_csrf_token' => $csrfToken,
            'action'      => 'export',
        ], $cookieJar);
        $this->assertSame(200, $postResponse['code'], 'Export POST should return 200');

        $json = json_decode($postResponse['body'], true);
        $this->assertIsArray($json, 'Export body should be valid JSON');
        $this->assertSame('1.0', $json['export_version'] ?? null, 'Export must have version 1.0');
        $this->assertArrayHasKey('customer', $json, 'Export must have customer section');
        $this->assertArrayHasKey('bookings', $json, 'Export must have bookings section');

        // Cleanup
        @unlink($cookieJar);
        $this->cleanupTestData($ids);
    }

    /**
     * Deletion request flow: POST with CSRF returns deletion-requested page.
     */
    public function testPrivacyDeletionRequestFlowWorks(): void
    {
        $ids = $this->seedTestData();
        if ($ids === null) {
            $this->markTestSkipped('Could not seed test data');
        }

        $cookieJar = tempnam(sys_get_temp_dir(), 'vb_csrf_');
        \Tests\Support\CustomerAccessTest::verifyBrowser($ids['tenant_id'], $ids['slug'], $ids['customer_id'], \App\Engine\CustomerCapability::PRIVACY, $cookieJar);

        // Step 1: GET to establish session + CSRF
        $getResponse = $this->request('GET', "/book/{$ids['slug']}/privacy/{$ids['customer_id']}", [], $cookieJar);
        preg_match('/name="_csrf_token"\s+value="([^"]+)"/', $getResponse['body'], $matches);
        $csrfToken = $matches[1] ?? '';
        $this->assertNotEmpty($csrfToken);

        // Step 2: POST deletion request
        $postResponse = $this->request('POST', "/book/{$ids['slug']}/privacy/{$ids['customer_id']}", [
            '_csrf_token' => $csrfToken,
            'action'      => 'delete',
        ], $cookieJar);
        $this->assertSame(200, $postResponse['code'], 'Deletion POST should return 200');
        $this->assertStringContainsString('Deletion Requested', $postResponse['body'], 'Should show deletion confirmation');

        // Cleanup
        @unlink($cookieJar);
        $this->cleanupTestData($ids);
    }

    /**
     * POST without CSRF token is rejected.
     */
    public function testPrivacyPostWithoutCsrfIsRejected(): void
    {
        $ids = $this->seedTestData();
        if ($ids === null) {
            $this->markTestSkipped('Could not seed test data');
        }

        // POST directly without session/CSRF
        $response = $this->request('POST', "/book/{$ids['slug']}/privacy/{$ids['customer_id']}", [
            'action' => 'export',
        ]);
        $this->assertSame(403, $response['code'], 'POST without CSRF should be rejected');

        $this->cleanupTestData($ids);
    }

    // ── Helpers ──

    /**
     * @return array{code: int, body: string, headers: string}
     */
    private function request(string $method, string $path, array $postData = [], ?string $cookieJar = null): array
    {
        $ch = curl_init($this->baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADER         => true,
        ]);

        if ($cookieJar !== null) {
            curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
            curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
        }

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
        }

        $response = (string) curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        unset($ch);

        return [
            'code'    => $code,
            'headers' => substr($response, 0, $headerSize),
            'body'    => substr($response, $headerSize),
        ];
    }

    /**
     * Seed a test tenant and customer for happy-path tests.
     *
     * @return array{slug: string, tenant_id: string, customer_id: string}|null
     */
    private function seedTestData(): ?array
    {
        try {
            require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
            \Tests\Support\TestEnvironment::boot();
            \App\Engine\Database::connect();
            \App\Engine\Database::query('SELECT 1');
            $tenantId = \App\Engine\Ulid::generate();
            $customerId = \App\Engine\Ulid::generate();
            $slug = 'test-privacy-' . substr($tenantId, -6);

            \App\Engine\Database::execute(
                "INSERT INTO `tenants` (`id`, `slug`, `name`, `email`, `booking_pattern`) VALUES (?, ?, 'Privacy Test', 'test@example.com', 'timeslot')",
                [$tenantId, $slug]
            );

            \App\Engine\Database::execute(
                "INSERT INTO `customers` (`id`, `tenant_id`, `name`, `email`) VALUES (?, ?, 'Test User', 'test-privacy@example.com')",
                [$customerId, $tenantId]
            );

            return ['slug' => $slug, 'tenant_id' => $tenantId, 'customer_id' => $customerId];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Remove test data after test.
     */
    private function cleanupTestData(array $ids): void
    {
        try {
            \App\Engine\Database::execute("DELETE FROM `customers` WHERE `id` = ?", [$ids['customer_id']]);
            \App\Engine\Database::execute("DELETE FROM `tenants` WHERE `id` = ?", [$ids['tenant_id']]);
        } catch (\Throwable) {
            // Best-effort cleanup
        }
    }
}
