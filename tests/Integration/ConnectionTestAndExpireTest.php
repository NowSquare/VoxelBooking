<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Agent\{Endpoints, OperationException, PrincipalResolver, SchemaValidator};
use App\Agent\Mcp\Server;
use App\Engine\Database;
use PHPUnit\Framework\TestCase;
use Tests\Support\AgentTransports;

/**
 * A connection can check itself and end itself at a chosen time.
 *
 * connection_test is the client's self-check on every transport. connection_expire
 * mirrors the admin screen's control over a connection's life: the screen sets an
 * expiry at creation and revokes; a connection may only bring its own end forward,
 * after the represented person reviews it in the browser.
 */
final class ConnectionTestAndExpireTest extends TestCase
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
        Database::execute('UPDATE agent_connections SET revoked_at=UTC_TIMESTAMP() WHERE principal_id=? AND revoked_at IS NULL', [TestFixtures::BUSINESS_USER_ID]);
    }

    private function owner(array $scopes): array
    {
        $session = (new PrincipalResolver())->resolve(
            'business_user',
            TestFixtures::BUSINESS_USER_ID,
            [TestFixtures::BUSINESS_TENANT_ID],
            $scopes,
            authMode: 'session'
        );

        return $this->transports->connect($session, $scopes);
    }

    public function testConnectionTestReportsGrantExpiryTransportAndScopeCoverageOnEveryTransport(): void
    {
        $client = $this->owner(['bookings:read']);
        $schema = $this->transports->registry->get('connection_test')->resultSchema();

        $local = $this->transports->executor($client, 'connection_test', []);
        (new SchemaValidator())->validate($schema, $local, true);
        $data = $local['data'];
        self::assertTrue($data['authenticated']);
        self::assertSame(['business_user', TestFixtures::BUSINESS_USER_ID, 'business_owner'], array_values($data['principal']));
        self::assertSame([TestFixtures::BUSINESS_TENANT_ID], $data['business_ids']);
        self::assertSame(['bookings:read'], $data['scopes']);
        self::assertSame($client['connection_id'], $data['connection']['id']);
        self::assertGreaterThan(1000, $data['connection']['expires_in_seconds']);
        self::assertSame(['http', null], [$data['transport'], $data['protocol_version']], 'The Executor actor holds the HTTP token.');
        self::assertSame(Endpoints::resource('mcp'), $data['discovery']['mcp_url']);

        // What the grant allows, and what the owner's role allows but this grant lacks.
        $available = $data['scope_coverage']['available'];
        foreach (['bookings_list', 'calendar_summary', 'connection_test', 'connection_expire'] as $operation) {
            self::assertContains($operation, $available);
        }
        $missing = array_column($data['scope_coverage']['missing_scope'], 'missing_scopes', 'operation');
        self::assertSame(['bookings:write'], $missing['booking_status_set']);
        self::assertSame(['bookings:export'], $missing['bookings_export']);
        self::assertArrayNotHasKey('operator_bookings_list', $missing, 'Role limits are not a missing scope.');
        self::assertNotContains('operator_bookings_list', $available);

        $http = $this->transports->http($client, 'connection_test', []);
        self::assertSame(200, $http['status'], json_encode($http['body']));
        (new SchemaValidator())->validate($schema, $http['body'], true);
        self::assertSame(['http', null], [$http['body']['data']['transport'], $http['body']['data']['protocol_version']]);

        $native = $this->transports->mcp($client, 'connection_test', []);
        self::assertFalse($native['body']['result']['isError'] ?? true, json_encode($native['body']));
        $nativeData = $native['body']['result']['structuredContent']['data'];
        self::assertSame(['mcp', Server::LEGACY_VERSION], [$nativeData['transport'], $nativeData['protocol_version']]);
        self::assertSame($data['scope_coverage'], $nativeData['scope_coverage']);
    }

    public function testExpireShortensAfterReviewAuditsAndCapsTokensButNeverExtends(): void
    {
        $client = $this->owner(['bookings:read']);
        $target = gmdate('Y-m-d\TH:i:s\Z', time() + 600);

        foreach ([gmdate('Y-m-d\TH:i:s\Z', time() + 7200) => 'cannot_extend', gmdate('Y-m-d\TH:i:s\Z', time() - 60) => 'must_be_future'] as $refused => $reason) {
            try {
                $this->transports->executor->preview($client['actor'], 'connection_expire', ['expires_at' => $refused]);
                self::fail('A connection may only bring its end forward: ' . $reason);
            } catch (OperationException $error) {
                self::assertSame(['validation_failed', ['expires_at' => $reason]], [$error->problemCode, $error->fieldErrors]);
            }
        }

        // The commit needs the represented person's browser approval of the reviewed instant.
        try {
            $this->transports->executor->execute($client['actor'], 'connection_expire', ['expires_at' => $target], 'expire-unreviewed');
            self::fail('An unreviewed expiry change must not commit.');
        } catch (OperationException $error) {
            self::assertSame('preview_required', $error->problemCode);
        }
        $preview = $this->transports->executor->preview($client['actor'], 'connection_expire', ['expires_at' => $target]);
        self::assertSame(gmdate('Y-m-d H:i:s', strtotime($target)), $preview['review']['expires_at']);
        $this->transports->approve($client, $preview);
        $result = $this->transports->executor->execute($client['actor'], 'connection_expire', ['preview_handle' => $preview['preview_handle']], 'expire-' . bin2hex(random_bytes(6)));
        (new SchemaValidator())->validate($this->transports->registry->get('connection_expire')->resultSchema(), $result, true);
        $expected = gmdate('Y-m-d H:i:s', strtotime($target));
        self::assertSame([$client['connection_id'], $expected], [$result['data']['connection_id'], $result['data']['expires_at']]);
        self::assertSame($expected, Database::query('SELECT expires_at FROM agent_connections WHERE id=?', [$client['connection_id']])[0]['expires_at']);
        foreach (Database::query('SELECT expires_at FROM agent_credentials WHERE connection_id=?', [$client['connection_id']]) as $credential) {
            self::assertLessThanOrEqual($expected, $credential['expires_at'], 'No token outlives its connection.');
        }
        $audit = Database::query("SELECT details FROM audit_log WHERE action='connection.expiry_shortened' AND entity_id=?", [$client['connection_id']]);
        self::assertCount(1, $audit);
        self::assertSame($expected, json_decode($audit[0]['details'], true)['expires_at']);
        self::assertSame(1, (int) Database::query("SELECT COUNT(*) AS n FROM audit_log WHERE action='agent.connection_expire' AND entity_id=?", [$result['receipt_id']])[0]['n']);
        self::assertSame($expected, $this->transports->executor($client, 'connection_get', [])['data']['expires_at']);

        // HTTP and MCP each shorten their own connection through the same review.
        $overHttp = $this->owner(['bookings:read']);
        // An explicit offset names the same instant as Z.
        $at = (new \DateTimeImmutable('@' . (time() + 900)))->setTimezone(new \DateTimeZone('+02:00'))->format('Y-m-d\TH:i:sP');
        $http = $this->transports->http($overHttp, 'connection_expire', ['expires_at' => $at]);
        self::assertSame(200, $http['status'], json_encode($http['body']));
        self::assertSame(gmdate('Y-m-d H:i:s', strtotime($at)), $http['body']['data']['expires_at']);
        $overMcp = $this->owner(['bookings:read']);
        $native = $this->transports->mcp($overMcp, 'connection_expire', ['expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 1000)]);
        self::assertFalse($native['body']['result']['isError'] ?? true, json_encode($native['body']));
        self::assertSame($overMcp['connection_id'], $native['body']['result']['structuredContent']['data']['connection_id']);
    }
}
