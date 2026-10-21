<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Engine\Database;
use App\Engine\EnvLoader;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for tenant slug validation in create and edit forms.
 *
 * Covers:
 * - Valid slug → tenant created successfully
 * - Invalid slug (uppercase, spaces, leading hyphens, etc.) → validation error, no tenant created
 * - Duplicate slug → validation error, no tenant created
 * - Valid slug edit → tenant updated
 * - Invalid slug edit → validation error, tenant unchanged
 * - Duplicate slug edit → validation error, tenant unchanged
 *
 * Requires: The app running at APP_TEST_URL (defaults to https://voxelbooking-app.test).
 */
final class TenantSlugValidationTest extends TestCase
{
    private string $baseUrl;
    private string $csrfToken = '';
    private string $sessionCookie = '';

    /** @var list<array{string, string}> */
    private array $cleanupIds = [];

    private static bool $appReachable = false;
    private static bool $dbReady = false;

    public static function setUpBeforeClass(): void
    {
        $baseUrl = rtrim($_ENV['APP_TEST_URL'] ?? 'https://voxelbooking-app.test', '/');

        $ch = curl_init($baseUrl . '/health');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT        => 5,
        ]);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
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
    }

    protected function setUp(): void
    {
        $this->baseUrl = rtrim($_ENV['APP_TEST_URL'] ?? 'https://voxelbooking-app.test', '/');

        if (!self::$appReachable) {
            $this->markTestSkipped('App not reachable at ' . $this->baseUrl);
        }
        if (!self::$dbReady) {
            $this->markTestSkipped('Database not available');
        }

        $this->operatorLogin();

        // Clear rate limits to prevent 429 false positives from rapid sequential POSTs
        try {
            Database::execute('TRUNCATE TABLE `rate_limits`');
        } catch (\Throwable) {
            // best-effort
        }
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->cleanupIds) as [$table, $id]) {
            try {
                Database::execute("DELETE FROM `{$table}` WHERE `id` = ?", [$id]);
            } catch (\Throwable) {
                // best-effort
            }
        }
    }

    // ════════════════════════════════════════════════════════════════
    // Create — valid slugs
    // ════════════════════════════════════════════════════════════════

    /**
     * A valid slug (lowercase, numbers, hyphens) creates the tenant successfully.
     */
    public function testCreateWithValidSlug(): void
    {
        $slug = 'test-valid-' . substr(bin2hex(random_bytes(4)), 0, 8);
        $res = $this->postForm('/admin/tenants/create', [
            'name'            => 'Valid Slug Tenant',
            'slug'            => $slug,
            'email'           => 'valid@test.test',
            'booking_pattern' => 'timeslot',
            'timezone'        => 'UTC',
            'currency'        => 'EUR',
            'brand_color'     => '#2563EB',
        ]);

        $this->assertRedirect($res, '/admin/tenants');

        $rows = Database::query('SELECT `id` FROM `tenants` WHERE `slug` = ?', [$slug]);
        $this->assertCount(1, $rows, 'Tenant with valid slug must be created');
        $this->cleanupIds[] = ['tenants', $rows[0]['id']];
    }

    /**
     * Empty slug auto-generates from name.
     */
    public function testCreateWithEmptySlugAutoGenerates(): void
    {
        $unique = substr(bin2hex(random_bytes(4)), 0, 8);
        $res = $this->postForm('/admin/tenants/create', [
            'name'            => 'Auto Slug ' . $unique,
            'slug'            => '', // empty — should auto-generate
            'email'           => 'auto@test.test',
            'booking_pattern' => 'timeslot',
            'timezone'        => 'UTC',
            'currency'        => 'EUR',
            'brand_color'     => '#2563EB',
        ]);

        $this->assertRedirect($res, '/admin/tenants');

        // The auto-generated slug should contain the unique suffix
        $rows = Database::query(
            'SELECT `id`, `slug` FROM `tenants` WHERE `slug` LIKE ?',
            ['auto-slug-' . $unique . '%']
        );
        $this->assertNotEmpty($rows, 'Tenant with auto-generated slug must be created');
        $this->cleanupIds[] = ['tenants', $rows[0]['id']];
    }

    // ════════════════════════════════════════════════════════════════
    // Create — invalid slugs
    // ════════════════════════════════════════════════════════════════

    /**
     * @param non-empty-string $invalidSlug
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidSlugProvider')]
    public function testCreateWithInvalidSlugRejectsAndShowsError(string $invalidSlug, string $description): void
    {
        $res = $this->postForm('/admin/tenants/create', [
            'name'            => 'Invalid Slug Test',
            'slug'            => $invalidSlug,
            'email'           => 'invalid@test.test',
            'booking_pattern' => 'timeslot',
            'timezone'        => 'UTC',
            'currency'        => 'EUR',
            'brand_color'     => '#2563EB',
        ]);

        // Should redirect back to create form, not to tenant list
        $this->assertRedirect($res, '/admin/tenants/create');

        // Follow redirect to inspect the error
        $page = $this->request('GET', '/admin/tenants/create');
        $body = $page['body'];

        // The slug field must be marked invalid
        $this->assertMatchesRegularExpression(
            '/id="tenant_slug"[^>]*class="[^"]*is-invalid/',
            $body,
            "Slug field must be marked invalid for: {$description}"
        );

        // The slug validation error message must appear
        $this->assertStringContainsString(
            'lowercase letters, numbers, and hyphens',
            $body,
            "Slug invalid error must appear for: {$description}"
        );

        // Verify no tenant was created
        $rows = Database::query('SELECT `id` FROM `tenants` WHERE `name` = ?', ['Invalid Slug Test']);
        $this->assertEmpty($rows, "No tenant should be created for invalid slug: {$description}");
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function invalidSlugProvider(): array
    {
        return [
            'uppercase'           => ['SalonBella', 'uppercase letters'],
            'spaces'              => ['salon bella', 'spaces'],
            'underscore'          => ['salon_bella', 'underscores'],
            'leading hyphen'      => ['-salon', 'leading hyphen'],
            'trailing hyphen'     => ['salon-', 'trailing hyphen'],
            'consecutive hyphens' => ['salon--bella', 'consecutive hyphens'],
            'path traversal'      => ['../etc', 'path traversal'],
        ];
    }

    // ════════════════════════════════════════════════════════════════
    // Create — duplicate slug
    // ════════════════════════════════════════════════════════════════

    public function testCreateWithDuplicateSlugRejects(): void
    {
        $slug = 'test-dup-slug-' . substr(bin2hex(random_bytes(4)), 0, 8);

        // Create the first tenant
        $this->postForm('/admin/tenants/create', [
            'name'            => 'First Dup Test',
            'slug'            => $slug,
            'email'           => 'dup1@test.test',
            'booking_pattern' => 'timeslot',
            'timezone'        => 'UTC',
            'currency'        => 'EUR',
            'brand_color'     => '#2563EB',
        ]);

        $tenants = Database::query('SELECT `id` FROM `tenants` WHERE `slug` = ?', [$slug]);
        $this->assertNotEmpty($tenants, 'First tenant must be created');
        $this->cleanupIds[] = ['tenants', $tenants[0]['id']];

        // Try creating a second tenant with the same slug
        $res = $this->postForm('/admin/tenants/create', [
            'name'            => 'Second Dup Test',
            'slug'            => $slug,
            'email'           => 'dup2@test.test',
            'booking_pattern' => 'timeslot',
            'timezone'        => 'UTC',
            'currency'        => 'EUR',
            'brand_color'     => '#2563EB',
        ]);

        $this->assertRedirect($res, '/admin/tenants/create');

        // Follow redirect to inspect
        $page = $this->request('GET', '/admin/tenants/create');
        $body = $page['body'];

        // Slug field must be marked invalid
        $this->assertMatchesRegularExpression(
            '/id="tenant_slug"[^>]*class="[^"]*is-invalid/',
            $body,
            'Slug field must be marked invalid for duplicate slug'
        );

        // The duplicate slug error must appear
        $this->assertStringContainsString(
            'already in use',
            $body,
            'Slug taken error must appear'
        );

        // Old input must be preserved
        $this->assertStringContainsString('Second Dup Test', $body,
            'Tenant name must be preserved on duplicate slug redirect');

        // No second tenant
        $count = Database::query('SELECT COUNT(*) AS c FROM `tenants` WHERE `slug` = ?', [$slug]);
        $this->assertSame(1, (int) $count[0]['c'], 'Only one tenant should exist with this slug');
    }

    // ════════════════════════════════════════════════════════════════
    // Edit — valid slug
    // ════════════════════════════════════════════════════════════════

    public function testEditWithValidSlugUpdates(): void
    {
        // Create a tenant to edit
        $slug = 'test-edit-ok-' . substr(bin2hex(random_bytes(4)), 0, 8);
        $this->postForm('/admin/tenants/create', [
            'name'            => 'Edit OK Tenant',
            'slug'            => $slug,
            'email'           => 'editok@test.test',
            'booking_pattern' => 'timeslot',
            'timezone'        => 'UTC',
            'currency'        => 'EUR',
            'brand_color'     => '#2563EB',
        ]);

        $rows = Database::query('SELECT `id` FROM `tenants` WHERE `slug` = ?', [$slug]);
        $this->assertNotEmpty($rows);
        $tenantId = $rows[0]['id'];
        $this->cleanupIds[] = ['tenants', $tenantId];

        $newSlug = 'test-edited-' . substr(bin2hex(random_bytes(4)), 0, 8);
        $res = $this->postForm("/admin/tenants/{$tenantId}/edit", [
            'name'        => 'Edited Tenant',
            'slug'        => $newSlug,
            'email'       => 'editok@test.test',
            'timezone'    => 'UTC',
            'currency'    => 'EUR',
            'brand_color' => '#2563EB',
        ]);

        // Should redirect to the edit page or tenant list
        $this->assertGreaterThanOrEqual(300, $res['code']);
        $this->assertLessThan(400, $res['code']);

        // Verify slug was updated
        $tenant = Database::query('SELECT `slug` FROM `tenants` WHERE `id` = ?', [$tenantId]);
        $this->assertSame($newSlug, $tenant[0]['slug'], 'Slug must be updated after valid edit');
    }

    // ════════════════════════════════════════════════════════════════
    // Edit — invalid slug
    // ════════════════════════════════════════════════════════════════

    public function testEditWithInvalidSlugRejects(): void
    {
        // Create a tenant to edit
        $slug = 'test-edit-bad-' . substr(bin2hex(random_bytes(4)), 0, 8);
        $this->postForm('/admin/tenants/create', [
            'name'            => 'Edit Bad Slug',
            'slug'            => $slug,
            'email'           => 'editbad@test.test',
            'booking_pattern' => 'timeslot',
            'timezone'        => 'UTC',
            'currency'        => 'EUR',
            'brand_color'     => '#2563EB',
        ]);

        $rows = Database::query('SELECT `id` FROM `tenants` WHERE `slug` = ?', [$slug]);
        $this->assertNotEmpty($rows);
        $tenantId = $rows[0]['id'];
        $this->cleanupIds[] = ['tenants', $tenantId];

        // Try editing with an invalid slug
        $res = $this->postForm("/admin/tenants/{$tenantId}/edit", [
            'name'        => 'Edit Bad Slug',
            'slug'        => 'INVALID_Slug!!',
            'email'       => 'editbad@test.test',
            'timezone'    => 'UTC',
            'currency'    => 'EUR',
            'brand_color' => '#2563EB',
        ]);

        // Should redirect back to edit form
        $this->assertRedirect($res, "/admin/tenants/{$tenantId}/edit");

        // Verify slug was NOT changed
        $tenant = Database::query('SELECT `slug` FROM `tenants` WHERE `id` = ?', [$tenantId]);
        $this->assertSame($slug, $tenant[0]['slug'], 'Slug must NOT change after invalid edit');
    }

    // ════════════════════════════════════════════════════════════════
    // Edit — duplicate slug
    // ════════════════════════════════════════════════════════════════

    public function testEditWithDuplicateSlugRejects(): void
    {
        // Create two tenants
        $slug1 = 'test-editdup1-' . substr(bin2hex(random_bytes(4)), 0, 8);
        $slug2 = 'test-editdup2-' . substr(bin2hex(random_bytes(4)), 0, 8);

        $this->postForm('/admin/tenants/create', [
            'name' => 'Edit Dup 1', 'slug' => $slug1, 'email' => 'ed1@test.test',
            'booking_pattern' => 'timeslot', 'timezone' => 'UTC', 'currency' => 'EUR', 'brand_color' => '#2563EB',
        ]);
        $this->postForm('/admin/tenants/create', [
            'name' => 'Edit Dup 2', 'slug' => $slug2, 'email' => 'ed2@test.test',
            'booking_pattern' => 'timeslot', 'timezone' => 'UTC', 'currency' => 'EUR', 'brand_color' => '#2563EB',
        ]);

        $rows1 = Database::query('SELECT `id` FROM `tenants` WHERE `slug` = ?', [$slug1]);
        $rows2 = Database::query('SELECT `id` FROM `tenants` WHERE `slug` = ?', [$slug2]);
        $this->assertNotEmpty($rows1);
        $this->assertNotEmpty($rows2);
        $this->cleanupIds[] = ['tenants', $rows1[0]['id']];
        $this->cleanupIds[] = ['tenants', $rows2[0]['id']];

        // Try editing tenant 2 to use tenant 1's slug
        $res = $this->postForm("/admin/tenants/{$rows2[0]['id']}/edit", [
            'name'        => 'Edit Dup 2',
            'slug'        => $slug1, // duplicate
            'email'       => 'ed2@test.test',
            'timezone'    => 'UTC',
            'currency'    => 'EUR',
            'brand_color' => '#2563EB',
        ]);

        // Should redirect back to edit form
        $this->assertRedirect($res, "/admin/tenants/{$rows2[0]['id']}/edit");

        // Verify slug was NOT changed
        $tenant = Database::query('SELECT `slug` FROM `tenants` WHERE `id` = ?', [$rows2[0]['id']]);
        $this->assertSame($slug2, $tenant[0]['slug'], 'Slug must NOT change to a duplicate');
    }

    // ════════════════════════════════════════════════════════════════
    // Helpers
    // ════════════════════════════════════════════════════════════════

    private function operatorLogin(): void
    {
        $loginPage = $this->request('GET', '/admin/login');
        $this->extractCsrf($loginPage['body']);

        $loginRes = $this->request('POST', '/admin/login', [
            '_csrf_token' => $this->csrfToken,
            'email'       => 'operator@example.com',
            'password'    => 'welcome3210',
        ]);

        if (preg_match('/vb_session=([^;]+)/', $loginRes['headers'], $m)) {
            $this->sessionCookie = 'vb_session=' . $m[1];
        }

        $dashboard = $this->request('GET', '/admin');
        $this->extractCsrf($dashboard['body']);
    }

    private function postForm(string $path, array $data): array
    {
        $page = $this->request('GET', $path);
        $this->extractCsrf($page['body']);
        $data['_csrf_token'] = $this->csrfToken;

        return $this->request('POST', $path, $data);
    }

    private function extractCsrf(string $html): void
    {
        if (preg_match('/name="_csrf_token"\s+value="([^"]+)"/', $html, $m)) {
            $this->csrfToken = $m[1];
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

    /**
     * @return array{code: int, headers: string, body: string}
     */
    private function request(string $method, string $path, array $data = []): array
    {
        $ch = curl_init($this->baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADER         => true,
        ]);

        $headers = [];
        if ($this->sessionCookie) {
            $headers[] = 'Cookie: ' . $this->sessionCookie;
        }
        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            $data = TestFixtures::withCatalogVersion($path, $data, $this->sessionCookie);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        }

        $response = (string) curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        unset($ch);

        $responseHeaders = substr($response, 0, $headerSize);

        if (preg_match('/vb_session=([^;]+)/', $responseHeaders, $m)) {
            $this->sessionCookie = 'vb_session=' . $m[1];
        }

        return [
            'code'    => $code,
            'headers' => $responseHeaders,
            'body'    => substr($response, $headerSize),
        ];
    }
}
