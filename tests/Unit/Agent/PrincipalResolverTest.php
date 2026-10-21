<?php

declare(strict_types=1);
namespace Tests\Unit\Agent;
use App\Agent\PrincipalResolver;
use App\Agent\OperationException;
use PDO;
use PHPUnit\Framework\TestCase;

final class PrincipalResolverTest extends TestCase
{
    private PDO $db;
    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->exec("CREATE TABLE business_users (id TEXT, email TEXT, role TEXT, is_active INTEGER, tenant_id TEXT); CREATE TABLE tenants (id TEXT, status TEXT); INSERT INTO business_users VALUES ('u','owner@example.test','owner',1,'a'); INSERT INTO tenants VALUES ('a','active'),('b','active')");
    }
    public function testCurrentRoleAndMembershipOverrideOldTokenAuthority(): void
    {
        $resolver = new PrincipalResolver($this->db);
        $first = $resolver->resolve('business_user', 'u', ['a','b'], ['business-settings:manage']);
        self::assertSame(['a'], $first->businessIds);
        self::assertSame('business_owner', $first->role);
        $this->db->exec("UPDATE business_users SET role = 'manager'");
        self::assertSame('business_manager', $resolver->resolve('business_user', 'u', ['a'], [])->role);
        $this->db->exec("UPDATE tenants SET status='archived' WHERE id='a'");
        self::assertSame([], $resolver->resolve('business_user', 'u', ['a'], [])->businessIds);
    }
    public function testDeactivationInvalidatesIdentity(): void
    {
        $this->db->exec('UPDATE business_users SET is_active = 0');
        $this->expectException(OperationException::class);
        (new PrincipalResolver($this->db))->resolve('business_user', 'u', ['a'], []);
    }
    public function testEmailChangeRequiresNewConsent(): void
    {
        $hash = PrincipalResolver::identityHash('owner@example.test');
        $this->db->exec("UPDATE business_users SET email = 'different@example.test'");
        $this->expectException(OperationException::class);
        (new PrincipalResolver($this->db))->resolve('business_user', 'u', ['a'], [], $hash);
    }
}
