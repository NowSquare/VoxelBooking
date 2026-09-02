<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Engine\Database;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for the self-service cancel public API endpoint.
 *
 * POST /api/{slug}/bookings/{id}/cancel
 *
 * Covers:
 * - CSRF enforcement: the session token from the manage page, or the
 *   Origin header for the embed iframe; a foreign Origin is rejected
 * - Happy path: the booking flips to cancelled, with and without a session
 */
final class SelfServiceCancelTest extends TestCase
{
    private static bool $appReachable = false;
    private string $baseUrl;
    private string $cookieJar;

    private const TENANT_ID   = TestFixtures::BUSINESS_TENANT_ID;
    private const CUSTOMER_ID = TestFixtures::CUSTOMER_ID;
    private const BOOKING_ID  = '01TESTSSCANCELBOOKING00';

    public static function setUpBeforeClass(): void
    {
        $baseUrl = rtrim($_ENV['APP_TEST_URL'] ?? 'https://voxelbooking-app.test', '/');

        $ch = curl_init($baseUrl . '/health');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_TIMEOUT => 5]);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($code === 0) {
            return;
        }

        try {
            TestFixtures::provision();
            self::$appReachable = true;
        } catch (\Throwable) {
            // Database unavailable: tests skip in setUp
        }
    }

    protected function setUp(): void
    {
        if (!self::$appReachable) {
            $this->markTestSkipped('App or database not reachable');
        }

        $this->baseUrl = rtrim($_ENV['APP_TEST_URL'] ?? 'https://voxelbooking-app.test', '/');
        $this->cookieJar = tempnam(sys_get_temp_dir(), 'vb_ss_cancel_') ?: '/tmp/vb_ss_cancel_cookies';

        try {
            Database::execute("DELETE FROM `rate_limits`");
        } catch (\Throwable) {
            // best-effort
        }

        $this->insertConfirmedBooking();
    }

    protected function tearDown(): void
    {
        if (isset($this->cookieJar) && file_exists($this->cookieJar)) {
            unlink($this->cookieJar);
        }

        try {
            Database::execute("DELETE FROM `bookings` WHERE `id` = ?", [self::BOOKING_ID]);
        } catch (\Throwable) {
            // best-effort
        }
    }

    // ════════════════════════════════════════════════════════════════
    // CSRF enforcement
    // ════════════════════════════════════════════════════════════════

    public function test_cancel_without_csrf_returns_403(): void
    {
        $r = $this->postJson($this->cancelPath(), ['reason' => 'No token'], '');

        $this->assertSame(403, $r['code']);
        $data = json_decode($r['body'], true);
        $this->assertSame('csrf_mismatch', $data['error'] ?? null);
        $this->assertSame('confirmed', $this->bookingStatus());
    }

    public function test_cancel_rejects_foreign_origin(): void
    {
        $r = $this->postJson($this->cancelPath(), ['reason' => 'Forged'], '', ['Origin: https://evil.example']);

        $this->assertSame(403, $r['code']);
        $data = json_decode($r['body'], true);
        $this->assertSame('csrf_mismatch', $data['error'] ?? null);
        $this->assertSame('confirmed', $this->bookingStatus());
    }

    // ════════════════════════════════════════════════════════════════
    // Happy paths
    // ════════════════════════════════════════════════════════════════

    public function test_cancel_with_session_token_succeeds(): void
    {
        $token = $this->getCsrfFromManagePage();
        $this->assertNotSame('', $token, 'Manage page must expose a CSRF token');

        $r = $this->postJson($this->cancelPath(), ['reason' => 'Standalone page'], $token);

        $this->assertSame(200, $r['code'], 'Body: ' . $r['body']);
        $data = json_decode($r['body'], true);
        $this->assertTrue($data['cancelled'] ?? false);
        $this->assertSame('cancelled', $this->bookingStatus());
    }

    /**
     * The embed iframe runs cross-site, so browsers withhold its cookies.
     * It has no session and no token. The browser still attaches an Origin
     * header to the POST, and a matching Origin is proof enough.
     */
    public function test_cancel_with_same_origin_and_no_session_succeeds(): void
    {
        $r = $this->postJson($this->cancelPath(), ['reason' => 'Embedded page'], '', ['Origin: ' . $this->baseUrl]);

        $this->assertSame(200, $r['code'], 'Embedded cancel must succeed without a session. Body: ' . $r['body']);
        $data = json_decode($r['body'], true);
        $this->assertTrue($data['cancelled'] ?? false);
        $this->assertSame('cancelled', $this->bookingStatus());
    }

    // ════════════════════════════════════════════════════════════════
    // Fixtures
    // ════════════════════════════════════════════════════════════════

    /**
     * A confirmed booking two days out, well past the 24-hour cancel gate.
     */
    private function insertConfirmedBooking(): void
    {
        $customer = Database::query("SELECT `id` FROM `customers` WHERE `id` = ?", [self::CUSTOMER_ID]);
        if (empty($customer)) {
            Database::execute(
                "INSERT INTO `customers` (`id`, `tenant_id`, `name`, `email`)
                 VALUES (?, ?, 'Test Customer', 'customer@example.com')",
                [self::CUSTOMER_ID, self::TENANT_ID]
            );
        }

        $date = date('Y-m-d', strtotime('+2 days'));

        Database::execute("DELETE FROM `bookings` WHERE `id` = ?", [self::BOOKING_ID]);
        Database::execute(
            "INSERT INTO `bookings`
             (`id`, `tenant_id`, `booking_pattern`, `customer_id`,
              `start_datetime`, `end_datetime`, `status`, `source`)
             VALUES (?, ?, 'timeslot', ?,
              '{$date} 09:00:00', '{$date} 09:30:00',
              'confirmed', 'web')",
            [self::BOOKING_ID, self::TENANT_ID, self::CUSTOMER_ID]
        );
    }

    private function bookingStatus(): ?string
    {
        $rows = Database::query("SELECT `status` FROM `bookings` WHERE `id` = ?", [self::BOOKING_ID]);

        return $rows[0]['status'] ?? null;
    }

    private function cancelPath(): string
    {
        return '/api/test-fixture/bookings/' . self::BOOKING_ID . '/cancel';
    }

    // ════════════════════════════════════════════════════════════════
    // HTTP helpers
    // ════════════════════════════════════════════════════════════════

    /**
     * Obtain a CSRF token by visiting the manage page, the way the
     * standalone flow does. The session cookie lands in the cookie jar.
     */
    private function getCsrfFromManagePage(): string
    {
        $r = $this->get('/book/test-fixture/manage/' . self::BOOKING_ID);
        if (preg_match('/window\.__VB_CSRF__\s*=\s*"([a-f0-9]+)"/', $r['body'], $m)) {
            return $m[1];
        }

        return '';
    }

    /**
     * POST JSON to the given path.
     *
     * Extra headers let a test mimic the embed iframe, which sends
     * an Origin header and nothing else.
     */
    private function postJson(string $path, array $data, string $csrfToken, array $extraHeaders = []): array
    {
        $ch = curl_init($this->baseUrl . $path);
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
        ];
        if ($csrfToken !== '') {
            $headers[] = 'X-CSRF-Token: ' . $csrfToken;
        }
        $headers = array_merge($headers, $extraHeaders);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_COOKIEJAR => $this->cookieJar,
            CURLOPT_COOKIEFILE => $this->cookieJar,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADER => true,
        ]);
        $response = (string) curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);

        $body = substr($response, $headerSize);
        return compact('code', 'body');
    }

    /**
     * @return array{code: int, body: string}
     */
    private function get(string $path): array
    {
        $ch = curl_init($this->baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIEJAR => $this->cookieJar,
            CURLOPT_COOKIEFILE => $this->cookieJar,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADER => true,
        ]);
        $response = (string) curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);

        $body = substr($response, $headerSize);
        return compact('code', 'body');
    }
}
