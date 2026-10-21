<?php

declare(strict_types=1);
namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

final class OAuthHttpTest extends TestCase
{
    private string $base;
    private string $cookies;
    protected function setUp(): void
    {
        TestFixtures::provision();
        $this->base = rtrim($_ENV['APP_TEST_URL'], '/');
        $this->cookies = tempnam(sys_get_temp_dir(), 'oauth-test-');
    }
    protected function tearDown(): void { @unlink($this->cookies); }

    private function request(string $path, string $method = 'GET', string $body = '', array $headers = []): array
    {
        $curl = curl_init($this->base . $path);
        $responseHeaders = [];
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => 10,
            CURLOPT_COOKIEJAR => $this->cookies, CURLOPT_COOKIEFILE => $this->cookies,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                if (str_contains($line, ':')) {
                    [$key, $value] = explode(':', $line, 2);
                    $responseHeaders[strtolower(trim($key))] = trim($value);
                }
                return strlen($line);
            },
        ]);
        if ($method !== 'GET') { curl_setopt($curl, CURLOPT_POSTFIELDS, $body); }
        $response = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        unset($curl);
        return ['status' => $status, 'headers' => $responseHeaders, 'body' => $response];
    }

    private function hidden(string $body, string $name): string
    {
        self::assertSame(1, preg_match('/name="' . preg_quote($name, '/') . '"\s+value="([^"]+)"/', $body, $matches));
        return html_entity_decode($matches[1], ENT_QUOTES);
    }

    public function testRealBrowserConsentPkceAndNativeMcp(): void
    {
        $metadata = $this->request('/.well-known/oauth-authorization-server');
        self::assertSame(200, $metadata['status'], $metadata['body']);
        self::assertSame($this->base, json_decode($metadata['body'], true)['issuer']);
        $registration = $this->request('/oauth/register', 'POST', json_encode([
            'client_name' => 'Native MCP test', 'redirect_uris' => ['https://client.example/callback'],
            'token_endpoint_auth_method' => 'none',
        ]), ['Content-Type: application/json']);
        self::assertSame(201, $registration['status'], $registration['body']);
        $client = json_decode($registration['body'], true)['client_id'];
        $verifier = str_repeat('x', 64);
        $parameters = [
            'response_type' => 'code', 'client_id' => $client, 'redirect_uri' => 'https://client.example/callback',
            'scope' => 'services:read', 'resource' => $this->base . '/mcp', 'state' => 'http-test',
            'code_challenge_method' => 'S256',
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
        ];
        $start = $this->request('/oauth/authorize?' . http_build_query($parameters));
        self::assertSame('/admin/login', $start['headers']['location'] ?? '', $start['body']);
        $login = $this->request('/admin/login');
        $signedIn = $this->request('/admin/login', 'POST', http_build_query([
            '_csrf_token' => $this->hidden($login['body'], '_csrf_token'),
            'email' => TestFixtures::BUSINESS_EMAIL, 'password' => TestFixtures::BUSINESS_PASSWORD,
        ]), ['Content-Type: application/x-www-form-urlencoded']);
        self::assertMatchesRegularExpression('~^/oauth/authorize\?request=[a-f0-9]{64}$~D', $signedIn['headers']['location'] ?? '', $signedIn['body']);
        $consent = $this->request($signedIn['headers']['location']);
        self::assertSame(200, $consent['status'], $consent['body']);
        self::assertStringContainsString('Native MCP test', $consent['body']);
        $approval = $this->request('/oauth/authorize', 'POST', http_build_query([
            '_csrf_token' => $this->hidden($consent['body'], '_csrf_token'), 'request' => $this->hidden($consent['body'], 'request'),
            'decision' => 'approve', 'business_ids' => [TestFixtures::BUSINESS_TENANT_ID],
        ]), ['Content-Type: application/x-www-form-urlencoded']);
        self::assertSame(302, $approval['status'], $approval['body']);
        parse_str(parse_url($approval['headers']['location'], PHP_URL_QUERY), $callback);
        self::assertSame('http-test', $callback['state']);
        self::assertSame($this->base, $callback['iss']);
        $exchange = $this->request('/oauth/token', 'POST', http_build_query([
            'grant_type' => 'authorization_code', 'code' => $callback['code'], 'client_id' => $client,
            'redirect_uri' => $parameters['redirect_uri'], 'resource' => $parameters['resource'], 'code_verifier' => $verifier,
        ]), ['Content-Type: application/x-www-form-urlencoded']);
        self::assertSame(200, $exchange['status'], $exchange['body']);
        $token = json_decode($exchange['body'], true)['access_token'];
        $mcp = $this->request('/mcp', 'POST', json_encode([
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list',
            'params' => ['_meta' => ['io.modelcontextprotocol/protocolVersion' => '2026-07-28', 'io.modelcontextprotocol/clientCapabilities' => new \stdClass()]],
        ]), ['Content-Type: application/json', 'Accept: application/json, text/event-stream', 'MCP-Protocol-Version: 2026-07-28', 'Mcp-Method: tools/list', 'Authorization: Bearer ' . $token]);
        self::assertSame(200, $mcp['status'], $mcp['body']);
        $result = json_decode($mcp['body'], true);
        self::assertNotEmpty($result['result']['tools'] ?? [], $mcp['body']);
        self::assertSame('complete', $result['result']['resultType']);
        $failure = $this->request('/mcp', 'POST', json_encode([
            'jsonrpc' => '2.0', 'id' => 42, 'method' => 'tools/call',
            'params' => ['name' => 'services_list', 'arguments' => ['business_id' => 'ungranted-business'],
                '_meta' => ['io.modelcontextprotocol/protocolVersion' => '2026-07-28', 'io.modelcontextprotocol/clientCapabilities' => new \stdClass()]],
        ]), ['Content-Type: application/json', 'Accept: application/json, text/event-stream', 'MCP-Protocol-Version: 2026-07-28', 'Mcp-Method: tools/call', 'Mcp-Name: services_list', 'Authorization: Bearer '.$token]);
        self::assertSame(200, $failure['status'], $failure['body']);
        $problem = json_decode($failure['body'], true);
        self::assertTrue($problem['result']['isError'] ?? false, $failure['body']);
        self::assertSame('42', $problem['result']['_meta']['com.voxelbooking/problem']['request_id']);
        $revoked = $this->request('/oauth/revoke', 'POST', http_build_query(['client_id' => $client, 'token' => $token]), ['Content-Type: application/x-www-form-urlencoded']);
        self::assertSame(200, $revoked['status'], $revoked['body']);
        $denied = $this->request('/mcp', 'POST', '{}', ['Content-Type: application/json', 'Accept: application/json, text/event-stream', 'Authorization: Bearer ' . $token]);
        self::assertSame(401, $denied['status'], $denied['body']);
        self::assertStringContainsString('resource_metadata=', $denied['headers']['www-authenticate'] ?? '');
    }

    public function testDuplicateParametersAndInvalidPkceFailBeforeConsent(): void
    {
        $response = $this->request('/oauth/token', 'POST', 'client_id=a&client_id=b', ['Content-Type: application/x-www-form-urlencoded']);
        self::assertSame(400, $response['status']);
        self::assertSame('invalid_request', json_decode($response['body'], true)['error']);
        $response = $this->request('/.well-known/oauth-protected-resource/mcp');
        self::assertSame(200, $response['status'], $response['body']);
        self::assertSame($this->base . '/mcp', json_decode($response['body'], true)['resource']);
    }

    public function testCustomerBrowserVerificationResumesNarrowConsentWithoutStaffLogin(): void
    {
        $registration = $this->request('/oauth/register', 'POST', json_encode([
            'client_name' => 'Customer booking assistant', 'redirect_uris' => ['https://client.example/callback'],
            'token_endpoint_auth_method' => 'none',
        ]), ['Content-Type: application/json']);
        self::assertSame(201, $registration['status'], $registration['body']);
        $client = json_decode($registration['body'], true)['client_id'];
        $verifier = str_repeat('y', 64);
        $parameters = [
            'response_type' => 'code', 'client_id' => $client, 'redirect_uri' => 'https://client.example/callback',
            'scope' => 'bookings:read', 'resource' => $this->base . '/mcp', 'state' => 'customer-http-test',
            'customer_purpose' => 'booking.manage', 'business_id' => TestFixtures::BUSINESS_TENANT_ID, 'subject_id' => TestFixtures::BOOKING_ID,
            'code_challenge_method' => 'S256',
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
        ];
        $start = $this->request('/oauth/authorize?' . http_build_query($parameters));
        self::assertSame(200, $start['status'], $start['body']);
        self::assertStringContainsString('Verify your customer access', $start['body']);
        $recovery = $this->request('/book/test-fixture/manage/' . TestFixtures::BOOKING_ID);
        self::assertSame(200, $recovery['status'], $recovery['body']);
        $csrf = $this->hidden($recovery['body'], '_csrf_token');
        $raw = \App\Engine\CustomerCapability::issueManage(TestFixtures::BUSINESS_TENANT_ID, TestFixtures::BOOKING_ID);
        $verified = $this->request('/api/test-fixture/customer-access', 'POST', json_encode([
            'purpose' => 'booking.manage', 'subject_id' => TestFixtures::BOOKING_ID, 'token' => $raw,
        ]), ['Content-Type: application/json', 'X-CSRF-Token: ' . $csrf]);
        self::assertSame(200, $verified['status'], $verified['body']);
        $return = $this->request('/book/test-fixture/manage/' . TestFixtures::BOOKING_ID);
        self::assertSame(302, $return['status'], $return['body']);
        self::assertMatchesRegularExpression('~^/oauth/authorize\?request=[a-f0-9]{64}$~D', $return['headers']['location'] ?? '');
        $consent = $this->request($return['headers']['location']);
        self::assertSame(200, $consent['status'], $consent['body']);
        self::assertStringContainsString('Access is limited to this verified booking.', $consent['body']);
        self::assertStringNotContainsString($raw, $consent['body']);
        $approved = $this->request('/oauth/authorize', 'POST', http_build_query([
            '_csrf_token' => $this->hidden($consent['body'], '_csrf_token'),
            'request' => $this->hidden($consent['body'], 'request'), 'decision' => 'approve',
        ]), ['Content-Type: application/x-www-form-urlencoded']);
        self::assertSame(302, $approved['status'], $approved['body']);
        parse_str(parse_url($approved['headers']['location'], PHP_URL_QUERY), $callback);
        $exchange = $this->request('/oauth/token', 'POST', http_build_query([
            'grant_type' => 'authorization_code', 'code' => $callback['code'], 'client_id' => $client,
            'redirect_uri' => $parameters['redirect_uri'], 'resource' => $parameters['resource'], 'code_verifier' => $verifier,
        ]), ['Content-Type: application/x-www-form-urlencoded']);
        self::assertSame(200, $exchange['status'], $exchange['body']);
        $actor = (new \App\Agent\ConnectionAuthenticator())->authenticate(json_decode($exchange['body'], true)['access_token'], $this->base . '/mcp');
        self::assertSame('customer', $actor->principalType);
        self::assertSame(TestFixtures::BOOKING_ID, $actor->bookingId);
        self::assertSame(['bookings:read'], $actor->scopes);
    }
}
