<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Agent\{ConnectionService, Endpoints, PrincipalResolver};
use App\Engine\Database;
use PHPUnit\Framework\TestCase;
use Tests\Support\AgentTransports;

/**
 * Every reason a client cannot reach an operation has its own machine code.
 *
 * Brief §10: wrong URL, missing authorization header, unsupported protocol,
 * expired grant, insufficient scope and disabled account must never all read
 * "Something went wrong". A revoked connection and a token sent to the wrong
 * address are distinct too, while an unknown token still says nothing more than
 * "unauthorized", because it may belong to another installation.
 */
final class ConnectionDiagnosticsTest extends TestCase
{
    private AgentTransports $transports;

    protected function setUp(): void
    {
        \Tests\Support\TestEnvironment::boot();
        TestFixtures::provision();
        Database::execute("DELETE FROM rate_limits WHERE endpoint_group='agent'");
        Database::execute('DELETE FROM agent_rate_budgets');
        // Earlier suites change the fixture member's role; these tests speak for an active owner.
        Database::execute("UPDATE business_users SET role='owner', is_active=1 WHERE id=?", [TestFixtures::BUSINESS_USER_ID]);
        $this->transports = new AgentTransports();
    }

    protected function tearDown(): void
    {
        Database::execute('UPDATE business_users SET is_active=1 WHERE id=?', [TestFixtures::BUSINESS_USER_ID]);
        Database::execute('UPDATE agent_connections SET revoked_at=UTC_TIMESTAMP() WHERE principal_id=? AND revoked_at IS NULL', [TestFixtures::BUSINESS_USER_ID]);
    }

    private function client(): array
    {
        $session = (new PrincipalResolver())->resolve(
            'business_user',
            TestFixtures::BUSINESS_USER_ID,
            [TestFixtures::BUSINESS_TENANT_ID],
            ['bookings:read'],
            authMode: 'session'
        );

        return $this->transports->connect($session, ['bookings:read']);
    }

    /** @return array{status: int, code: ?string, next_action: ?string} what the HTTP API answers */
    private function overHttp(?string $token, string $path = '/api/agent/v2/connection/test'): array
    {
        $response = $this->transports->request('GET', $path, $token);

        return ['status' => $response['status'], 'code' => $response['body']['code'] ?? null, 'next_action' => $response['body']['next_action'] ?? null];
    }

    /**
     * What MCP answers: a transport-level problem document, a JSON-RPC error's data,
     * or a tool error's problem, whichever layer refused the call.
     *
     * @return array{status: int, code: ?string, next_action: ?string}
     */
    private function overMcp(?string $token, string $tool = 'connection_test', string $path = '/mcp', array $headers = []): array
    {
        $response = $this->transports->call($token, $tool, [], $path, $headers);
        $body = $response['body'];
        $problem = $body['result']['_meta']['com.voxelbooking/problem'] ?? $body['error']['data'] ?? $body;

        return ['status' => $response['status'], 'code' => $problem['code'] ?? null, 'next_action' => $problem['next_action'] ?? null];
    }

    public function testEveryFailureCauseHasADistinctCodeAndNextActionOverHttpAndMcp(): void
    {
        $http = [];
        $mcp = [];

        $http['wrong_url'] = $this->overHttp(null, '/api/agent/v2/conection/test');
        $mcp['wrong_url'] = $this->overMcp(null, path: '/mcp/v1');
        $discovery = $this->transports->request('GET', '/api/agent/v2/unknown');
        self::assertSame(Endpoints::resource('http') . '/schema', $discovery['body']['discovery_url']);
        self::assertStringContainsString('application/problem+json', $discovery['headers']);
        self::assertSame(Endpoints::resource('mcp'), $this->transports->call(null, 'connection_test', [], '/mcp/v1')['body']['discovery_url']);

        $http['missing_authorization'] = $this->overHttp(null);
        $mcp['missing_authorization'] = $this->overMcp(null);

        $http['unknown_token'] = $this->overHttp('vbc_' . str_repeat('x', 43));
        $mcp['unknown_token'] = $this->overMcp('vbc_' . str_repeat('x', 43));

        $client = $this->client();
        // Each token is bound to the address it was issued for.
        $http['wrong_audience'] = $this->overHttp($client['tokens']['mcp']);
        $mcp['wrong_audience'] = $this->overMcp($client['tokens']['http']);

        // Only MCP negotiates a protocol revision; HTTP versions live in the path.
        $versioned = $this->transports->request('POST', '/mcp', $client['tokens']['mcp'], [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'connection_test', 'arguments' => (object) [], '_meta' => ['io.modelcontextprotocol/protocolVersion' => '2099-01-01']],
        ], ['MCP-Protocol-Version: 2099-01-01']);
        $mcp['unsupported_protocol'] = [
            'status' => $versioned['status'],
            'code' => $versioned['body']['error']['data']['code'] ?? null,
            'next_action' => $versioned['body']['error']['data']['next_action'] ?? null,
        ];
        self::assertSame(-32022, $versioned['body']['error']['code']);

        // bookings_export needs bookings:export, which the owner holds and this grant lacks.
        $export = '/api/agent/v2/businesses/' . TestFixtures::BUSINESS_TENANT_ID . '/bookings/export';
        $http['insufficient_scope'] = $this->overHttp($client['tokens']['http'], $export);
        $mcp['insufficient_scope'] = $this->overMcp($client['tokens']['mcp'], 'bookings_export');

        Database::execute('UPDATE business_users SET is_active=0 WHERE id=?', [TestFixtures::BUSINESS_USER_ID]);
        $http['disabled_account'] = $this->overHttp($client['tokens']['http']);
        $mcp['disabled_account'] = $this->overMcp($client['tokens']['mcp']);
        Database::execute('UPDATE business_users SET is_active=1 WHERE id=?', [TestFixtures::BUSINESS_USER_ID]);

        Database::execute('UPDATE agent_connections SET expires_at=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE) WHERE id=?', [$client['connection_id']]);
        $http['expired_grant'] = $this->overHttp($client['tokens']['http']);
        $mcp['expired_grant'] = $this->overMcp($client['tokens']['mcp']);

        $revoked = $this->client();
        (new ConnectionService($this->transports->registry))->revoke($revoked['reviewer'], $revoked['connection_id']);
        $http['revoked_connection'] = $this->overHttp($revoked['tokens']['http']);
        $mcp['revoked_connection'] = $this->overMcp($revoked['tokens']['mcp']);

        $expected = [
            'wrong_url' => [404, 'unknown_endpoint', 'discover_endpoints'],
            'missing_authorization' => [401, 'authorization_required', 'connect_client'],
            'unknown_token' => [401, 'unauthorized', 'reconnect'],
            'wrong_audience' => [401, 'wrong_audience', 'use_matching_endpoint'],
            'insufficient_scope' => [403, 'insufficient_scope', 'request_more_access'],
            'disabled_account' => [403, 'account_disabled', 'contact_business_owner'],
            'expired_grant' => [401, 'connection_expired', 'reconnect'],
            'revoked_connection' => [401, 'connection_revoked', 'reconnect'],
        ];
        foreach ($expected as $cause => [$status, $code, $next]) {
            self::assertSame(['status' => $status, 'code' => $code, 'next_action' => $next], $http[$cause], 'HTTP ' . $cause);
            // A refused MCP tool call is still a delivered JSON-RPC response.
            $mcpStatus = $cause === 'insufficient_scope' ? 200 : $status;
            self::assertSame(['status' => $mcpStatus, 'code' => $code, 'next_action' => $next], $mcp[$cause], 'MCP ' . $cause);
        }
        self::assertSame(['status' => 400, 'code' => 'unsupported_protocol_version', 'next_action' => 'use_supported_protocol'], $mcp['unsupported_protocol']);

        $codes = array_column([...array_values($http), ...array_values($mcp)], 'code');
        self::assertCount(count($expected) + 1, array_unique($codes), 'Each cause has its own code.');
    }
}
