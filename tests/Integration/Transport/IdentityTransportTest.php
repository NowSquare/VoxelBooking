<?php

declare(strict_types=1);

namespace Tests\Integration\Transport;

use App\Engine\Database;
use Tests\Integration\TestFixtures;
use Tests\Support\Transport\TransportTestCase;

/**
 * Account, identity and connection self-service over HTTP v2 and native MCP.
 *
 * Each test covers one operation on both transports, because the effect of the
 * first call (a name, a receipt, a revoked connection) is the setup of the second.
 */
final class IdentityTransportTest extends TransportTestCase
{
    private array $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = Database::query('SELECT name FROM business_users WHERE id=?', [TestFixtures::BUSINESS_USER_ID])[0];
        $_SESSION = ['auth_type' => 'business_user', 'auth_id' => TestFixtures::BUSINESS_USER_ID, 'auth_tenant_id' => TestFixtures::BUSINESS_TENANT_ID];
    }

    protected function tearDown(): void
    {
        Database::execute('UPDATE business_users SET name=? WHERE id=?', [$this->user['name'], TestFixtures::BUSINESS_USER_ID]);
        parent::tearDown();
    }

    public function testAccountGetOverHttpAndMcp(): void
    {
        $client = $this->connect($this->ownerSession(), ['account_get']);
        foreach (self::TRANSPORTS as $transport) {
            $data = $this->call($transport, $client, 'account_get', [])['data'];
            self::assertSame(TestFixtures::BUSINESS_USER_ID, $data['id'], $transport);
            self::assertSame(TestFixtures::BUSINESS_EMAIL, $data['email'], $transport);
            self::assertSame([TestFixtures::BUSINESS_TENANT_ID], $data['business_ids'], $transport);
        }
    }

    public function testAccountUpdateOverHttpAndMcp(): void
    {
        $client = $this->connect($this->ownerSession(), ['account_get', 'account_update']);
        foreach (self::TRANSPORTS as $transport) {
            $version = $this->read($client['actor'], 'account_get', [])['version'];
            $name = 'Transport account ' . $transport;
            $data = $this->call($transport, $client, 'account_update', ['name' => $name, 'expected_version' => $version])['data'];
            self::assertSame($name, $data['name']);
            self::assertNotSame($version, $data['version']);
            self::assertSame($name, Database::query('SELECT name FROM business_users WHERE id=?', [TestFixtures::BUSINESS_USER_ID])[0]['name']);
        }
    }

    public function testIdentityGetOverHttpAndMcp(): void
    {
        $client = $this->connect($this->ownerSession(), ['account_get']);
        foreach (self::TRANSPORTS as $transport) {
            $data = $this->call($transport, $client, 'identity_get', [])['data'];
            self::assertSame('business_user', $data['principal_type']);
            self::assertSame(TestFixtures::BUSINESS_USER_ID, $data['principal_id']);
            self::assertSame($client['grant'], $data['connection_id']);
            self::assertSame(['account:read'], $data['scopes']);
        }
    }

    public function testCapabilitiesGetOverHttpAndMcp(): void
    {
        $client = $this->connect($this->ownerSession(), ['account_get']);
        foreach (self::TRANSPORTS as $transport) {
            $data = $this->call($transport, $client, 'capabilities_get', [])['data'];
            $names = array_column($data['operations'], 'name');
            self::assertContains('account_get', $names, $transport);
            // The grant holds only account:read, so no write is advertised.
            self::assertNotContains('account_update', $names, $transport);
            self::assertNotContains('business_general_update', $names, $transport);
        }
    }

    public function testOperationGetOverHttpAndMcp(): void
    {
        $client = $this->connect($this->ownerSession(), ['account_get', 'account_update']);
        foreach (self::TRANSPORTS as $transport) {
            $version = $this->read($client['actor'], 'account_get', [])['version'];
            $write = $this->call($transport, $client, 'account_update', ['name' => 'Receipt ' . $transport, 'expected_version' => $version]);
            $data = $this->call($transport, $client, 'operation_get', ['receipt_id' => $write['receipt_id']])['data'];
            self::assertSame($write['receipt_id'], $data['id']);
            self::assertSame('account_update', $data['operation']);
            self::assertSame('committed', $data['status']);
            self::assertTrue($data['replay_available']);
        }
    }

    public function testConnectionGetOverHttpAndMcp(): void
    {
        $client = $this->connect($this->ownerSession(), ['account_get']);
        foreach (self::TRANSPORTS as $transport) {
            $data = $this->call($transport, $client, 'connection_get', [])['data'];
            self::assertSame($client['grant'], $data['id']);
            self::assertSame(['account:read'], $data['scopes']);
            self::assertSame([TestFixtures::BUSINESS_TENANT_ID], $data['business_ids']);
            self::assertArrayNotHasKey('token_hash', $data);
        }
    }

    public function testConnectionActivityOverHttpAndMcp(): void
    {
        $client = $this->connect($this->ownerSession(), ['account_get', 'account_update']);
        foreach (self::TRANSPORTS as $transport) {
            $version = $this->read($client['actor'], 'account_get', [])['version'];
            $write = $this->call($transport, $client, 'account_update', ['name' => 'Activity ' . $transport, 'expected_version' => $version]);
            $data = $this->call($transport, $client, 'connection_activity', ['limit' => 1])['data'];
            self::assertCount(1, $data['items'], $transport);
            self::assertSame('agent.account_update', $data['items'][0]['action']);
            self::assertSame($write['receipt_id'], $data['items'][0]['receipt_id']);
            // The second transport's write is the newest of two on this connection, so a second page remains.
            self::assertSame($transport === 'mcp', $data['next_cursor'] !== null, $transport);
        }
    }

    public function testConnectionSetupOverHttpAndMcp(): void
    {
        $client = $this->connect($this->ownerSession(), ['account_get']);
        foreach (self::TRANSPORTS as $transport) {
            $data = $this->call($transport, $client, 'connection_setup', [])['data'];
            self::assertTrue($data['requires_browser']);
            self::assertStringStartsWith(\App\Agent\Endpoints::baseUrl(), $data['browser_url']);
        }
    }

    /** Revocation ends the connection that asked, so each transport revokes its own. */
    public function testConnectionRevokeOverHttpAndMcp(): void
    {
        foreach (self::TRANSPORTS as $transport) {
            $client = $this->connect($this->ownerSession(), ['account_get']);
            $data = $this->call($transport, $client, 'connection_revoke', [])['data'];
            self::assertTrue($data['revoked']);
            self::assertNotNull(Database::query('SELECT revoked_at FROM agent_connections WHERE id=?', [$client['grant']])[0]['revoked_at']);
            $denied = $transport === 'http'
                ? $this->httpRaw($client, $this->registry->get('connection_get'), [])
                : $this->mcpRaw($client, 'connection_get', []);
            self::assertSame(401, $denied['status'], $denied['body']);
        }
    }
}
