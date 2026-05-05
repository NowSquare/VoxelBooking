<?php

declare(strict_types=1);

namespace Tests\Unit\Engine;

use App\Engine\DemoMode;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the DemoMode sentinel engine.
 *
 * IMPORTANT: Sentinel detection tests use a temporary directory to avoid
 * deleting the real `.demo` file from the application root. The root `.demo`
 * sentinel must never be removed by tests — it is required for browser
 * testing of demo mode.
 *
 * The denylist/write-allowed tests are pure logic — they don't depend on
 * sentinel state and use the app root only for path assertions.
 */
final class DemoModeTest extends TestCase
{
    /** Application root (for path assertions, never modified). */
    private string $appRoot;

    /** Isolated temp directory (for sentinel file operations). */
    private string $tempDir;

    protected function setUp(): void
    {
        $this->appRoot = dirname(__DIR__, 3);
        $this->tempDir = sys_get_temp_dir() . '/vb-demo-test-' . mt_rand();
        @mkdir($this->tempDir, 0755, true);

        DemoMode::reset();
    }

    protected function tearDown(): void
    {
        // Clean up temp sentinel only — never touch the real root .demo
        @unlink($this->tempDir . '/.demo');
        @rmdir($this->tempDir);
        DemoMode::reset();
    }

    // ════════════════════════════════════════════════════════════════
    // Sentinel Detection (isolated temp directory)
    // ════════════════════════════════════════════════════════════════

    public function testIsActiveReturnsFalseWhenNoSentinel(): void
    {
        // Temp dir has no .demo file
        DemoMode::init($this->tempDir);
        $this->assertFalse(DemoMode::isActive());
    }

    public function testIsActiveReturnsTrueWhenSentinelExists(): void
    {
        touch($this->tempDir . '/.demo');
        DemoMode::init($this->tempDir);
        $this->assertTrue(DemoMode::isActive());
    }

    public function testIsActiveCachesResult(): void
    {
        touch($this->tempDir . '/.demo');
        DemoMode::init($this->tempDir);
        $this->assertTrue(DemoMode::isActive());

        // Remove sentinel — result should still be cached
        unlink($this->tempDir . '/.demo');
        $this->assertTrue(DemoMode::isActive());

        // After reset, it should re-check
        DemoMode::reset();
        DemoMode::init($this->tempDir);
        $this->assertFalse(DemoMode::isActive());
    }

    // ════════════════════════════════════════════════════════════════
    // Paths (use app root for correct path assertions)
    // ════════════════════════════════════════════════════════════════

    public function testSentinelPathReturnsCorrectLocation(): void
    {
        DemoMode::init($this->appRoot);
        $expected = $this->appRoot . '/.demo';
        $this->assertSame($expected, DemoMode::sentinelPath());
    }


    // ════════════════════════════════════════════════════════════════
    // Read requests: always allowed (pure logic — no file IO)
    // ════════════════════════════════════════════════════════════════

    public function testGetRequestsAreAlwaysAllowed(): void
    {
        $this->assertTrue(DemoMode::isWriteAllowed('GET', '/admin/settings'));
        $this->assertTrue(DemoMode::isWriteAllowed('GET', '/admin'));
        $this->assertTrue(DemoMode::isWriteAllowed('GET', '/book/demo'));
    }

    public function testHeadRequestsAreAlwaysAllowed(): void
    {
        $this->assertTrue(DemoMode::isWriteAllowed('HEAD', '/admin/settings'));
    }

    public function testOptionsRequestsAreAlwaysAllowed(): void
    {
        $this->assertTrue(DemoMode::isWriteAllowed('OPTIONS', '/admin/settings'));
    }

    // ════════════════════════════════════════════════════════════════
    // Interactive demo: allowed write operations
    // ════════════════════════════════════════════════════════════════

    public function testLoginPostIsAllowed(): void
    {
        $this->assertTrue(DemoMode::isWriteAllowed('POST', '/admin/login'));
    }

    public function testLogoutPostIsAllowed(): void
    {
        $this->assertTrue(DemoMode::isWriteAllowed('POST', '/auth/logout'));
    }

    public function testOtpRequestCodeIsAllowed(): void
    {
        $this->assertTrue(DemoMode::isWriteAllowed('POST', '/admin/login/request-code'));
    }

    public function testOtpVerifyCodeIsAllowed(): void
    {
        $this->assertTrue(DemoMode::isWriteAllowed('POST', '/admin/login/verify-code'));
    }

    public function testForgotPasswordPostIsAllowed(): void
    {
        $this->assertTrue(DemoMode::isWriteAllowed('POST', '/admin/forgot-password'));
    }

    public function testBookingPostIsAllowed(): void
    {
        $this->assertTrue(DemoMode::isWriteAllowed('POST', '/api/demo/bookings'));
    }

    public function testBookingCancelIsAllowed(): void
    {
        $this->assertTrue(DemoMode::isWriteAllowed('POST', '/api/demo/bookings/abc/cancel'));
    }

    public function testBookingRescheduleIsAllowed(): void
    {
        $this->assertTrue(DemoMode::isWriteAllowed('POST', '/api/demo/bookings/abc/reschedule'));
    }

    public function testAdminBookingStatusChangeIsAllowed(): void
    {
        $this->assertTrue(DemoMode::isWriteAllowed('POST', '/admin/bookings/abc/status'));
    }

    public function testAdminBookingRescheduleIsAllowed(): void
    {
        $this->assertTrue(DemoMode::isWriteAllowed('POST', '/admin/bookings/abc/reschedule'));
    }

    public function testTenantBookingCreateIsAllowed(): void
    {
        $this->assertTrue(DemoMode::isWriteAllowed('POST', '/admin/tenants/t1/bookings/create'));
    }

    public function testTenantBookingStatusIsAllowed(): void
    {
        $this->assertTrue(DemoMode::isWriteAllowed('POST', '/admin/tenants/t1/bookings/b1/status'));
    }

    public function testTenantBookingRescheduleIsAllowed(): void
    {
        $this->assertTrue(DemoMode::isWriteAllowed('POST', '/admin/tenants/t1/bookings/b1/reschedule'));
    }

    public function testImpersonationIsAllowed(): void
    {
        $this->assertTrue(DemoMode::isWriteAllowed('POST', '/admin/tenants/t1/impersonate'));
        $this->assertTrue(DemoMode::isWriteAllowed('POST', '/admin/impersonate/exit'));
    }

    public function testTenantArchiveActivateIsAllowed(): void
    {
        $this->assertTrue(DemoMode::isWriteAllowed('POST', '/admin/tenants/t1/archive'));
        $this->assertTrue(DemoMode::isWriteAllowed('POST', '/admin/tenants/t1/activate'));
    }

    public function testStaffActivateDeactivateIsAllowed(): void
    {
        $this->assertTrue(DemoMode::isWriteAllowed('POST', '/admin/tenants/t1/staff/s1/activate'));
        $this->assertTrue(DemoMode::isWriteAllowed('POST', '/admin/tenants/t1/staff/s1/deactivate'));
    }

    public function testServiceActivateDeactivateIsAllowed(): void
    {
        $this->assertTrue(DemoMode::isWriteAllowed('POST', '/admin/tenants/t1/services/s1/activate'));
        $this->assertTrue(DemoMode::isWriteAllowed('POST', '/admin/tenants/t1/services/s1/deactivate'));
    }

    public function testPrivacyActionIsAllowed(): void
    {
        $this->assertTrue(DemoMode::isWriteAllowed('POST', '/book/demo/privacy/cust-id'));
    }

    // ════════════════════════════════════════════════════════════════
    // Blocked write operations (denylist)
    // ════════════════════════════════════════════════════════════════

    public function testSystemSettingsPostIsBlocked(): void
    {
        $this->assertFalse(DemoMode::isWriteAllowed('POST', '/admin/settings'));
    }

    public function testEmailSettingsPostIsBlocked(): void
    {
        $this->assertFalse(DemoMode::isWriteAllowed('POST', '/admin/settings/email'));
    }

    public function testCronRunIsBlocked(): void
    {
        $this->assertFalse(DemoMode::isWriteAllowed('POST', '/admin/settings/cron/run'));
    }

    public function testInstallWizardIsBlocked(): void
    {
        $this->assertFalse(DemoMode::isWriteAllowed('POST', '/install/step/2'));
        $this->assertFalse(DemoMode::isWriteAllowed('POST', '/install/step/3'));
        $this->assertFalse(DemoMode::isWriteAllowed('POST', '/install/step/4'));
        $this->assertFalse(DemoMode::isWriteAllowed('POST', '/install/step/5'));
        $this->assertFalse(DemoMode::isWriteAllowed('POST', '/install/complete'));
    }

    public function testUpdatesAreBlocked(): void
    {
        $this->assertFalse(DemoMode::isWriteAllowed('POST', '/admin/updates/upload'));
        $this->assertFalse(DemoMode::isWriteAllowed('POST', '/admin/updates/apply'));
    }

    public function testAccountSaveIsBlocked(): void
    {
        $this->assertFalse(DemoMode::isWriteAllowed('POST', '/admin/account'));
    }

    public function testDeletionQueueConfirmIsBlocked(): void
    {
        $this->assertFalse(DemoMode::isWriteAllowed('POST', '/admin/deletion-queue/confirm'));
    }

    public function testRequestAccessIsBlocked(): void
    {
        $this->assertFalse(DemoMode::isWriteAllowed('POST', '/request-access'));
    }

    public function testTenantSettingsAreBlocked(): void
    {
        $this->assertFalse(DemoMode::isWriteAllowed('POST', '/admin/tenants/t1/settings'));
        $this->assertFalse(DemoMode::isWriteAllowed('POST', '/admin/tenants/t1/settings/branding'));
        $this->assertFalse(DemoMode::isWriteAllowed('POST', '/admin/tenants/t1/settings/bookingpage'));
        $this->assertFalse(DemoMode::isWriteAllowed('POST', '/admin/tenants/t1/settings/booking'));
        $this->assertFalse(DemoMode::isWriteAllowed('POST', '/admin/tenants/t1/settings/privacy'));
        $this->assertFalse(DemoMode::isWriteAllowed('POST', '/admin/tenants/t1/settings/notifications'));
        $this->assertFalse(DemoMode::isWriteAllowed('POST', '/admin/tenants/t1/settings/emails'));
        $this->assertFalse(DemoMode::isWriteAllowed('POST', '/admin/tenants/t1/settings/embed'));
    }

    public function testTenantUserManagementIsBlocked(): void
    {
        $this->assertFalse(DemoMode::isWriteAllowed('POST', '/admin/tenants/t1/users/invite'));
        $this->assertFalse(DemoMode::isWriteAllowed('POST', '/admin/tenants/t1/users/u1/deactivate'));
        $this->assertFalse(DemoMode::isWriteAllowed('POST', '/admin/tenants/t1/users/u1/activate'));
    }

    // ════════════════════════════════════════════════════════════════
    // Email suppression
    // ════════════════════════════════════════════════════════════════

    public function testDemoDomainsAreSuppressed(): void
    {
        $this->assertTrue(DemoMode::isEmailSuppressed('bob@example.com'));
        $this->assertTrue(DemoMode::isEmailSuppressed('alice@example.org'));
        $this->assertTrue(DemoMode::isEmailSuppressed('test@example.net'));
        $this->assertTrue(DemoMode::isEmailSuppressed('hello@demostudio.example'));
        $this->assertTrue(DemoMode::isEmailSuppressed('info@hotelmarina.example'));
        $this->assertTrue(DemoMode::isEmailSuppressed('foo@invalid'));
        $this->assertTrue(DemoMode::isEmailSuppressed('bar@localhost'));
        $this->assertTrue(DemoMode::isEmailSuppressed('demo@booking.test'));
        $this->assertTrue(DemoMode::isEmailSuppressed('demo@voxelbooking.test'));
        $this->assertTrue(DemoMode::isEmailSuppressed('demo@anything.test'));
    }

    public function testRealDomainsAreNotSuppressed(): void
    {
        $this->assertFalse(DemoMode::isEmailSuppressed('reviewer@gmail.com'));
        $this->assertFalse(DemoMode::isEmailSuppressed('test@mycompany.io'));
        $this->assertFalse(DemoMode::isEmailSuppressed('user@outlook.com'));
        $this->assertFalse(DemoMode::isEmailSuppressed('demo@protonmail.com'));
    }

    // ════════════════════════════════════════════════════════════════
    // Reset (isolated temp directory)
    // ════════════════════════════════════════════════════════════════

    public function testResetClearsCache(): void
    {
        touch($this->tempDir . '/.demo');
        DemoMode::init($this->tempDir);
        $this->assertTrue(DemoMode::isActive());

        unlink($this->tempDir . '/.demo');
        DemoMode::reset();
        DemoMode::init($this->tempDir);
        $this->assertFalse(DemoMode::isActive());
    }

    // ════════════════════════════════════════════════════════════════
    // Controller: forgot-password in demo mode (allowed)
    // ════════════════════════════════════════════════════════════════

    public function testForgotPasswordPageAccessibleInDemoMode(): void
    {
        // In the new interactive demo model, forgot-password is accessible.
        // The OTP/email flows work normally — email to demo domains is suppressed
        // by Mailer::send(), not by blocking the route.
        $this->assertTrue(DemoMode::isWriteAllowed('POST', '/admin/forgot-password'));
    }
}
