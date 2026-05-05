<?php

declare(strict_types=1);

namespace Tests\Unit\Engine;

use App\Engine\Mailer;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the Mailer transport modes.
 *
 * Verifies:
 * - mail_transport=log records to email_log without outbound connection
 * - mail_transport=mailpit resolves to localhost:1025 with no auth
 * - mail_transport=smtp (default) uses operator-configured SMTP settings
 * - Unknown transport value falls through to SMTP behavior
 */
final class MailerTransportTest extends TestCase
{
    protected function setUp(): void
    {
        Mailer::clearConfigCache();
    }

    /**
     * Log transport: send returns success without SMTP connection.
     *
     * The log transport must:
     * - Return sent=true
     * - Return error=null
     * - Return a valid log_id
     * - NOT make any outbound network connection
     */
    public function testLogTransportReturnsSentWithoutSmtp(): void
    {
        // Inject log transport via reflection to bypass DB dependency
        $configProp = new \ReflectionProperty(Mailer::class, 'configCache');
        $configProp->setValue(null, [
            'mail_transport'   => 'log',
            'smtp_host'        => '',
            'smtp_port'        => '',
            'smtp_username'    => '',
            'smtp_password'    => '',
            'smtp_encryption'  => '',
            'mail_from_address' => '',
            'mail_from_name'   => '',
        ]);

        $result = Mailer::send('test@example.com', 'Test', '<p>Body</p>', 'test');

        $this->assertTrue($result['sent'], 'Log transport must return sent=true');
        $this->assertNull($result['error'], 'Log transport must return no error');
        $this->assertNotEmpty($result['log_id'], 'Log transport must return a log_id');
    }

    /**
     * Mailpit transport: resolveEffectiveConfig overrides production values.
     *
     * Verifies the 5 overrides applied by the mailpit branch:
     * host → 127.0.0.1, port → 1025, username → '', password → '', encryption → 'none'.
     * No network connection is made.
     */
    public function testMailpitTransportOverridesConfig(): void
    {
        $method = new \ReflectionMethod(Mailer::class, 'resolveEffectiveConfig');

        $input = [
            'mail_transport'    => 'mailpit',
            'smtp_host'         => 'smtp.production.com',
            'smtp_port'         => '587',
            'smtp_username'     => 'prod@example.com',
            'smtp_password'     => 'prodpassword',
            'smtp_encryption'   => 'tls',
            'mail_from_address' => 'from@example.com',
            'mail_from_name'    => 'Prod Sender',
        ];

        $effective = $method->invoke(null, $input);

        // Mailpit overrides
        $this->assertSame('127.0.0.1', $effective['smtp_host'], 'Mailpit must override host to localhost');
        $this->assertSame('1025', $effective['smtp_port'], 'Mailpit must override port to 1025');
        $this->assertSame('', $effective['smtp_username'], 'Mailpit must clear username');
        $this->assertSame('', $effective['smtp_password'], 'Mailpit must clear password');
        $this->assertSame('none', $effective['smtp_encryption'], 'Mailpit must set encryption to none');

        // Non-overridden values preserved
        $this->assertSame('from@example.com', $effective['mail_from_address'], 'From address must be preserved');
        $this->assertSame('Prod Sender', $effective['mail_from_name'], 'From name must be preserved');
    }

    /**
     * Non-mailpit transports: resolveEffectiveConfig returns config unchanged.
     */
    public function testNonMailpitTransportPreservesConfig(): void
    {
        $method = new \ReflectionMethod(Mailer::class, 'resolveEffectiveConfig');

        $input = [
            'mail_transport'    => 'smtp',
            'smtp_host'         => 'smtp.production.com',
            'smtp_port'         => '587',
            'smtp_username'     => 'prod@example.com',
            'smtp_password'     => 'prodpassword',
            'smtp_encryption'   => 'tls',
            'mail_from_address' => 'from@example.com',
            'mail_from_name'    => 'Prod Sender',
        ];

        $effective = $method->invoke(null, $input);
        $this->assertSame($input, $effective, 'SMTP transport must not modify config');

        // Also verify log transport
        $input['mail_transport'] = 'log';
        $effective = $method->invoke(null, $input);
        $this->assertSame($input, $effective, 'Log transport must not modify config');
    }

    /**
     * Default transport (smtp) with no SMTP configured returns failure.
     */
    public function testSmtpTransportWithNoHostReturnsFailed(): void
    {
        $configProp = new \ReflectionProperty(Mailer::class, 'configCache');
        $configProp->setValue(null, [
            'mail_transport'   => 'smtp',
            'smtp_host'        => '',
            'smtp_port'        => '',
            'smtp_username'    => '',
            'smtp_password'    => '',
            'smtp_encryption'  => '',
            'mail_from_address' => '',
            'mail_from_name'   => '',
        ]);

        $result = Mailer::send('test@example.com', 'Test', '<p>Body</p>', 'test');

        $this->assertFalse($result['sent']);
        $this->assertSame('SMTP not configured', $result['error']);
    }

    /**
     * Unknown transport value falls through to SMTP behavior.
     */
    public function testUnknownTransportFallsToSmtp(): void
    {
        $configProp = new \ReflectionProperty(Mailer::class, 'configCache');
        $configProp->setValue(null, [
            'mail_transport'   => 'carrier_pigeon',
            'smtp_host'        => '',
            'smtp_port'        => '',
            'smtp_username'    => '',
            'smtp_password'    => '',
            'smtp_encryption'  => '',
            'mail_from_address' => '',
            'mail_from_name'   => '',
        ]);

        $result = Mailer::send('test@example.com', 'Test', '<p>Body</p>', 'test');

        // Unknown transport with no SMTP host → same as SMTP not configured
        $this->assertFalse($result['sent']);
        $this->assertSame('SMTP not configured', $result['error']);
    }

    /**
     * Log transport returns sent=true even when SMTP host is empty.
     * This confirms log transport bypasses the SMTP-unconfigured check.
     */
    public function testLogTransportBypassesSmtpCheck(): void
    {
        $configProp = new \ReflectionProperty(Mailer::class, 'configCache');
        $configProp->setValue(null, [
            'mail_transport'   => 'log',
            'smtp_host'        => '',  // Intentionally empty
            'smtp_port'        => '',
            'smtp_username'    => '',
            'smtp_password'    => '',
            'smtp_encryption'  => '',
            'mail_from_address' => '',
            'mail_from_name'   => '',
        ]);

        $result = Mailer::send('any@email.com', 'Subject', '<p>Body</p>', 'test');

        $this->assertTrue($result['sent'], 'Log transport must succeed regardless of SMTP config');
    }

    // ── isConfigured() transport-awareness ──

    /**
     * isConfigured returns true for log transport even with no SMTP host.
     */
    public function testIsConfiguredTrueForLogTransport(): void
    {
        $configProp = new \ReflectionProperty(Mailer::class, 'configCache');
        $configProp->setValue(null, [
            'mail_transport'   => 'log',
            'smtp_host'        => '',
            'smtp_port'        => '',
            'smtp_username'    => '',
            'smtp_password'    => '',
            'smtp_encryption'  => '',
            'mail_from_address' => '',
            'mail_from_name'   => '',
        ]);

        $this->assertTrue(Mailer::isConfigured(), 'Log transport is always configured');
    }

    /**
     * isConfigured returns true for mailpit transport even with no SMTP host.
     */
    public function testIsConfiguredTrueForMailpitTransport(): void
    {
        $configProp = new \ReflectionProperty(Mailer::class, 'configCache');
        $configProp->setValue(null, [
            'mail_transport'   => 'mailpit',
            'smtp_host'        => '',
            'smtp_port'        => '',
            'smtp_username'    => '',
            'smtp_password'    => '',
            'smtp_encryption'  => '',
            'mail_from_address' => '',
            'mail_from_name'   => '',
        ]);

        $this->assertTrue(Mailer::isConfigured(), 'Mailpit transport is always configured');
    }

    /**
     * isConfigured returns false for smtp transport with no host.
     */
    public function testIsConfiguredFalseForSmtpWithNoHost(): void
    {
        $configProp = new \ReflectionProperty(Mailer::class, 'configCache');
        $configProp->setValue(null, [
            'mail_transport'   => 'smtp',
            'smtp_host'        => '',
            'smtp_port'        => '',
            'smtp_username'    => '',
            'smtp_password'    => '',
            'smtp_encryption'  => '',
            'mail_from_address' => '',
            'mail_from_name'   => '',
        ]);

        $this->assertFalse(Mailer::isConfigured(), 'SMTP transport without host is not configured');
    }

    /**
     * isConfigured returns true for smtp transport with a host set.
     */
    public function testIsConfiguredTrueForSmtpWithHost(): void
    {
        $configProp = new \ReflectionProperty(Mailer::class, 'configCache');
        $configProp->setValue(null, [
            'mail_transport'   => 'smtp',
            'smtp_host'        => 'smtp.example.com',
            'smtp_port'        => '587',
            'smtp_username'    => '',
            'smtp_password'    => '',
            'smtp_encryption'  => 'tls',
            'mail_from_address' => '',
            'mail_from_name'   => '',
        ]);

        $this->assertTrue(Mailer::isConfigured(), 'SMTP transport with host is configured');
    }

    // ════════════════════════════════════════════════════════════════
    // isProductionSmtp — customer-facing delivery truthfulness
    // ════════════════════════════════════════════════════════════════

    /**
     * isProductionSmtp returns true for smtp transport with a configured host.
     */
    public function testIsProductionSmtpTrueForConfiguredSmtp(): void
    {
        $configProp = new \ReflectionProperty(Mailer::class, 'configCache');
        $configProp->setValue(null, [
            'mail_transport'   => 'smtp',
            'smtp_host'        => 'smtp.example.com',
            'smtp_port'        => '587',
            'smtp_username'    => '',
            'smtp_password'    => '',
            'smtp_encryption'  => 'tls',
            'mail_from_address' => '',
            'mail_from_name'   => '',
        ]);

        $this->assertTrue(Mailer::isProductionSmtp(),
            'SMTP transport with configured host is production SMTP');
    }

    /**
     * isProductionSmtp returns false for mailpit — dev capture, not customer delivery.
     */
    public function testIsProductionSmtpFalseForMailpit(): void
    {
        $configProp = new \ReflectionProperty(Mailer::class, 'configCache');
        $configProp->setValue(null, [
            'mail_transport'   => 'mailpit',
            'smtp_host'        => 'smtp.production.com',
            'smtp_port'        => '587',
            'smtp_username'    => '',
            'smtp_password'    => '',
            'smtp_encryption'  => '',
            'mail_from_address' => '',
            'mail_from_name'   => '',
        ]);

        $this->assertFalse(Mailer::isProductionSmtp(),
            'Mailpit is dev capture, not production SMTP');
    }

    /**
     * isProductionSmtp returns false for log transport.
     */
    public function testIsProductionSmtpFalseForLog(): void
    {
        $configProp = new \ReflectionProperty(Mailer::class, 'configCache');
        $configProp->setValue(null, [
            'mail_transport'   => 'log',
            'smtp_host'        => '',
            'smtp_port'        => '',
            'smtp_username'    => '',
            'smtp_password'    => '',
            'smtp_encryption'  => '',
            'mail_from_address' => '',
            'mail_from_name'   => '',
        ]);

        $this->assertFalse(Mailer::isProductionSmtp(),
            'Log transport is not production SMTP');
    }

    /**
     * isProductionSmtp returns false for smtp transport with no host.
     */
    public function testIsProductionSmtpFalseForSmtpWithNoHost(): void
    {
        $configProp = new \ReflectionProperty(Mailer::class, 'configCache');
        $configProp->setValue(null, [
            'mail_transport'   => 'smtp',
            'smtp_host'        => '',
            'smtp_port'        => '',
            'smtp_username'    => '',
            'smtp_password'    => '',
            'smtp_encryption'  => '',
            'mail_from_address' => '',
            'mail_from_name'   => '',
        ]);

        $this->assertFalse(Mailer::isProductionSmtp(),
            'SMTP with no host is not production SMTP');
    }

    // ════════════════════════════════════════════════════════════════
    // Resend transport — isConfigured / isProductionSmtp
    // ════════════════════════════════════════════════════════════════

    /**
     * isConfigured returns true for resend transport with API key set.
     */
    public function testIsConfiguredTrueForResendWithApiKey(): void
    {
        $configProp = new \ReflectionProperty(Mailer::class, 'configCache');
        $configProp->setValue(null, [
            'mail_transport'   => 'resend',
            'smtp_host'        => '',
            'smtp_port'        => '',
            'smtp_username'    => '',
            'smtp_password'    => 're_test_api_key_123',
            'smtp_encryption'  => '',
            'mail_from_address' => 'book@example.com',
            'mail_from_name'   => 'Test',
        ]);

        $this->assertTrue(Mailer::isConfigured(),
            'Resend transport with API key must be configured');
    }

    /**
     * isConfigured returns false for resend transport with no API key.
     */
    public function testIsConfiguredFalseForResendWithNoApiKey(): void
    {
        $configProp = new \ReflectionProperty(Mailer::class, 'configCache');
        $configProp->setValue(null, [
            'mail_transport'   => 'resend',
            'smtp_host'        => '',
            'smtp_port'        => '',
            'smtp_username'    => '',
            'smtp_password'    => '',
            'smtp_encryption'  => '',
            'mail_from_address' => 'book@example.com',
            'mail_from_name'   => 'Test',
        ]);

        $this->assertFalse(Mailer::isConfigured(),
            'Resend transport without API key must not be configured');
    }

    /**
     * isProductionSmtp returns true for resend transport with API key.
     * Resend delivers to real customer inboxes, same as SMTP.
     */
    public function testIsProductionSmtpTrueForResendWithApiKey(): void
    {
        $configProp = new \ReflectionProperty(Mailer::class, 'configCache');
        $configProp->setValue(null, [
            'mail_transport'   => 'resend',
            'smtp_host'        => '',
            'smtp_port'        => '',
            'smtp_username'    => '',
            'smtp_password'    => 're_test_api_key_123',
            'smtp_encryption'  => '',
            'mail_from_address' => 'book@example.com',
            'mail_from_name'   => 'Test',
        ]);

        $this->assertTrue(Mailer::isProductionSmtp(),
            'Resend with API key delivers to real inboxes');
    }

    /**
     * isProductionSmtp returns false for resend transport with no API key.
     */
    public function testIsProductionSmtpFalseForResendWithNoApiKey(): void
    {
        $configProp = new \ReflectionProperty(Mailer::class, 'configCache');
        $configProp->setValue(null, [
            'mail_transport'   => 'resend',
            'smtp_host'        => '',
            'smtp_port'        => '',
            'smtp_username'    => '',
            'smtp_password'    => '',
            'smtp_encryption'  => '',
            'mail_from_address' => 'book@example.com',
            'mail_from_name'   => 'Test',
        ]);

        $this->assertFalse(Mailer::isProductionSmtp(),
            'Resend without API key cannot deliver to inboxes');
    }

    /**
     * resolveEffectiveConfig does NOT modify config for resend transport.
     * Resend early-returns via sendViaResendApi before config overrides apply.
     */
    public function testResolveEffectiveConfigPassthroughForResend(): void
    {
        $method = new \ReflectionMethod(Mailer::class, 'resolveEffectiveConfig');

        $input = [
            'mail_transport'    => 'resend',
            'smtp_host'         => '',
            'smtp_port'         => '',
            'smtp_username'     => '',
            'smtp_password'     => 're_test_key',
            'smtp_encryption'   => '',
            'mail_from_address' => 'from@example.com',
            'mail_from_name'    => 'Sender',
        ];

        $effective = $method->invoke(null, $input);
        $this->assertSame($input, $effective,
            'Resend transport must not modify config in resolveEffectiveConfig');
    }

    /**
     * Resend transport with no API key returns failure (does not reach SMTP path).
     */
    public function testResendTransportWithNoApiKeyReturnsFailed(): void
    {
        $configProp = new \ReflectionProperty(Mailer::class, 'configCache');
        $configProp->setValue(null, [
            'mail_transport'   => 'resend',
            'smtp_host'        => '',
            'smtp_port'        => '',
            'smtp_username'    => '',
            'smtp_password'    => '',
            'smtp_encryption'  => '',
            'mail_from_address' => 'book@example.com',
            'mail_from_name'   => 'Test',
        ]);

        $result = Mailer::send('test@example.com', 'Test', '<p>Body</p>', 'test');

        $this->assertFalse($result['sent'], 'Resend with no API key must fail');
        $this->assertSame('Resend API key not configured', $result['error']);
        $this->assertNotEmpty($result['log_id']);
    }
}
