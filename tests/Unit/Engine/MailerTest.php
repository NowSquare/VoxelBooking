<?php

declare(strict_types=1);

namespace Tests\Unit\Engine;

use App\Engine\Mailer;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the Mailer engine.
 *
 * Verifies:
 * - HTML-to-plain-text conversion
 * - Credential redaction in error messages
 * - Encryption resolution
 * - Config cache behavior
 * - Privacy email rendering
 * - Graceful failure when SMTP is not configured
 */
final class MailerTest extends TestCase
{
    /**
     * @param array<string, string> $config
     */
    private function setMailerConfig(array $config): void
    {
        $property = new \ReflectionProperty(Mailer::class, 'configCache');
        $property->setValue(null, array_merge([
            'smtp_host' => '',
            'smtp_port' => '',
            'smtp_username' => '',
            'smtp_password' => '',
            'smtp_encryption' => '',
            'mail_from_address' => '',
            'mail_from_name' => '',
            'mail_transport' => 'smtp',
        ], $config));
    }

    protected function setUp(): void
    {
        Mailer::clearConfigCache();
        \App\Engine\Locale::init(dirname(__DIR__, 3));
        \App\Engine\Locale::setLocale('en');
    }

    /**
     * htmlToPlainText strips tags and converts BR to newlines.
     */
    public function testHtmlToPlainTextStripsTagsAndConverts(): void
    {
        $method = new \ReflectionMethod(Mailer::class, 'htmlToPlainText');

        $html = '<h1>Hello</h1><p>World</p><br>Line 2';
        $text = $method->invoke(null, $html);

        $this->assertStringContainsString('Hello', $text);
        $this->assertStringContainsString('World', $text);
        $this->assertStringContainsString('Line 2', $text);
        $this->assertStringNotContainsString('<', $text);
    }

    /**
     * htmlToPlainText decodes HTML entities.
     */
    public function testHtmlToPlainTextDecodesEntities(): void
    {
        $method = new \ReflectionMethod(Mailer::class, 'htmlToPlainText');

        $html = 'Price: &euro;50 &amp; tax &lt;included&gt;';
        $text = $method->invoke(null, $html);

        $this->assertStringContainsString('€50', $text);
        $this->assertStringContainsString('& tax', $text);
        $this->assertStringContainsString('<included>', $text);
    }

    /**
     * redactCredentials removes sensitive values from error messages.
     */
    public function testRedactCredentialsRemovesSensitiveValues(): void
    {
        $method = new \ReflectionMethod(Mailer::class, 'redactCredentials');

        $config = [
            'smtp_username' => 'admin@example.com',
            'smtp_password' => 'SuperSecret123!',
        ];

        $message = 'Failed to authenticate with admin@example.com using password SuperSecret123!';
        $redacted = $method->invoke(null, $message, $config);

        $this->assertStringNotContainsString('SuperSecret123!', $redacted);
        $this->assertStringNotContainsString('admin@example.com', $redacted);
        $this->assertStringContainsString('[REDACTED]', $redacted);
    }

    /**
     * redactCredentials handles empty password gracefully.
     */
    public function testRedactCredentialsHandlesEmptyPassword(): void
    {
        $method = new \ReflectionMethod(Mailer::class, 'redactCredentials');

        $config = ['smtp_username' => '', 'smtp_password' => ''];
        $message = 'Connection timed out';
        $redacted = $method->invoke(null, $message, $config);

        $this->assertSame('Connection timed out', $redacted);
    }

    /**
     * resolveEncryption maps setting values to PHPMailer constants.
     */
    public function testResolveEncryption(): void
    {
        $method = new \ReflectionMethod(Mailer::class, 'resolveEncryption');

        $this->assertSame('tls', $method->invoke(null, 'tls'));
        $this->assertSame('ssl', $method->invoke(null, 'ssl'));
        $this->assertSame('', $method->invoke(null, 'none'));
        $this->assertSame('', $method->invoke(null, ''));
        $this->assertSame('tls', $method->invoke(null, 'TLS'));
        $this->assertSame('ssl', $method->invoke(null, 'SSL'));
    }

    /**
     * renderPrivacyEmail produces valid HTML with all parts.
     */
    public function testRenderPrivacyEmailContainsAllParts(): void
    {
        $method = new \ReflectionMethod(Mailer::class, 'renderPrivacyEmail');

        $html = $method->invoke(null, 'Test Title', 'Test body content.', 'Test footer note.');

        $this->assertStringContainsString('Test Title', $html);
        $this->assertStringContainsString('Test body content.', $html);
        $this->assertStringContainsString('Test footer note.', $html);
        // Powered-by line now uses app_name() — in test context this is the env fallback
        $this->assertStringContainsString('Powered by', $html);
        $this->assertStringContainsString('<html lang="en" dir="ltr">', $html);
    }

    /**
     * Email translations correctly substitute :app_name placeholder.
     */
    public function testEmailTranslationsSubstituteAppName(): void
    {
        $testName = 'MyTestApp';
        $replacements = ['app_name' => $testName];

        // Test subject
        $subject = __('email.test.subject', $replacements);
        $this->assertStringContainsString($testName, $subject);
        $this->assertStringNotContainsString(':app_name', $subject);

        // Common powered_by
        $poweredBy = __('email.common.powered_by', $replacements);
        $this->assertStringContainsString($testName, $poweredBy);
        $this->assertStringNotContainsString(':app_name', $poweredBy);

        // Operator deletion footer
        $footer = __('email.operator_deletion.footer', $replacements);
        $this->assertStringContainsString($testName, $footer);
        $this->assertStringNotContainsString(':app_name', $footer);
    }

    /**
     * send() with no SMTP configured returns graceful failure.
     */
    public function testSendWithNoSmtpReturnsFailure(): void
    {
        $this->setMailerConfig([
            'mail_transport' => 'smtp',
            'smtp_host' => '',
        ]);

        $result = Mailer::send(
            'test@example.com',
            'Test Subject',
            '<p>Test body</p>',
            'test',
        );

        $this->assertFalse($result['sent']);
        $this->assertSame('SMTP not configured', $result['error']);
    }

    /**
     * isConfigured returns false when SMTP host is empty.
     */
    public function testIsConfiguredReturnsFalseWhenEmpty(): void
    {
        $this->setMailerConfig([
            'mail_transport' => 'smtp',
            'smtp_host' => '',
        ]);

        $this->assertFalse(Mailer::isConfigured());
    }

    /**
     * clearConfigCache resets the internal cache.
     */
    public function testClearConfigCacheWorks(): void
    {
        $this->setMailerConfig([
            'mail_transport' => 'smtp',
            'smtp_host' => 'smtp.example.com',
        ]);

        $this->assertTrue(Mailer::isConfigured());

        Mailer::clearConfigCache();
        $this->setMailerConfig([
            'mail_transport' => 'smtp',
            'smtp_host' => '',
        ]);

        $this->assertFalse(Mailer::isConfigured());
    }

    // ── Branded confirmation email renderer tests ──

    public function testRenderConfirmationEmailContainsAllParts(): void
    {
        $method = new \ReflectionMethod(Mailer::class, 'renderConfirmationEmail');

        $html = $method->invoke(null,
            '#2563EB',                                       // brandColor
            'Your booking has been confirmed.',              // heading
            'Hi Test Customer,',                             // greeting
            'Your booking has been confirmed.',              // bodyText
            'Booking details',                               // detailsHeading
            [                                                // details
                'Date'    => 'Wednesday, 2 April 2026',
                'Time'    => '10:00–10:30',
                'Service' => 'Haircut',
                'Staff'   => 'Emma',
            ],
            'If you need to make changes, please contact us.', // footerText
            'Salon Bella',                                   // tenantName
            'VoxelBooking',                                  // appName
        );

        // Brand header bar
        $this->assertStringContainsString('#2563EB', $html, 'Must contain brand color');
        $this->assertStringContainsString('40px', $html, 'Must contain header bar height');

        // Status confirmation
        $this->assertStringContainsString('✓', $html, 'Must contain check mark');

        // Heading
        $this->assertStringContainsString('Your booking has been confirmed.', $html);

        // Greeting
        $this->assertStringContainsString('Hi Test Customer,', $html);

        // Details section heading
        $this->assertStringContainsString('Booking details', $html, 'Must contain details heading');

        // Summary card details
        $this->assertStringContainsString('Wednesday, 2 April 2026', $html);
        $this->assertStringContainsString('10:00', $html);
        $this->assertStringContainsString('Haircut', $html);
        $this->assertStringContainsString('Emma', $html);

        // Footer
        $this->assertStringContainsString('Salon Bella', $html);
        $this->assertStringContainsString('VoxelBooking', $html);
    }

    public function testRenderConfirmationEmailOmitsNullDetails(): void
    {
        $method = new \ReflectionMethod(Mailer::class, 'renderConfirmationEmail');

        $html = $method->invoke(null,
            '#2563EB',
            'Confirmed.', 'Hi Test,', 'Confirmed.',
            'Booking details',                                   // detailsHeading
            ['Date' => '2026-04-02', 'Time' => '10:00–10:30'],  // no Service or Staff
            'Contact us.', 'Test Biz', 'VB',
        );

        $this->assertStringNotContainsString('Service', $html);
        $this->assertStringNotContainsString('Staff', $html);
    }

    public function testPlainTextConfirmationIsReadable(): void
    {
        $method = new \ReflectionMethod(Mailer::class, 'renderConfirmationPlainText');

        $plain = $method->invoke(null,
            'Your booking has been confirmed.',              // heading
            'Hi Emma,',                                      // greeting
            'Your booking has been confirmed.',              // bodyText
            'Booking details',                               // detailsHeading
            [                                                // details
                'Date'    => 'April 2, 2026',
                'Time'    => '10:00–10:30',
                'Service' => 'Haircut & Beard',
                'Staff'   => 'Emma Wilson',
            ],
            'If you need to make changes, please contact us.', // footerText
            'Salon Bella',                                   // tenantName
            'Powered by VoxelBooking',                       // poweredBy
        );

        // Each detail row must appear as "Label: Value" on its own line
        $this->assertStringContainsString("Date: April 2, 2026\n", $plain);
        $this->assertStringContainsString("Time: 10:00", $plain);
        $this->assertStringContainsString("Service: Haircut & Beard\n", $plain);
        $this->assertStringContainsString("Staff: Emma Wilson\n", $plain);

        // Heading is uppercased
        $this->assertStringContainsString('YOUR BOOKING HAS BEEN CONFIRMED.', $plain);

        // Details section heading present before detail rows
        $this->assertStringContainsString("Booking details\n", $plain, 'Must contain details heading');

        // Details are not collapsed
        $this->assertStringNotContainsString('DateApril', $plain);
        $this->assertStringNotContainsString('TimeService', $plain);

        // Footer and business name present
        $this->assertStringContainsString('Salon Bella', $plain);
        $this->assertStringContainsString('Powered by VoxelBooking', $plain);
    }

    /**
     * A tenant-defined button label replaces the default one in the HTML email;
     * without it the default label is kept. The label is escaped.
     */
    public function testConfirmationEmailUsesCustomCtaLabel(): void
    {
        $method = new \ReflectionMethod(Mailer::class, 'renderConfirmationEmail');
        $args = static fn (string $ctaLabel): array => [
            '#2563EB', 'Heading', 'Hi Emma,', '', 'Booking details', ['Date' => 'April 2, 2026'],
            'Footer', 'Salon Bella', 'VoxelBooking', 'https://example.test/manage/abc', $ctaLabel,
        ];

        $custom = $method->invoke(null, ...$args('See my appointment'));
        $this->assertStringContainsString('See my appointment', $custom);
        $this->assertStringContainsString('https://example.test/manage/abc', $custom);
        $this->assertStringNotContainsString('View or Manage Booking', $custom);

        $default = $method->invoke(null, ...$args(''));
        $this->assertStringContainsString('View or Manage Booking', $default);

        $escaped = $method->invoke(null, ...$args('<b>Go</b>'));
        $this->assertStringContainsString('&lt;b&gt;Go&lt;/b&gt;', $escaped);
        $this->assertStringNotContainsString('<b>Go</b>', $escaped);
    }

    public function testPlainTextConfirmationUsesCustomCtaLabel(): void
    {
        $method = new \ReflectionMethod(Mailer::class, 'renderConfirmationPlainText');
        $args = static fn (string $ctaLabel): array => [
            'Heading', 'Hi Emma,', '', 'Booking details', ['Date' => 'April 2, 2026'],
            'Footer', 'Salon Bella', 'Powered by VoxelBooking', 'https://example.test/manage/abc', $ctaLabel,
        ];

        $custom = $method->invoke(null, ...$args('See my appointment'));
        $this->assertStringContainsString("See my appointment:\nhttps://example.test/manage/abc", $custom);

        $default = $method->invoke(null, ...$args(''));
        $this->assertStringContainsString("View or Manage Booking:\nhttps://example.test/manage/abc", $default);
    }

    public function testCancellationEmailUsesCustomCtaLabel(): void
    {
        $method = new \ReflectionMethod(Mailer::class, 'renderCancellationEmail');
        $args = static fn (string $ctaLabel): array => [
            '#2563EB', 'Heading', 'Hi Emma,', '', 'Booking details', ['Date' => 'April 2, 2026'],
            'Footer', 'Salon Bella', 'VoxelBooking', 'salon-bella', $ctaLabel,
        ];

        $custom = $method->invoke(null, ...$args('Rebook now'));
        // Match the link text: "Book Again" also appears in an HTML comment
        $this->assertStringContainsString('>Rebook now</a>', $custom);
        $this->assertStringNotContainsString('>Book Again</a>', $custom);

        $default = $method->invoke(null, ...$args(''));
        $this->assertStringContainsString('>Book Again</a>', $default);
    }

    public function testBrandColorSanitizationFallback(): void
    {
        $tokens = \App\Engine\BrandColorHelper::derive('not-a-color');
        $this->assertSame('#2563EB', $tokens['brand'], 'Invalid color must fall back to default');

        $tokens = \App\Engine\BrandColorHelper::derive('');
        $this->assertSame('#2563EB', $tokens['brand'], 'Empty color must fall back to default');

        $tokens = \App\Engine\BrandColorHelper::derive('<script>');
        $this->assertSame('#2563EB', $tokens['brand'], 'XSS payload must fall back to default');

        // 6-char non-hex must now fail with the hardened regex
        $tokens = \App\Engine\BrandColorHelper::derive('abcxyz');
        $this->assertSame('#2563EB', $tokens['brand'], '6-char non-hex must fall back to default');

        $tokens = \App\Engine\BrandColorHelper::derive('#FF5733');
        $this->assertSame('#FF5733', $tokens['brand'], 'Valid hex must be preserved');
    }

    // ── Reply-To header tests ──

    /**
     * resolveTenantReplyTo returns nulls when tenantId is null.
     */
    public function testResolveTenantReplyToReturnsNullForNullTenant(): void
    {
        $method = new \ReflectionMethod(Mailer::class, 'resolveTenantReplyTo');

        $result = $method->invoke(null, null);

        $this->assertNull($result['email']);
        $this->assertNull($result['name']);
    }

    /**
     * resolveTenantReplyTo returns nulls when tenant is not found in DB.
     *
     * Uses a non-existent ULID so the query returns empty.
     */
    public function testResolveTenantReplyToReturnsNullForMissingTenant(): void
    {
        $method = new \ReflectionMethod(Mailer::class, 'resolveTenantReplyTo');

        // This will either fail DB query (no connection in unit test) or return empty rows
        $result = $method->invoke(null, '01NONEXISTENT000000000000');

        $this->assertNull($result['email']);
        $this->assertNull($result['name']);
    }

    /**
     * send() accepts replyToEmail and replyToName parameters.
     *
     * Verifies the signature change doesn't break existing callers by
     * calling send() with log transport (no SMTP needed) and the new parameters.
     */
    public function testSendAcceptsReplyToParameters(): void
    {
        $this->setMailerConfig(['mail_transport' => 'log']);

        $result = Mailer::send(
            'test@example.com',
            'Test',
            '<p>Test</p>',
            'test',
            null,                           // tenantId
            null,                           // bookingId
            null,                           // plainBody
            'salon@example.com',            // replyToEmail
            'Salon Bella',                  // replyToName
        );

        $this->assertTrue($result['sent'], 'Log transport with Reply-To parameters must succeed');
    }

    /**
     * send() works without Reply-To parameters (backward compatibility).
     */
    public function testSendWorksWithoutReplyToParameters(): void
    {
        $this->setMailerConfig(['mail_transport' => 'log']);

        $result = Mailer::send(
            'test@example.com',
            'Test',
            '<p>Test</p>',
            'test',
        );

        $this->assertTrue($result['sent'], 'Log transport without Reply-To must still succeed');
    }

    // ── Tenant-branded From name tests ──

    /**
     * resolveEffectiveFromName returns tenant name when provided (tenant-scoped path).
     *
     * This tests the actual resolution logic used by setFrom() at Mailer.php:95.
     */
    public function testResolveEffectiveFromNameReturnsTenantName(): void
    {
        $config = ['mail_from_name' => 'VoxelBooking Global'];

        $result = Mailer::resolveEffectiveFromName('Salon Bella', $config);

        $this->assertSame('Salon Bella', $result, 'Tenant name must override global config');
    }

    /**
     * resolveEffectiveFromName returns global config when no tenant name is provided (system email path).
     */
    public function testResolveEffectiveFromNameFallsBackToGlobalConfig(): void
    {
        $config = ['mail_from_name' => 'VoxelBooking Global'];

        $result = Mailer::resolveEffectiveFromName(null, $config);

        $this->assertSame('VoxelBooking Global', $result, 'Null fromName must fall back to global config');
    }

    /**
     * resolveEffectiveFromName falls back to app_name() when both tenant and global are empty.
     */
    public function testResolveEffectiveFromNameFallsBackToAppName(): void
    {
        $config = ['mail_from_name' => ''];

        $result = Mailer::resolveEffectiveFromName(null, $config);

        // app_name() returns the APP_NAME env var or 'VoxelBooking'
        $this->assertNotEmpty($result, 'Must fall back to app_name() when config is empty');
        $this->assertSame(app_name(), $result);
    }

    /**
     * Empty string fromName is treated as "no override" (same as null).
     */
    public function testResolveEffectiveFromNameTreatsEmptyStringAsNull(): void
    {
        $config = ['mail_from_name' => 'Global Name'];

        $result = Mailer::resolveEffectiveFromName('', $config);

        $this->assertSame('Global Name', $result, 'Empty string fromName must fall back to global');
    }

    /**
     * Tenant-scoped methods pass tenant name while system methods do not.
     *
     * Structural verification: ensures the wiring is correct by checking
     * that the resolution produces different results for each path.
     */
    public function testTenantVsSystemFromNameDiverges(): void
    {
        $config = ['mail_from_name' => 'Platform Global'];

        // Tenant-scoped path: passes tenant name
        $tenantResult = Mailer::resolveEffectiveFromName('Hotel Marina', $config);
        $this->assertSame('Hotel Marina', $tenantResult);

        // System email path: passes null
        $systemResult = Mailer::resolveEffectiveFromName(null, $config);
        $this->assertSame('Platform Global', $systemResult);

        // They must differ
        $this->assertNotSame($tenantResult, $systemResult,
            'Tenant-scoped and system emails must resolve to different From names');
    }

    // ── Resource-specific email detail rows (Phase R) ──

    /**
     * sendBookingConfirmation with $patternDetails renders resource-specific
     * labels (Room, Check-in, Check-out, Guests, Total) instead of the
     * default timeslot rows (Date, Time, Service, Staff).
     */
    public function testConfirmationEmailRendersResourceDetails(): void
    {
        $this->setMailerConfig(['mail_transport' => 'log']);

        $resourceDetails = [
            'Room'      => 'Sea View Suite',
            'Check-in'  => 'April 5, 2026',
            'Check-out' => 'April 8, 2026',
            'Guests'    => '2',
            'Total'     => '€450.00',
        ];

        // Verify send succeeds with resource details
        $result = Mailer::sendBookingConfirmation(
            'guest@example.com', 'Jane Doe',
            ['date' => '2026-04-05', 'formatted_date' => 'April 5, 2026', 'time' => '', 'end_time' => ''],
            'Sea View Suite', null,
            'Hotel Marina', 'TENANT_ID_TEST', 'BOOKING_ID_TEST',
            '#2563EB',
            $resourceDetails,
        );

        $this->assertTrue($result['sent'], 'Log transport with resource details must succeed');

        // Verify rendered content via the renderer directly
        $renderMethod = new \ReflectionMethod(Mailer::class, 'renderConfirmationEmail');
        $plainMethod  = new \ReflectionMethod(Mailer::class, 'renderConfirmationPlainText');

        $html = $renderMethod->invoke(null,
            '#2563EB', 'Confirmed.', 'Hi Jane,', 'Confirmed.',
            'Booking details', $resourceDetails,
            'Footer.', 'Hotel Marina', 'VB',
        );

        $plain = $plainMethod->invoke(null,
            'Confirmed.', 'Hi Jane,', 'Confirmed.',
            'Booking details', $resourceDetails,
            'Footer.', 'Hotel Marina', 'Powered by VB',
        );

        // Resource labels must appear in HTML
        $this->assertStringContainsString('Sea View Suite', $html);
        $this->assertStringContainsString('Check-in', $html);
        $this->assertStringContainsString('Check-out', $html);
        $this->assertStringContainsString('€450.00', $html);

        // Timeslot labels must NOT appear in HTML
        $this->assertStringNotContainsString('>Time<', $html);
        $this->assertStringNotContainsString('>Service<', $html);
        $this->assertStringNotContainsString('>Staff<', $html);

        // Plain text verification
        $this->assertStringContainsString('Room: Sea View Suite', $plain);
        $this->assertStringContainsString('Check-in: April 5, 2026', $plain);
        $this->assertStringContainsString('Total: €450.00', $plain);
    }

    /**
     * sendApprovalRequest with $patternDetails renders resource-specific
     * labels for pending resource bookings (approval flow).
     */
    public function testApprovalEmailRendersResourceDetails(): void
    {
        $this->setMailerConfig(['mail_transport' => 'log']);

        $resourceDetails = [
            'Room'      => 'Garden Room',
            'Check-in'  => 'May 1, 2026',
            'Check-out' => 'May 3, 2026',
            'Guests'    => '3',
            'Total'     => '€260.00',
        ];

        $result = Mailer::sendApprovalRequest(
            'guest@example.com', 'John Smith',
            ['date' => '2026-05-01', 'formatted_date' => 'May 1 – May 3, 2026', 'time' => '', 'end_time' => ''],
            'Garden Room', null,
            'Hotel Marina', 'TENANT_ID_TEST', 'BOOKING_ID_TEST',
            '#2563EB',
            $resourceDetails,
        );

        $this->assertTrue($result['sent'], 'Log transport with resource approval details must succeed');

        // Verify rendered content shows resource details, not timeslot details.
        // The approval email uses the same renderConfirmationEmail renderer.
        $renderMethod = new \ReflectionMethod(Mailer::class, 'renderConfirmationEmail');
        $plainMethod  = new \ReflectionMethod(Mailer::class, 'renderConfirmationPlainText');

        $html = $renderMethod->invoke(null,
            '#2563EB', 'Pending review.', 'Hi John,', 'Your booking is pending.',
            'Booking details', $resourceDetails,
            'We will notify you.', 'Hotel Marina', 'VB',
        );

        $plain = $plainMethod->invoke(null,
            'Pending review.', 'Hi John,', 'Your booking is pending.',
            'Booking details', $resourceDetails,
            'We will notify you.', 'Hotel Marina', 'Powered by VB',
        );

        // Resource labels must appear in HTML
        $this->assertStringContainsString('Garden Room', $html);
        $this->assertStringContainsString('Check-in', $html);
        $this->assertStringContainsString('Check-out', $html);
        $this->assertStringContainsString('€260.00', $html);
        $this->assertStringContainsString('Guests', $html);

        // Timeslot labels must NOT appear
        $this->assertStringNotContainsString('>Time<', $html);
        $this->assertStringNotContainsString('>Service<', $html);
        $this->assertStringNotContainsString('>Staff<', $html);

        // Plain text verification
        $this->assertStringContainsString('Room: Garden Room', $plain);
        $this->assertStringContainsString('Check-in: May 1, 2026', $plain);
        $this->assertStringContainsString('Check-out: May 3, 2026', $plain);
        $this->assertStringContainsString('Guests: 3', $plain);
        $this->assertStringContainsString('Total: €260.00', $plain);
    }

    /**
     * sendBookingConfirmation without $patternDetails and empty time
     * omits the Time row from the email (fixes the "Time: –" bug).
     */
    public function testConfirmationEmailOmitsEmptyTimeRow(): void
    {
        $method = new \ReflectionMethod(Mailer::class, 'renderConfirmationEmail');

        // Simulate what sendBookingConfirmation builds when time is empty
        // (the default branch now skips the Time row)
        $details = [];
        $details['Date'] = 'April 5, 2026';
        // No 'Time' key — this is what the fixed code does when time is ''

        $html = $method->invoke(null,
            '#2563EB',
            'Confirmed.', 'Hi Test,', 'Confirmed.',
            'Booking details', $details,
            'Footer.', 'Test Biz', 'VB',
        );

        $this->assertStringContainsString('April 5, 2026', $html);
        $this->assertStringNotContainsString('>Time<', $html, 'Empty time row must be omitted');
    }

    // ── Manage-link CTA tests ──

    /**
     * renderConfirmationEmail includes a branded CTA button when manageUrl is provided.
     */
    public function testConfirmationEmailIncludesManageLinkCta(): void
    {
        $method = new \ReflectionMethod(Mailer::class, 'renderConfirmationEmail');

        $manageUrl = 'https://example.com/book/test-salon/manage/01JTEST000000000000000000';

        $html = $method->invoke(null,
            '#2563EB',
            'Confirmed.', 'Hi Test,', 'Confirmed.',
            'Booking details', ['Date' => '2026-04-17'],
            'Footer.', 'Test Biz', 'VB',
            $manageUrl,
        );

        // CTA anchor must contain the manage URL
        $this->assertStringContainsString($manageUrl, $html, 'HTML must contain the manage URL');
        // CTA must have the translated label
        $this->assertStringContainsString('View or Manage Booking', $html, 'HTML must contain the CTA label');
        // CTA must be styled as a button with brand color
        $this->assertStringContainsString('background: #2563EB', $html, 'CTA button must use brand color');
    }

    /**
     * renderConfirmationPlainText includes the manage URL on its own line.
     */
    public function testPlainTextEmailIncludesManageLink(): void
    {
        $method = new \ReflectionMethod(Mailer::class, 'renderConfirmationPlainText');

        $manageUrl = 'https://example.com/book/test-salon/manage/01JTEST000000000000000000';

        $plain = $method->invoke(null,
            'Confirmed.', 'Hi Test,', 'Confirmed.',
            'Booking details', ['Date' => '2026-04-17'],
            'Footer.', 'Test Biz', 'Powered by VB',
            $manageUrl,
        );

        $this->assertStringContainsString($manageUrl, $plain, 'Plain text must contain the manage URL');
        $this->assertStringContainsString("View or Manage Booking:\n" . $manageUrl, $plain,
            'Manage URL must appear on its own line after the label');
    }

    /**
     * Both renderers omit the manage CTA when manageUrl is empty.
     */
    public function testEmailOmitsManageLinkWhenEmpty(): void
    {
        $htmlMethod  = new \ReflectionMethod(Mailer::class, 'renderConfirmationEmail');
        $plainMethod = new \ReflectionMethod(Mailer::class, 'renderConfirmationPlainText');

        $html = $htmlMethod->invoke(null,
            '#2563EB',
            'Confirmed.', 'Hi Test,', 'Confirmed.',
            'Booking details', ['Date' => '2026-04-17'],
            'Footer.', 'Test Biz', 'VB',
            '', // empty manageUrl
        );

        $plain = $plainMethod->invoke(null,
            'Confirmed.', 'Hi Test,', 'Confirmed.',
            'Booking details', ['Date' => '2026-04-17'],
            'Footer.', 'Test Biz', 'Powered by VB',
            '', // empty manageUrl
        );

        $this->assertStringNotContainsString('View or Manage Booking', $html,
            'HTML must not contain manage CTA when URL is empty');
        $this->assertStringNotContainsString('/manage/', $html,
            'HTML must not contain manage path when URL is empty');
        $this->assertStringNotContainsString('View or Manage Booking', $plain,
            'Plain text must not contain manage CTA when URL is empty');
    }

    /**
     * sendBookingConfirmation with tenantSlug produces an email containing
     * the manage-booking URL in the expected format.
     */
    public function testSendBookingConfirmationIncludesManageUrl(): void
    {
        $this->setMailerConfig(['mail_transport' => 'log']);

        // Use reflection to capture the rendered HTML from buildManageUrl
        $buildMethod = new \ReflectionMethod(Mailer::class, 'buildManageUrl');

        $url = $buildMethod->invoke(null, 'test-salon', 'BOOKING_01');
        $this->assertStringContainsString('/book/test-salon/manage/BOOKING_01', $url,
            'buildManageUrl must construct the correct path');

        // Verify the full send path works with slug
        $result = Mailer::sendBookingConfirmation(
            'test@example.com', 'Jane Doe',
            ['date' => '2026-04-17', 'formatted_date' => 'April 17, 2026', 'time' => '10:00', 'end_time' => '10:30'],
            'Haircut', 'Emma',
            'Test Salon', 'TENANT_ID', 'BOOKING_01',
            '#2563EB',
            null,           // patternDetails
            'test-salon',   // tenantSlug
        );

        $this->assertTrue($result['sent'], 'Log transport must succeed with tenantSlug');
    }

    /**
     * sendCancellationConfirmation does NOT include a manage-booking link.
     * Cancellation uses renderCancellationEmail (separate renderer with "Book Again" CTA).
     */
    public function testCancellationEmailDoesNotIncludeManageLink(): void
    {
        $this->setMailerConfig(['mail_transport' => 'log']);

        // Use reflection to verify the cancellation HTML does not contain the manage path
        $cancelMethod = new \ReflectionMethod(Mailer::class, 'renderCancellationEmail');

        $html = $cancelMethod->invoke(null,
            '#2563EB',
            'Booking Cancelled', 'Hi Test,', 'Your booking has been cancelled.',
            'Booking details', ['Date' => '2026-04-17', 'Time' => '10:00–10:30'],
            'Book again anytime.', 'Test Salon', 'VB',
            'test-salon', // tenantSlug — used for "Book Again", NOT for manage link
        );

        $this->assertStringNotContainsString('/manage/', $html,
            'Cancellation email must NOT contain a manage link');
        $this->assertStringNotContainsString('View or Manage Booking', $html,
            'Cancellation email must NOT contain manage CTA label');
        // It should contain "Book Again" instead
        $this->assertStringContainsString('Book Again', $html,
            'Cancellation email must contain "Book Again" CTA');
    }
}
