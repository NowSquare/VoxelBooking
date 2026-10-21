<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Integration tests for SEO endpoints (robots.txt, sitemap.xml).
 *
 * Verifies:
 * - Both endpoints return 200 (served via router, not static files)
 * - robots.txt disallows admin, API, tokenized manage and privacy paths
 * - robots.txt allows public booking pages under /book/
 * - sitemap.xml returns valid XML with active tenant URLs
 * - sitemap.xml does not include manage or privacy sub-paths
 *
 * Requires:
 * - The app running at APP_TEST_URL (default: https://voxelbooking-app.test)
 */
final class SeoEndpointTest extends TestCase
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

        self::$appReachable = ($code !== 0);
    }

    protected function setUp(): void
    {
        $this->baseUrl = rtrim($_ENV['APP_TEST_URL'] ?? 'https://voxelbooking-app.test', '/');

        if (!self::$appReachable) {
            $this->markTestSkipped('App not reachable at ' . $this->baseUrl);
        }
    }

    // ── robots.txt ──

    public function test_robots_txt_returns_200(): void
    {
        $result = $this->get('/robots.txt');

        $this->assertSame(200, $result['code'], 'robots.txt must return 200');
        $this->assertStringContainsString('text/plain', $result['content_type']);
    }

    public function test_robots_txt_allows_book(): void
    {
        $body = $this->get('/robots.txt')['body'];

        $this->assertStringContainsString('Allow: /book/', $body);
    }

    public function test_robots_txt_disallows_admin(): void
    {
        $body = $this->get('/robots.txt')['body'];

        $this->assertStringContainsString('Disallow: /admin/', $body);
    }

    public function test_robots_txt_disallows_api(): void
    {
        $body = $this->get('/robots.txt')['body'];

        $this->assertStringContainsString('Disallow: /api/', $body);
    }

    public function test_robots_txt_disallows_manage_paths(): void
    {
        $body = $this->get('/robots.txt')['body'];

        $this->assertStringContainsString('Disallow: /book/*/manage/', $body,
            'Bearer-token manage pages must be disallowed for crawler safety');
    }

    public function test_robots_txt_disallows_privacy_paths(): void
    {
        $body = $this->get('/robots.txt')['body'];

        $this->assertStringContainsString('Disallow: /book/*/privacy/', $body,
            'Bearer-token privacy pages must be disallowed for crawler safety');
    }

    public function test_robots_txt_disallows_install(): void
    {
        $body = $this->get('/robots.txt')['body'];

        $this->assertStringContainsString('Disallow: /install/', $body);
    }

    public function test_robots_txt_disallows_cron(): void
    {
        $body = $this->get('/robots.txt')['body'];

        $this->assertStringContainsString('Disallow: /cron/', $body);
    }

    // ── sitemap.xml ──

    public function test_sitemap_xml_returns_200(): void
    {
        $result = $this->get('/sitemap.xml');

        $this->assertSame(200, $result['code'], 'sitemap.xml must return 200');
        $this->assertStringContainsString('application/xml', $result['content_type']);
    }

    public function test_sitemap_xml_is_valid_xml(): void
    {
        $body = $this->get('/sitemap.xml')['body'];

        // Suppress libxml warnings and verify parsing succeeds
        $prev = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($body);
        libxml_use_internal_errors($prev);

        $this->assertNotFalse($doc, 'sitemap.xml must be valid XML');
        $this->assertSame('urlset', $doc->getName(), 'Root element must be <urlset>');
    }

    public function test_sitemap_xml_contains_book_urls(): void
    {
        $body = $this->get('/sitemap.xml')['body'];
        $doc = simplexml_load_string($body);

        // At minimum, the namespace-aware xpath should find <url> children
        $doc->registerXPathNamespace('s', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        $urls = $doc->xpath('//s:loc');

        $this->assertNotEmpty($urls, 'sitemap.xml must contain at least one <loc>');

        $firstLoc = (string) $urls[0];
        $this->assertStringContainsString('/book/', $firstLoc,
            'Sitemap URLs must be /book/{slug} booking pages');
    }

    public function test_sitemap_xml_does_not_contain_manage_urls(): void
    {
        $body = $this->get('/sitemap.xml')['body'];

        $this->assertStringNotContainsString('/manage/', $body,
            'Sitemap must NOT include tokenized manage URLs');
        $this->assertStringNotContainsString('/privacy/', $body,
            'Sitemap must NOT include tokenized privacy URLs');
    }

    // ── Manage page noindex ──

    public function test_manage_page_emits_noindex(): void
    {
        // Find a real booking to test with
        $bookingInfo = $this->findTestBooking();
        if ($bookingInfo === null) {
            $this->markTestSkipped('No test booking found in database');
        }

        $result = $this->get('/book/' . $bookingInfo['slug'] . '/manage/' . $bookingInfo['id']);

        $this->assertSame(200, $result['code']);
        $this->assertStringContainsString(
            '<meta name="robots" content="noindex, nofollow">',
            $result['body'],
            'Manage page must emit noindex,nofollow for privacy safety'
        );
    }

    public function test_booking_page_does_not_emit_noindex(): void
    {
        // Find an active tenant slug via sitemap
        $sitemapBody = $this->get('/sitemap.xml')['body'];
        if (!preg_match('#/book/([a-z0-9_-]+)</loc>#i', $sitemapBody, $m)) {
            $this->markTestSkipped('No active tenant found in sitemap');
        }

        $result = $this->get('/book/' . $m[1]);

        $this->assertSame(200, $result['code']);
        $this->assertStringNotContainsString(
            'noindex',
            $result['body'],
            'Public booking page must NOT emit noindex — it should be indexable'
        );
    }

    // ── Helper ──

    /**
     * Find a test booking for manage-page tests.
     *
     * @return array{slug: string, id: string}|null
     */
    private function findTestBooking(): ?array
    {
        try {
            \Tests\Support\TestEnvironment::assertDatabase();
            $host = $_ENV['DB_HOST'] ?? '127.0.0.1';
            $port = $_ENV['DB_PORT'] ?? '3309';
            $name = $_ENV['DB_DATABASE'] ?? 'voxelbooking';
            $user = $_ENV['DB_USERNAME'] ?? 'root';
            $pass = $_ENV['DB_PASSWORD'] ?? '';

            $pdo = new \PDO(
                "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
                $user,
                $pass,
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
            );

            $stmt = $pdo->query(
                "SELECT b.id, t.slug FROM bookings b "
                . "JOIN tenants t ON b.tenant_id = t.id "
                . "WHERE t.status = 'active' LIMIT 1"
            );
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            return $row ? ['slug' => $row['slug'], 'id' => $row['id']] : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array{code: int, body: string, content_type: string}
     */
    private function get(string $path): array
    {
        $ch = curl_init($this->baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: '';
        unset($ch);

        return ['code' => $code, 'body' => $body ?: '', 'content_type' => $contentType];
    }
}
