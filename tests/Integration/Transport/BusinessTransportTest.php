<?php

declare(strict_types=1);

namespace Tests\Integration\Transport;

use App\Agent\ActorContext;
use App\Engine\{Database, Ulid};
use Tests\Support\Transport\TransportTestCase;

/** Operator business, team and application commands over HTTP v2 and native MCP. */
final class BusinessTransportTest extends TransportTestCase
{
    private string $business;
    private ActorContext $session;
    private ?string $applicationsSetting = null;
    private array $applicationEmails = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->business = $this->tenant('timeslot')['id'];
        $this->session = $this->operatorSession([$this->business]);
        $this->applicationsSetting = Database::query("SELECT value FROM settings WHERE `key`='enable_applications'")[0]['value'] ?? null;
    }

    protected function tearDown(): void
    {
        foreach ($this->applicationEmails as $email) {
            Database::execute('DELETE FROM business_applications WHERE email=?', [$email]);
        }
        $this->applicationsSetting === null
            ? Database::execute("DELETE FROM settings WHERE `key`='enable_applications'")
            : Database::upsertSetting('enable_applications', $this->applicationsSetting);
        parent::tearDown();
    }

    public function testBusinessCreateOverHttpAndMcp(): void
    {
        $client = $this->connect($this->session, ['business_create']);
        foreach (self::TRANSPORTS as $transport) {
            $name = 'Created over ' . $transport . ' ' . Ulid::generate();
            $data = $this->call($transport, $client, 'business_create', ['name' => $name, 'email' => 'created-' . $transport . '@example.test', 'booking_pattern' => 'resource'])['data'];
            $this->tenantIds[] = $data['id'];
            self::assertSame($name, $data['name']);
            self::assertSame('resource', $data['booking_pattern']);
            $stored = Database::query('SELECT name, booking_pattern, status FROM tenants WHERE id=?', [$data['id']])[0];
            self::assertSame(['name' => $name, 'booking_pattern' => 'resource', 'status' => 'active'], $stored);
        }
    }

    public function testBusinessUpdateOverHttpAndMcp(): void
    {
        $client = $this->connect($this->session, ['business_get', 'business_update']);
        foreach (self::TRANSPORTS as $transport) {
            $before = $this->read($this->session, 'business_get', ['business_id' => $this->business]);
            $data = $this->call($transport, $client, 'business_update', ['business_id' => $this->business, 'expected_version' => $before['version'], 'name' => 'Updated over ' . $transport])['data'];
            self::assertSame('Updated over ' . $transport, $data['name']);
            self::assertSame($data, $this->read($this->session, 'business_get', ['business_id' => $this->business]));
        }
    }

    /** Archiving ends a business's commands, so each transport archives its own business. */
    public function testBusinessSetStatusOverHttpAndMcp(): void
    {
        $businesses = ['http' => $this->business, 'mcp' => $this->tenant('event')['id']];
        $session = $this->operatorSession(array_values($businesses));
        $client = $this->connect($session, ['business_get', 'business_set_status']);
        foreach (self::TRANSPORTS as $transport) {
            $before = $this->read($session, 'business_get', ['business_id' => $businesses[$transport]]);
            $data = $this->call($transport, $client, 'business_set_status', ['business_id' => $businesses[$transport], 'expected_version' => $before['version'], 'status' => 'archived'])['data'];
            self::assertSame('archived', $data['status']);
            self::assertSame('archived', Database::query('SELECT status FROM tenants WHERE id=?', [$businesses[$transport]])[0]['status']);
        }
    }

    public function testTeamInviteOverHttpAndMcp(): void
    {
        $client = $this->connect($this->session, ['team_invite', 'team_member_get']);
        foreach (self::TRANSPORTS as $transport) {
            $email = 'manager-' . $transport . '-' . strtolower(Ulid::generate()) . '@example.test';
            $data = $this->call($transport, $client, 'team_invite', ['business_id' => $this->business, 'name' => 'Manager ' . $transport, 'email' => $email, 'role' => 'manager'])['data'];
            self::assertSame($email, $data['email']);
            self::assertSame('manager', $data['role']);
            self::assertTrue($data['is_active']);
            self::assertSame($data, $this->read($this->session, 'team_member_get', ['business_id' => $this->business, 'id' => $data['id']]));
        }
    }

    public function testTeamMemberSetActiveOverHttpAndMcp(): void
    {
        $client = $this->connect($this->session, ['team_member_get', 'team_member_set_active']);
        $member = $this->seedCommand($this->session, 'team_invite', ['business_id' => $this->business, 'name' => 'Toggled manager',
            'email' => 'toggle-' . strtolower(Ulid::generate()) . '@example.test', 'role' => 'manager'])['data'];
        // HTTP deactivates the member, then MCP reactivates them.
        foreach (['http' => false, 'mcp' => true] as $transport => $active) {
            $data = $this->call($transport, $client, 'team_member_set_active', ['business_id' => $this->business, 'id' => $member['id'], 'expected_version' => $member['version'], 'is_active' => $active])['data'];
            self::assertSame($active, $data['is_active'], $transport);
            self::assertSame($active, (bool) Database::query('SELECT is_active FROM business_users WHERE id=?', [$member['id']])[0]['is_active']);
            $member = $data;
        }
    }

    public function testApplicationSubmitOverHttpAndMcp(): void
    {
        Database::upsertSetting('enable_applications', '1');
        foreach (self::TRANSPORTS as $transport) {
            $client = $this->publicPlatformSession();
            $email = 'applicant-' . $transport . '-' . strtolower(Ulid::generate()) . '@example.test';
            $this->applicationEmails[] = $email;
            $data = $this->call($transport, $client, 'application_submit', ['business_name' => 'Requested over ' . $transport, 'contact_name' => 'Applicant', 'email' => $email])['data'];
            self::assertSame(['accepted' => true, 'status' => 'received'], $data);
            $stored = Database::query('SELECT business_name, status FROM business_applications WHERE email=?', [$email]);
            self::assertSame([['business_name' => 'Requested over ' . $transport, 'status' => 'pending']], $stored);
        }
    }

    public function testApplicationReviewOverHttpAndMcp(): void
    {
        Database::upsertSetting('enable_applications', '1');
        $client = $this->connect($this->session, ['application_get', 'application_review']);
        foreach (['http' => 'approved', 'mcp' => 'rejected'] as $transport => $decision) {
            $email = 'reviewed-' . $transport . '-' . strtolower(Ulid::generate()) . '@example.test';
            $this->applicationEmails[] = $email;
            $this->seedCommand($this->session, 'application_submit', ['business_name' => 'Reviewed over ' . $transport, 'contact_name' => 'Applicant', 'email' => $email]);
            $id = Database::query('SELECT id FROM business_applications WHERE email=?', [$email])[0]['id'];
            $request = $this->read($this->session, 'application_get', ['id' => $id]);
            $tenants = (int) Database::query('SELECT COUNT(*) AS n FROM tenants')[0]['n'];
            $data = $this->call($transport, $client, 'application_review', ['id' => $id, 'expected_version' => $request['version'], 'decision' => $decision, 'admin_notes' => 'Reviewed over ' . $transport])['data'];
            self::assertSame($decision, $data['status']);
            self::assertSame('Reviewed over ' . $transport, $data['admin_notes']);
            self::assertSame($decision, Database::query('SELECT status FROM business_applications WHERE id=?', [$id])[0]['status']);
            // Review is a decision only; provisioning stays a separate command.
            self::assertSame($tenants, (int) Database::query('SELECT COUNT(*) AS n FROM tenants')[0]['n']);
        }
    }
}
