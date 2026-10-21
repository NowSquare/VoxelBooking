<?php

declare(strict_types=1);

namespace Tests\Unit\Engine;

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for welcome email sender classification (admin onboarding identity).
 *
 * Tests the resolveWelcomeReplyTo() private static method via reflection.
 * This method controls the sender metadata passed to Mailer::send():
 * - fromName:     always null (uses global platform From name)
 * - replyToEmail: inviter's email
 * - replyToName:  cascade: inviter name → email local part → app name
 *
 * No SMTP, no database — pure computation tests.
 */
final class WelcomeEmailSenderTest extends TestCase
{
    private static \ReflectionMethod $resolve;

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

        $rc = new \ReflectionClass(\App\Engine\Mailer::class);
        self::$resolve = $rc->getMethod('resolveWelcomeReplyTo');
    }

    private function resolve(?string $email, ?string $name, string $appName = 'VoxelBooking'): array
    {
        return self::$resolve->invoke(null, $email, $name, $appName);
    }

    // ═══════════════════════════════════════════════════
    // fromName — always null (admin onboarding = platform identity)
    // ═══════════════════════════════════════════════════

    public function test_from_name_is_always_null(): void
    {
        $result = $this->resolve('admin@salon.com', 'Admin User');
        $this->assertNull($result['fromName'], 'Admin onboarding From name must be null (uses global platform name)');
    }

    public function test_from_name_null_even_when_no_operator(): void
    {
        $result = $this->resolve(null, null);
        $this->assertNull($result['fromName']);
    }

    // ═══════════════════════════════════════════════════
    // replyToEmail — passthrough of operator email
    // ═══════════════════════════════════════════════════

    public function test_reply_to_email_uses_operator_email(): void
    {
        $result = $this->resolve('admin@salon.com', 'Admin User');
        $this->assertSame('admin@salon.com', $result['replyToEmail']);
    }

    public function test_reply_to_email_null_when_no_operator(): void
    {
        $result = $this->resolve(null, null);
        $this->assertNull($result['replyToEmail']);
    }

    public function test_reply_to_email_empty_string_passed_through(): void
    {
        $result = $this->resolve('', null);
        $this->assertSame('', $result['replyToEmail']);
    }

    // ═══════════════════════════════════════════════════
    // replyToName — cascade: name → email local part → app name
    // ═══════════════════════════════════════════════════

    public function test_reply_to_name_uses_operator_name_when_provided(): void
    {
        $result = $this->resolve('admin@salon.com', 'Sarah Johnson');
        $this->assertSame('Sarah Johnson', $result['replyToName']);
    }

    public function test_reply_to_name_falls_back_to_email_local_part(): void
    {
        $result = $this->resolve('admin@salon.com', null);
        $this->assertSame('admin', $result['replyToName']);
    }

    public function test_reply_to_name_email_local_part_when_name_empty(): void
    {
        $result = $this->resolve('boss@example.com', '');
        $this->assertSame('boss', $result['replyToName']);
    }

    public function test_reply_to_name_falls_back_to_app_name(): void
    {
        $result = $this->resolve(null, null, 'MyBooking');
        $this->assertSame('MyBooking', $result['replyToName']);
    }

    public function test_reply_to_name_app_name_when_email_empty(): void
    {
        $result = $this->resolve('', null, 'TestApp');
        $this->assertSame('TestApp', $result['replyToName']);
    }

    // ═══════════════════════════════════════════════════
    // Edge cases — malformed/unusual inputs
    // ═══════════════════════════════════════════════════

    public function test_reply_to_name_app_name_when_email_has_no_at_sign(): void
    {
        // strstr('@', true) would return false for "noatsign"
        $result = $this->resolve('noatsign', null, 'FallbackApp');
        $this->assertSame('FallbackApp', $result['replyToName'], 'Must not return bool false for email without @');
    }

    public function test_reply_to_name_prefers_name_over_email(): void
    {
        // Even when email is valid, explicit name takes priority
        $result = $this->resolve('admin@salon.com', 'Explicit Name');
        $this->assertSame('Explicit Name', $result['replyToName']);
    }

    public function test_reply_to_name_email_local_part_complex(): void
    {
        // Local parts can have dots, plus signs
        $result = $this->resolve('sarah.johnson+booking@salon.com', null);
        $this->assertSame('sarah.johnson+booking', $result['replyToName']);
    }

    public function test_full_sender_metadata_structure(): void
    {
        $result = $this->resolve('operator@platform.com', 'Platform Admin', 'VoxelBooking');

        $this->assertArrayHasKey('fromName', $result);
        $this->assertArrayHasKey('replyToEmail', $result);
        $this->assertArrayHasKey('replyToName', $result);
        $this->assertCount(3, $result, 'Should contain exactly 3 keys');
    }
}
