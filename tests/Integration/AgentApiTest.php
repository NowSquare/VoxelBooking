<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Engine\Database;
use App\Engine\EnvLoader;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for the Agent API.
 *
 * These tests hit the real HTTP endpoints via curl to verify:
 * - Public schema endpoint (no auth)
 * - Bearer token authentication (missing, invalid, valid)
 * - Scope enforcement (missing scope → 403)
 * - Happy-path reads (tenants, bookings, services, availability)
 * - Data minimization (internal_notes excluded, consent as boolean)
 *
 * Requires:
 * - The app running at APP_TEST_URL
 * - A valid API key in the database
 */
final class AgentApiTest extends TestCase
{
    private string $baseUrl;
    private static bool $appReachable = false;
    private static string $validKey = '';
    private static string $viewerKey = '';
    private static string $tenantId = '';

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

        // Bootstrap database and seed test API keys
        try {
            require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
            \Tests\Support\TestEnvironment::boot();
            Database::connect();
            Database::query('SELECT 1');

            // Clean up test keys
            Database::execute("DELETE FROM `api_keys` WHERE `name` LIKE 'test_%'");

            // Create agent-role test key
            self::$validKey = 'vb_test_agent_' . bin2hex(random_bytes(24));
            Database::execute(
                "INSERT INTO `api_keys` (`id`, `name`, `key_hash`, `key_prefix`, `scopes`, `role`, `is_active`)
                 VALUES ('test-agent-key-001', 'test_agent_key', ?, ?, ?, 'agent', 1)",
                [
                    hash('sha256', self::$validKey),
                    substr(self::$validKey, 0, 8),
                    json_encode([
                        'tenants:read', 'tenants:write',
                        'bookings:read', 'bookings:write',
                        'services:read', 'services:write',
                        'availability:read',
                        'customers:read', 'customers:write',
                        'settings:read', 'reports:read',
                    ]),
                ]
            );

            // Create viewer-role test key (read-only, but NO tenants:read to test scope denial)
            self::$viewerKey = 'vb_test_viewer_' . bin2hex(random_bytes(24));
            Database::execute(
                "INSERT INTO `api_keys` (`id`, `name`, `key_hash`, `key_prefix`, `scopes`, `role`, `is_active`)
                 VALUES ('test-viewer-key-001', 'test_viewer_key', ?, ?, ?, 'viewer', 1)",
                [
                    hash('sha256', self::$viewerKey),
                    substr(self::$viewerKey, 0, 8),
                    json_encode(['bookings:read', 'services:read']),  // Intentionally missing tenants:read
                ]
            );

            // Get a tenant ID for testing
            $tenants = Database::query("SELECT `id` FROM `tenants` LIMIT 1");
            self::$tenantId = $tenants[0]['id'] ?? '';

            // Clean rate limits for test keys
            Database::execute("DELETE FROM `rate_limits` WHERE `key` LIKE 'api:%'");
        } catch (\Throwable $e) {
            // best-effort — tests will fail clearly if DB is unavailable
        }
    }

    public static function tearDownAfterClass(): void
    {
        try {
            Database::execute("DELETE FROM `api_keys` WHERE `name` LIKE 'test_%'");
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

    // ════════════════════════════════════════════════════════════════
    // Schema Endpoint (public, no auth)
    // ════════════════════════════════════════════════════════════════

    public function testSchemaEndpointIsPublic(): void
    {
        $response = $this->apiGet('/api/agent/v1/schema');

        $this->assertSame(200, $response['code']);
        $this->assertJson($response['body']);

        $schema = json_decode($response['body'], true);
        $this->assertSame('3.0.3', $schema['openapi'] ?? '');
        $this->assertArrayHasKey('paths', $schema);
        $this->assertArrayHasKey('components', $schema);
    }

    public function testSchemaContainsExpectedPaths(): void
    {
        $response = $this->apiGet('/api/agent/v1/schema');
        $schema = json_decode($response['body'], true);

        $this->assertArrayHasKey('/tenants', $schema['paths']);
        $this->assertArrayHasKey('/bookings', $schema['paths']);
        $this->assertArrayHasKey('/services', $schema['paths']);
        $this->assertArrayHasKey('/availability', $schema['paths']);
    }

    public function testSchemaHasScopeAnnotations(): void
    {
        $response = $this->apiGet('/api/agent/v1/schema');
        $schema = json_decode($response['body'], true);

        $tenantsOp = $schema['paths']['/tenants']['get'] ?? [];
        $this->assertSame('tenants:read', $tenantsOp['x-required-scope'] ?? '');
    }

    public function testSchemaUsesConfigurableAppName(): void
    {
        // Set a known app_name
        try {
            Database::execute(
                "INSERT INTO `settings` (`key`, `value`, `updated_at`) VALUES ('app_name', 'TestBookingApp', NOW())
                 ON DUPLICATE KEY UPDATE `value` = 'TestBookingApp', `updated_at` = NOW()"
            );
        } catch (\Throwable) {
            $this->markTestSkipped('Cannot update settings');
        }

        $response = $this->apiGet('/api/agent/v1/schema');
        $schema = json_decode($response['body'], true);

        $title = $schema['info']['title'] ?? '';
        $this->assertSame('TestBookingApp Agent API', $title,
            'Schema title must include the configured app_name');

        // Restore original
        try {
            Database::execute(
                "UPDATE `settings` SET `value` = 'VoxelBooking' WHERE `key` = 'app_name'"
            );
        } catch (\Throwable) {
            // best-effort
        }
    }

    // ════════════════════════════════════════════════════════════════
    // Auth: Missing / Invalid Bearer Token
    // ════════════════════════════════════════════════════════════════

    public function testMissingBearerTokenReturns401(): void
    {
        $response = $this->apiGet('/api/agent/v1/tenants');

        $this->assertSame(401, $response['code']);
        $body = json_decode($response['body'], true);
        $this->assertSame('unauthorized', $body['error'] ?? '');
    }

    public function testInvalidBearerTokenReturns401(): void
    {
        $response = $this->apiGet('/api/agent/v1/tenants', 'Bearer invalid_token_123');

        $this->assertSame(401, $response['code']);
        $body = json_decode($response['body'], true);
        $this->assertSame('unauthorized', $body['error'] ?? '');
    }

    public function testEmptyBearerTokenReturns401(): void
    {
        $response = $this->apiGet('/api/agent/v1/tenants', 'Bearer ');

        $this->assertSame(401, $response['code']);
    }

    public function testBasicAuthSchemeReturns401(): void
    {
        $response = $this->apiGet('/api/agent/v1/tenants', 'Basic ' . base64_encode('user:pass'));

        $this->assertSame(401, $response['code']);
    }

    // ════════════════════════════════════════════════════════════════
    // Auth: Scope Denial
    // ════════════════════════════════════════════════════════════════

    public function testMissingScopeReturns403(): void
    {
        if (empty(self::$viewerKey)) {
            $this->markTestSkipped('Viewer key not seeded');
        }

        // Viewer key intentionally lacks tenants:read scope
        $response = $this->apiGet('/api/agent/v1/tenants', 'Bearer ' . self::$viewerKey);

        $this->assertSame(403, $response['code']);
        $body = json_decode($response['body'], true);
        $this->assertSame('forbidden', $body['error'] ?? '');
        $this->assertStringContainsString('tenants:read', $body['message'] ?? '');
    }

    // ════════════════════════════════════════════════════════════════
    // Happy-Path Reads
    // ════════════════════════════════════════════════════════════════

    public function testTenantsEndpointReturnsData(): void
    {
        if (empty(self::$validKey)) {
            $this->markTestSkipped('Agent key not seeded');
        }

        $response = $this->apiGet('/api/agent/v1/tenants', 'Bearer ' . self::$validKey);

        $this->assertSame(200, $response['code']);
        $body = json_decode($response['body'], true);
        $this->assertArrayHasKey('data', $body);
        $this->assertIsArray($body['data']);
    }

    public function testBookingsEndpointRequiresTenantId(): void
    {
        if (empty(self::$validKey)) {
            $this->markTestSkipped('Agent key not seeded');
        }

        // No tenant_id → 422
        $response = $this->apiGet('/api/agent/v1/bookings', 'Bearer ' . self::$validKey);

        $this->assertSame(422, $response['code']);
        $body = json_decode($response['body'], true);
        $this->assertSame('validation', $body['error'] ?? '');
    }

    public function testBookingsEndpointReturnsData(): void
    {
        if (empty(self::$validKey) || empty(self::$tenantId)) {
            $this->markTestSkipped('Test data not available');
        }

        $response = $this->apiGet(
            '/api/agent/v1/bookings?tenant_id=' . self::$tenantId,
            'Bearer ' . self::$validKey
        );

        $this->assertSame(200, $response['code']);
        $body = json_decode($response['body'], true);
        $this->assertArrayHasKey('data', $body);
        $this->assertArrayHasKey('total', $body);
        $this->assertIsArray($body['data']);
        $this->assertIsInt($body['total']);
    }

    public function testBookingsDataMinimization(): void
    {
        if (empty(self::$validKey) || empty(self::$tenantId)) {
            $this->markTestSkipped('Test data not available');
        }

        $response = $this->apiGet(
            '/api/agent/v1/bookings?tenant_id=' . self::$tenantId . '&limit=1',
            'Bearer ' . self::$validKey
        );

        $this->assertSame(200, $response['code']);
        $body = json_decode($response['body'], true);
        $this->assertArrayHasKey('data', $body);

        if (empty($body['data'])) {
            // No bookings — verify the structure is still correct
            $this->assertSame([], $body['data']);
            $this->assertSame(0, $body['total']);
            return;
        }

        $booking = $body['data'][0];

        // Data minimization: internal_notes must NOT be present
        $this->assertArrayNotHasKey('internal_notes', $booking,
            'internal_notes must be excluded from API response (data minimization)');

        // Consent as boolean, not raw timestamp
        $this->assertArrayHasKey('has_consent', $booking);
        $this->assertIsBool($booking['has_consent']);

        // Required fields present
        $this->assertArrayHasKey('id', $booking);
        $this->assertArrayHasKey('status', $booking);
        $this->assertArrayHasKey('start_datetime', $booking);
    }

    public function testServicesEndpointReturnsData(): void
    {
        if (empty(self::$validKey) || empty(self::$tenantId)) {
            $this->markTestSkipped('Test data not available');
        }

        $response = $this->apiGet(
            '/api/agent/v1/services?tenant_id=' . self::$tenantId,
            'Bearer ' . self::$validKey
        );

        $this->assertSame(200, $response['code']);
        $body = json_decode($response['body'], true);
        $this->assertArrayHasKey('data', $body);

        if (!empty($body['data'])) {
            $service = $body['data'][0];
            $this->assertArrayHasKey('id', $service);
            $this->assertArrayHasKey('name', $service);
            $this->assertArrayHasKey('is_active', $service);
            $this->assertIsBool($service['is_active']);
        }
    }

    public function testAvailabilityEndpointReturnsData(): void
    {
        if (empty(self::$validKey) || empty(self::$tenantId)) {
            $this->markTestSkipped('Test data not available');
        }

        $response = $this->apiGet(
            '/api/agent/v1/availability?tenant_id=' . self::$tenantId,
            'Bearer ' . self::$validKey
        );

        $this->assertSame(200, $response['code']);
        $body = json_decode($response['body'], true);
        $this->assertArrayHasKey('data', $body);
    }

    public function testBookingsPagination(): void
    {
        if (empty(self::$validKey) || empty(self::$tenantId)) {
            $this->markTestSkipped('Test data not available');
        }

        // Request with limit=2, offset=0
        $response = $this->apiGet(
            '/api/agent/v1/bookings?tenant_id=' . self::$tenantId . '&limit=2&offset=0',
            'Bearer ' . self::$validKey
        );

        $this->assertSame(200, $response['code']);
        $body = json_decode($response['body'], true);
        $this->assertLessThanOrEqual(2, count($body['data']));
    }

    // ════════════════════════════════════════════════════════════════
    // Response Format
    // ════════════════════════════════════════════════════════════════

    public function testApiResponsesAreJson(): void
    {
        // Even 401 should be JSON, not HTML redirects
        $response = $this->apiGet('/api/agent/v1/tenants');

        $this->assertSame(401, $response['code']);
        $this->assertJson($response['body']);
        $this->assertStringNotContainsString('<html', $response['body'],
            'API error responses must be JSON, not HTML');
    }

    // ── Helpers ──

    /**
     * @return array{code: int, body: string}
     */
    private function apiGet(string $path, string $authHeader = ''): array
    {
        $ch = curl_init($this->baseUrl . $path);
        $headers = ['Accept: application/json'];

        if ($authHeader !== '') {
            $headers[] = 'Authorization: ' . $authHeader;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 10,
        ]);

        $body = (string) curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);

        return ['code' => $code, 'body' => $body];
    }
}
