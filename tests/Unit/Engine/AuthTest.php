<?php

declare(strict_types=1);

namespace Tests\Unit\Engine;

use App\Engine\Auth;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the Auth engine.
 *
 * These tests verify session-based auth behavior without
 * touching a real database. DB-backed login tests live in
 * Tests\Integration\AuthFlowTest.
 */
final class AuthTest extends TestCase
{
    protected function setUp(): void
    {
        // Ensure clean session state for each test
        $_SESSION = [];
        Auth::reset();
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        Auth::reset();
    }

    // ── check() ──

    public function testCheckReturnsFalseWithNoSession(): void
    {
        $this->assertFalse(Auth::check());
    }

    public function testCheckReturnsFalseWhenAuthIdMissing(): void
    {
        $_SESSION['auth_type'] = 'operator';
        // No auth_id set

        $this->assertFalse(Auth::check());
    }

    public function testCheckReturnsFalseWhenAuthTypeMissing(): void
    {
        $_SESSION['auth_id'] = 'some-ulid';
        // No auth_type set

        $this->assertFalse(Auth::check());
    }

    public function testCheckReturnsTrueWhenSessionValid(): void
    {
        $_SESSION['auth_type'] = 'operator';
        $_SESSION['auth_id'] = '01HXYZ1234567890ABCDEF';
        $_SESSION['auth_name'] = 'Test Operator';
        $_SESSION['auth_email'] = 'op@example.com';
        $_SESSION['_last_activity'] = time();

        $this->assertTrue(Auth::check());
    }

    public function testCheckReturnsFalseWhenSessionExpired(): void
    {
        $_SESSION['auth_type'] = 'operator';
        $_SESSION['auth_id'] = '01HXYZ1234567890ABCDEF';
        $_SESSION['auth_name'] = 'Test Operator';
        $_SESSION['auth_email'] = 'op@example.com';
        // 9 hours ago — exceeds 8-hour expiry
        $_SESSION['_last_activity'] = time() - (9 * 3600);

        $this->assertFalse(Auth::check());
    }

    public function testCheckReturnsTrueWhenActivityWithinWindow(): void
    {
        $_SESSION['auth_type'] = 'operator';
        $_SESSION['auth_id'] = '01HXYZ1234567890ABCDEF';
        $_SESSION['auth_name'] = 'Test Operator';
        $_SESSION['auth_email'] = 'op@example.com';
        // 7 hours ago — within 8-hour window
        $_SESSION['_last_activity'] = time() - (7 * 3600);

        $this->assertTrue(Auth::check());
    }

    // ── user() ──

    public function testUserReturnsNullWhenNotAuthenticated(): void
    {
        $this->assertNull(Auth::user());
    }

    public function testUserReturnsOperatorData(): void
    {
        $_SESSION['auth_type'] = 'operator';
        $_SESSION['auth_id'] = '01HXYZ1234567890ABCDEF';
        $_SESSION['auth_name'] = 'Jane Operator';
        $_SESSION['auth_email'] = 'jane@example.com';
        $_SESSION['_last_activity'] = time();

        $user = Auth::user();

        $this->assertNotNull($user);
        $this->assertSame('operator', $user['type']);
        $this->assertSame('01HXYZ1234567890ABCDEF', $user['id']);
        $this->assertSame('Jane Operator', $user['name']);
        $this->assertSame('jane@example.com', $user['email']);
    }

    public function testUserReturnsBusinessUserData(): void
    {
        $_SESSION['auth_type'] = 'business_user';
        $_SESSION['auth_id'] = '01HABC5678901234UVWXYZ';
        $_SESSION['auth_name'] = 'Bob Owner';
        $_SESSION['auth_email'] = 'bob@salon.com';
        $_SESSION['auth_tenant_id'] = '01HTENANT123456789ABC';
        $_SESSION['auth_role'] = 'owner';
        $_SESSION['_last_activity'] = time();

        $user = Auth::user();

        $this->assertNotNull($user);
        $this->assertSame('business_user', $user['type']);
        $this->assertSame('01HABC5678901234UVWXYZ', $user['id']);
        $this->assertSame('01HTENANT123456789ABC', $user['tenant_id']);
        $this->assertSame('owner', $user['role']);
    }

    // ── isOperator() / isBusinessUser() ──

    public function testIsOperatorReturnsTrueForOperator(): void
    {
        $_SESSION['auth_type'] = 'operator';
        $_SESSION['auth_id'] = '01HXYZ1234567890ABCDEF';
        $_SESSION['_last_activity'] = time();

        $this->assertTrue(Auth::isOperator());
        $this->assertFalse(Auth::isBusinessUser());
    }

    public function testIsBusinessUserReturnsTrueForBusinessUser(): void
    {
        $_SESSION['auth_type'] = 'business_user';
        $_SESSION['auth_id'] = '01HABC5678901234UVWXYZ';
        $_SESSION['auth_tenant_id'] = '01HTENANT123456789ABC';
        $_SESSION['auth_role'] = 'owner';
        $_SESSION['_last_activity'] = time();

        $this->assertTrue(Auth::isBusinessUser());
        $this->assertFalse(Auth::isOperator());
    }

    public function testIsOperatorReturnsFalseWhenNotAuthenticated(): void
    {
        $this->assertFalse(Auth::isOperator());
    }

    // ── logout() ──

    public function testLogoutClearsSessionData(): void
    {
        $_SESSION['auth_type'] = 'operator';
        $_SESSION['auth_id'] = '01HXYZ1234567890ABCDEF';
        $_SESSION['auth_name'] = 'Test Operator';
        $_SESSION['auth_email'] = 'op@example.com';
        $_SESSION['_last_activity'] = time();

        Auth::clearSession();

        $this->assertArrayNotHasKey('auth_type', $_SESSION);
        $this->assertArrayNotHasKey('auth_id', $_SESSION);
        $this->assertArrayNotHasKey('auth_name', $_SESSION);
        $this->assertArrayNotHasKey('auth_email', $_SESSION);
        $this->assertFalse(Auth::check());
    }

    // ── businessUserRole() ──

    public function testBusinessUserRoleReturnsRole(): void
    {
        $_SESSION['auth_type'] = 'business_user';
        $_SESSION['auth_id'] = '01HABC5678901234UVWXYZ';
        $_SESSION['auth_tenant_id'] = '01HTENANT123456789ABC';
        $_SESSION['auth_role'] = 'manager';
        $_SESSION['_last_activity'] = time();

        $this->assertSame('manager', Auth::businessUserRole());
    }

    public function testBusinessUserRoleReturnsNullForOperator(): void
    {
        $_SESSION['auth_type'] = 'operator';
        $_SESSION['auth_id'] = '01HXYZ1234567890ABCDEF';
        $_SESSION['_last_activity'] = time();

        $this->assertNull(Auth::businessUserRole());
    }

    // ── Session expiry edge cases ──

    public function testCheckUpdatesLastActivityOnValidSession(): void
    {
        $oldTime = time() - 3600; // 1 hour ago
        $_SESSION['auth_type'] = 'operator';
        $_SESSION['auth_id'] = '01HXYZ1234567890ABCDEF';
        $_SESSION['_last_activity'] = $oldTime;

        $this->assertTrue(Auth::check());

        // Last activity should be updated to current time
        $this->assertGreaterThan($oldTime, $_SESSION['_last_activity']);
    }

    public function testCheckClearsExpiredSession(): void
    {
        $_SESSION['auth_type'] = 'operator';
        $_SESSION['auth_id'] = '01HXYZ1234567890ABCDEF';
        $_SESSION['_last_activity'] = time() - (9 * 3600);

        Auth::check(); // Should clear expired session

        $this->assertArrayNotHasKey('auth_type', $_SESSION);
        $this->assertArrayNotHasKey('auth_id', $_SESSION);
    }

    // ── setSession() for login flow ──

    public function testSetOperatorSession(): void
    {
        Auth::setSession('operator', '01HXYZ1234567890ABCDEF', 'Jane', 'jane@example.com');

        $this->assertTrue(Auth::check());
        $this->assertTrue(Auth::isOperator());
        $this->assertSame('01HXYZ1234567890ABCDEF', Auth::user()['id']);
        $this->assertSame('Jane', Auth::user()['name']);
    }

    public function testSetBusinessUserSession(): void
    {
        Auth::setSession(
            'business_user',
            '01HABC5678901234UVWXYZ',
            'Bob',
            'bob@salon.com',
            '01HTENANT123456789ABC',
            'owner'
        );

        $this->assertTrue(Auth::check());
        $this->assertTrue(Auth::isBusinessUser());
        $this->assertSame('01HTENANT123456789ABC', Auth::user()['tenant_id']);
        $this->assertSame('owner', Auth::user()['role']);
    }

    // ── isOwner() / isManager() ──

    public function testIsOwnerReturnsTrueForOwner(): void
    {
        Auth::setSession('business_user', 'bu-001', 'Alice', 'alice@test.com', 'tenant-001', 'owner');

        $this->assertTrue(Auth::isOwner());
        $this->assertFalse(Auth::isManager());
    }

    public function testIsManagerReturnsTrueForManager(): void
    {
        Auth::setSession('business_user', 'bu-002', 'Bob', 'bob@test.com', 'tenant-001', 'manager');

        $this->assertTrue(Auth::isManager());
        $this->assertFalse(Auth::isOwner());
    }

    public function testIsOwnerReturnsFalseForOperator(): void
    {
        Auth::setSession('operator', 'op-001', 'Jane', 'jane@test.com');

        $this->assertFalse(Auth::isOwner());
        $this->assertFalse(Auth::isManager());
    }

    // ── tenantId() ──

    public function testTenantIdReturnsIdForBusinessUser(): void
    {
        Auth::setSession('business_user', 'bu-001', 'Alice', 'alice@test.com', 'tenant-001', 'owner');

        $this->assertSame('tenant-001', Auth::tenantId());
    }

    public function testTenantIdReturnsNullForOperator(): void
    {
        Auth::setSession('operator', 'op-001', 'Jane', 'jane@test.com');

        $this->assertNull(Auth::tenantId());
    }

    // ── canAccessTenant() ──

    public function testOperatorCanAccessAnyTenant(): void
    {
        Auth::setSession('operator', 'op-001', 'Jane', 'jane@test.com');

        $this->assertTrue(Auth::canAccessTenant('tenant-001'));
        $this->assertTrue(Auth::canAccessTenant('tenant-999'));
    }

    public function testBusinessUserCanAccessOwnTenant(): void
    {
        Auth::setSession('business_user', 'bu-001', 'Alice', 'alice@test.com', 'tenant-001', 'owner');

        $this->assertTrue(Auth::canAccessTenant('tenant-001'));
    }

    public function testBusinessUserCannotAccessOtherTenant(): void
    {
        Auth::setSession('business_user', 'bu-001', 'Alice', 'alice@test.com', 'tenant-001', 'owner');

        $this->assertFalse(Auth::canAccessTenant('tenant-002'));
    }

    public function testUnauthenticatedCannotAccessTenant(): void
    {
        $this->assertFalse(Auth::canAccessTenant('tenant-001'));
    }

    // ── canManageTenant() ──

    public function testOperatorCanManageTenant(): void
    {
        Auth::setSession('operator', 'op-001', 'Jane', 'jane@test.com');

        $this->assertTrue(Auth::canManageTenant());
    }

    public function testOwnerCanManageTenant(): void
    {
        Auth::setSession('business_user', 'bu-001', 'Alice', 'alice@test.com', 'tenant-001', 'owner');

        $this->assertTrue(Auth::canManageTenant());
    }

    public function testManagerCannotManageTenant(): void
    {
        Auth::setSession('business_user', 'bu-002', 'Bob', 'bob@test.com', 'tenant-001', 'manager');

        $this->assertFalse(Auth::canManageTenant());
    }

    // ── remember_me ──

    public function testCheckSurvivesWithRememberMe(): void
    {
        $_SESSION['auth_type'] = 'operator';
        $_SESSION['auth_id'] = '01HXYZ1234567890ABCDEF';
        $_SESSION['auth_name'] = 'Test Operator';
        $_SESSION['auth_email'] = 'op@example.com';
        $_SESSION['_last_activity'] = time() - (12 * 3600); // 12h ago — past 8h timeout
        $_SESSION['remember_me'] = true;

        $this->assertTrue(Auth::check(), 'remember_me should skip inactivity timeout');
    }

    public function testClearSessionRemovesRememberMe(): void
    {
        $_SESSION['auth_type'] = 'operator';
        $_SESSION['auth_id'] = '01HXYZ1234567890ABCDEF';
        $_SESSION['_last_activity'] = time();
        $_SESSION['remember_me'] = true;

        Auth::clearSession();

        $this->assertArrayNotHasKey('remember_me', $_SESSION);
    }

    // ── loginByEmail() ──
    // These tests require DB. Skipped if DB unavailable.

    private function ensureDb(): void
    {
        try {
            \Tests\Support\TestEnvironment::boot();
            \App\Engine\Database::connect();
            \App\Engine\Database::query('SELECT 1');
        } catch (\Throwable) {
            $this->markTestSkipped('Database not available');
        }
    }

    public function testLoginByEmailSucceedsForOperator(): void
    {
        $this->ensureDb();
        \Tests\Integration\TestFixtures::provision();

        $result = Auth::loginByEmail('operator@example.com');
        $this->assertTrue($result['success']);
        $this->assertSame('operator', $_SESSION['auth_type'] ?? null);
    }

    public function testLoginByEmailSucceedsForBusinessUser(): void
    {
        $this->ensureDb();
        \Tests\Integration\TestFixtures::provision();

        $result = Auth::loginByEmail('business@example.com');
        $this->assertTrue($result['success']);
        $this->assertSame('business_user', $_SESSION['auth_type'] ?? null);
    }

    public function testLoginByEmailFailsForUnknownEmail(): void
    {
        $this->ensureDb();

        $result = Auth::loginByEmail('nonexistent@example.com');
        $this->assertFalse($result['success']);
    }
}
