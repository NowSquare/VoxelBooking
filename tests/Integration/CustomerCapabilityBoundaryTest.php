<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Engine\CustomerCapability;
use App\Engine\Database;
use PHPUnit\Framework\TestCase;

final class CustomerCapabilityBoundaryTest extends TestCase
{
    private string $baseUrl;
    private string $cookies;

    protected function setUp(): void
    {
        \Tests\Support\TestEnvironment::boot();
        TestFixtures::provision();\Tests\Support\CustomerFixtureState::restore();
        $this->baseUrl = rtrim($_ENV['APP_TEST_URL'], '/');
        $this->cookies = tempnam(sys_get_temp_dir(), 'vb-capability-');
        Database::execute('DELETE FROM `customer_capabilities`');
        Database::execute('DELETE FROM `customer_recovery_limits`');
    }

    protected function tearDown(): void
    {
        @unlink($this->cookies);
    }

    public function testIdentifiersAndForgedOriginNeverAuthorizePublicManagement(): void
    {
        $id = TestFixtures::BOOKING_ID;
        $page = $this->request('GET', '/book/test-fixture/manage/' . $id);
        self::assertSame(200, $page['code']);
        self::assertStringContainsString('Open your secure link', $page['body']);
        self::assertStringNotContainsString('customer@example.com', $page['body']);
        $read = $this->request('GET', '/api/test-fixture/bookings/' . $id, headers: ['Origin: ' . $this->baseUrl]);
        self::assertSame(403, $read['code']);
        $cancel = $this->request('POST', '/api/test-fixture/bookings/' . $id . '/cancel', '{}', ['Content-Type: application/json', 'Origin: ' . $this->baseUrl]);
        self::assertSame(403, $cancel['code']);
        self::assertSame('confirmed', Database::query('SELECT status FROM bookings WHERE id = ?', [$id])[0]['status']);
    }

    public function testBookingCapabilityCannotReadPrivacyAndRevocationIsImmediate(): void
    {
        $token = CustomerCapability::issueManage(TestFixtures::BUSINESS_TENANT_ID, TestFixtures::BOOKING_ID);
        $headers = ['X-Customer-Capability: ' . $token];
        $read = $this->request('GET', '/api/test-fixture/bookings/' . TestFixtures::BOOKING_ID, headers: $headers);
        self::assertSame(200, $read['code'], $read['body']);
        self::assertStringContainsString('no-store', strtolower($read['headers']));
        $privacy = $this->request('GET', '/book/test-fixture/privacy/' . TestFixtures::CUSTOMER_ID, headers: $headers);
        self::assertStringNotContainsString('customer@example.com', $privacy['body']);
        self::assertStringContainsString('Open your secure link', $privacy['body']);
        CustomerCapability::revokeForSubject(TestFixtures::BUSINESS_TENANT_ID, CustomerCapability::MANAGE, TestFixtures::BOOKING_ID);
        self::assertSame(403, $this->request('GET', '/api/test-fixture/bookings/' . TestFixtures::BOOKING_ID, headers: $headers)['code']);
    }

    public function testFragmentExchangeCreatesScopedBrowserGrantWithNoSecretInResponse(): void
    {
        $page = $this->request('GET', '/book/test-fixture/manage/' . TestFixtures::BOOKING_ID);
        preg_match('/data-csrf="([a-f0-9]+)"/', $page['body'], $matches);
        self::assertNotEmpty($matches[1] ?? null);
        $token = CustomerCapability::issueManage(TestFixtures::BUSINESS_TENANT_ID, TestFixtures::BOOKING_ID);
        $exchange = $this->request('POST', '/api/test-fixture/customer-access', json_encode([
            'purpose' => CustomerCapability::MANAGE, 'subject_id' => TestFixtures::BOOKING_ID, 'token' => $token,
        ]), ['Content-Type: application/json', 'X-CSRF-Token: ' . $matches[1]]);
        self::assertSame(200, $exchange['code'], $exchange['body']);
        self::assertStringNotContainsString($token, $exchange['body']);
        self::assertSame(200, $this->request('GET', '/api/test-fixture/bookings/' . TestFixtures::BOOKING_ID)['code']);
        self::assertStringNotContainsString('customer@example.com', $this->request('GET', '/book/test-fixture/privacy/' . TestFixtures::CUSTOMER_ID)['body']);
    }

    public function testRecoveryReturnsIdenticalResponsesAndQueuesNoRawCapability(): void
    {
        $page = $this->request('GET', '/book/test-fixture/manage/' . TestFixtures::BOOKING_ID);
        preg_match('/data-csrf="([a-f0-9]+)"/', $page['body'], $matches);
        $form = ['purpose' => CustomerCapability::MANAGE, 'subject_id' => TestFixtures::BOOKING_ID, '_csrf_token' => $matches[1]];
        $known = $this->request('POST', '/book/test-fixture/access-recovery', http_build_query($form + ['email' => 'customer@example.com']));
        $unknown = $this->request('POST', '/book/test-fixture/access-recovery', http_build_query($form + ['email' => 'unknown@example.com']));
        self::assertSame(202, $known['code'], $known['body']);
        self::assertSame($known['code'], $unknown['code']);
        self::assertSame($known['body'], $unknown['body']);
        self::assertSame(0, (int) Database::query('SELECT COUNT(*) AS c FROM customer_capabilities')[0]['c'], 'Recovery must mint at delivery, not request time.');
    }

    private function request(string $method, string $path, ?string $body = null, array $headers = []): array
    {
        $curl = curl_init($this->baseUrl . $path);
        curl_setopt_array($curl, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 10,
            CURLOPT_COOKIEFILE => $this->cookies, CURLOPT_COOKIEJAR => $this->cookies]);
        if ($body !== null) curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        $response = (string) curl_exec($curl);
        $code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $headerLength = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
        return ['code' => $code, 'headers' => substr($response, 0, $headerLength), 'body' => substr($response, $headerLength)];
    }
}
