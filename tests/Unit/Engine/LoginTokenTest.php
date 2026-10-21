<?php

declare(strict_types=1);

namespace Tests\Unit\Engine;

use App\Engine\Database;
use App\Engine\EnvLoader;
use App\Engine\LoginToken;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the LoginToken engine.
 *
 * Uses a real DB connection (like other Engine unit tests).
 * Cleans up login_tokens rows after each test.
 */
final class LoginTokenTest extends TestCase
{
    private const EMAIL = 'tokentest@example.com';
    private const IP = '127.0.0.1';

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 3) . '/vendor/autoload.php';
        \Tests\Support\TestEnvironment::boot();

        try {
            Database::connect();
            Database::query('SELECT 1');
        } catch (\Throwable) {
            self::markTestSkipped('Database not available');
        }
    }

    protected function tearDown(): void
    {
        Database::execute("DELETE FROM `login_tokens` WHERE `email` = ?", [self::EMAIL]);
    }

    // ── OTP ──

    public function testCreateOtpReturnsCode(): void
    {
        $result = LoginToken::createOtp(self::EMAIL, self::IP);
        $this->assertTrue($result['success']);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $result['code']);
    }

    public function testCreateOtpStoresHash(): void
    {
        $result = LoginToken::createOtp(self::EMAIL, self::IP);
        $expectedHash = hash('sha256', $result['code']);

        $row = Database::query(
            "SELECT `token_hash` FROM `login_tokens` WHERE `email` = ? AND `type` = 'otp' ORDER BY `created_at` DESC LIMIT 1",
            [self::EMAIL]
        );
        $this->assertNotEmpty($row);
        $this->assertSame($expectedHash, $row[0]['token_hash']);
    }

    public function testVerifyOtpSucceeds(): void
    {
        $create = LoginToken::createOtp(self::EMAIL, self::IP);
        $verify = LoginToken::verifyOtp(self::EMAIL, $create['code']);
        $this->assertTrue($verify['success']);
        $this->assertSame(self::EMAIL, $verify['email']);
    }

    public function testVerifyOtpFailsAfterUse(): void
    {
        $create = LoginToken::createOtp(self::EMAIL, self::IP);
        LoginToken::verifyOtp(self::EMAIL, $create['code']); // first use
        $verify2 = LoginToken::verifyOtp(self::EMAIL, $create['code']); // second use
        $this->assertFalse($verify2['success']);
        $this->assertSame('invalid_code', $verify2['error']);
    }

    public function testVerifyOtpFailsAfterExpiry(): void
    {
        $create = LoginToken::createOtp(self::EMAIL, self::IP);
        // Manually expire it
        Database::execute(
            "UPDATE `login_tokens` SET `expires_at` = '2020-01-01 00:00:00' WHERE `email` = ? AND `type` = 'otp'",
            [self::EMAIL]
        );
        $verify = LoginToken::verifyOtp(self::EMAIL, $create['code']);
        $this->assertFalse($verify['success']);
    }

    public function testVerifyOtpFailsWithWrongCode(): void
    {
        LoginToken::createOtp(self::EMAIL, self::IP);
        $verify = LoginToken::verifyOtp(self::EMAIL, '000000');
        $this->assertFalse($verify['success']);
        $this->assertSame('invalid_code', $verify['error']);
    }

    // ── Magic link ──

    public function testCreateMagicLinkReturnsToken(): void
    {
        $result = LoginToken::createMagicLink(self::EMAIL, self::IP);
        $this->assertTrue($result['success']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result['token']);
    }

    public function testVerifyMagicLinkSucceeds(): void
    {
        $create = LoginToken::createMagicLink(self::EMAIL, self::IP);
        $verify = LoginToken::verifyMagicLink($create['token']);
        $this->assertTrue($verify['success']);
        $this->assertSame(self::EMAIL, $verify['email']);
    }

    public function testVerifyMagicLinkFailsAfterUse(): void
    {
        $create = LoginToken::createMagicLink(self::EMAIL, self::IP);
        LoginToken::verifyMagicLink($create['token']); // first
        $verify2 = LoginToken::verifyMagicLink($create['token']); // second
        $this->assertFalse($verify2['success']);
    }

    public function testCreateMagicLinkInvalidatesPrevious(): void
    {
        $first  = LoginToken::createMagicLink(self::EMAIL, self::IP);
        $second = LoginToken::createMagicLink(self::EMAIL, self::IP);

        // First should be invalidated
        $verifyFirst = LoginToken::verifyMagicLink($first['token']);
        $this->assertFalse($verifyFirst['success'], 'First magic link should be invalidated');

        // Second should still work
        $verifySecond = LoginToken::verifyMagicLink($second['token']);
        $this->assertTrue($verifySecond['success']);
    }

    // ── Limits ──

    public function testOtpActiveTokenLimitEnforced(): void
    {
        // Create 3 active OTPs
        for ($i = 0; $i < 3; $i++) {
            $result = LoginToken::createOtp(self::EMAIL, self::IP);
            $this->assertTrue($result['success'], "OTP {$i} should succeed");
        }

        // 4th should fail
        $result = LoginToken::createOtp(self::EMAIL, self::IP);
        $this->assertFalse($result['success']);
        $this->assertSame('too_many_active', $result['error']);
    }

    public function testIssuanceFloodLimitEnforced(): void
    {
        // Create 5 tokens (mix of types)
        for ($i = 0; $i < 3; $i++) {
            LoginToken::createOtp(self::EMAIL, self::IP);
        }
        LoginToken::createMagicLink(self::EMAIL, self::IP);
        LoginToken::createMagicLink(self::EMAIL, self::IP);

        // 6th should be flood-limited
        $result = LoginToken::createOtp(self::EMAIL, self::IP);
        $this->assertFalse($result['success']);
        $this->assertSame('rate_limited', $result['error']);
    }

    // ── Cleanup ──

    public function testCleanupDeletesExpiredAndUsedTokens(): void
    {
        // Create fresh OTP (valid)
        $fresh = LoginToken::createOtp(self::EMAIL, self::IP);

        // Create and expire one
        $expired = LoginToken::createOtp(self::EMAIL, self::IP);
        Database::execute(
            "UPDATE `login_tokens` SET `expires_at` = '2020-01-01 00:00:00' WHERE `token_hash` = ?",
            [hash('sha256', $expired['code'])]
        );

        // Create and use one
        $used = LoginToken::createOtp(self::EMAIL, self::IP);
        LoginToken::verifyOtp(self::EMAIL, $used['code']);

        // Run cleanup
        LoginToken::cleanup();

        $remaining = Database::query(
            "SELECT COUNT(*) as cnt FROM `login_tokens` WHERE `email` = ?",
            [self::EMAIL]
        );
        $this->assertSame(1, (int) $remaining[0]['cnt'], 'Only the valid unused token should remain');
    }

    // ── Remember me ──

    public function testMagicLinkPreservesRememberMe(): void
    {
        $create = LoginToken::createMagicLink(self::EMAIL, self::IP, rememberMe: true);
        $verify = LoginToken::verifyMagicLink($create['token']);
        $this->assertTrue($verify['success']);
        $this->assertTrue($verify['remember_me']);
    }
}
