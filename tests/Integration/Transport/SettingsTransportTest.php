<?php

declare(strict_types=1);

namespace Tests\Integration\Transport;

use App\Agent\ActorContext;
use App\Application\Settings\EmailTemplates;
use App\Engine\Database;
use Tests\Support\Transport\TransportTestCase;

/** Business settings, email templates and media upload grants over HTTP v2 and native MCP. */
final class SettingsTransportTest extends TransportTestCase
{
    /** section => [HTTP changes, MCP changes]; each transport leaves a distinct, verifiable value. */
    private const CHANGES = [
        'general' => [['name' => 'Studio over HTTP', 'timezone' => 'Europe/Amsterdam'], ['name' => 'Studio over MCP', 'timezone' => 'America/New_York']],
        'branding' => [['brand_color' => '#234567', 'booking_page_heading' => 'Welcome over HTTP'], ['brand_color' => '#345678', 'booking_page_heading' => 'Welcome over MCP']],
        'bookingpage' => [['confirmation_message' => 'See you soon (HTTP)', 'cancellation_hours_before' => 4], ['confirmation_message' => 'See you soon (MCP)', 'cancellation_hours_before' => 6]],
        'booking' => [['slot_duration_minutes' => 20, 'buffer_minutes' => 10], ['slot_duration_minutes' => 45, 'buffer_minutes' => 5]],
        'privacy' => [['requires_consent' => true, 'consent_text' => 'I agree (HTTP)', 'data_retention_months' => 18], ['requires_consent' => true, 'consent_text' => 'I agree (MCP)', 'data_retention_months' => 24]],
        'notifications' => [['notification_email' => 'team-http@example.com', 'reminder_hours_before' => 12], ['notification_email' => 'team-mcp@example.com', 'reminder_hours_before' => 24]],
        'embed' => [['embed_button_position' => 'bottom-left', 'embed_button_label' => 'Reserve (HTTP)'], ['embed_button_position' => 'bottom-right', 'embed_button_label' => 'Reserve (MCP)']],
    ];

    private string $business;
    private ActorContext $session;

    protected function setUp(): void
    {
        parent::setUp();
        $this->business = $this->tenant('timeslot')['id'];
        $this->session = $this->operatorSession([$this->business]);
    }

    protected function tearDown(): void
    {
        Database::execute('DELETE FROM agent_media_grants WHERE tenant_id=?', [$this->business]);
        parent::tearDown();
    }

    private function assertSettingsUpdate(string $section): void
    {
        $client = $this->connect($this->session, ['business_' . $section . '_get', 'business_' . $section . '_update']);
        $input = ['business_id' => $this->business];
        foreach (array_combine(self::TRANSPORTS, self::CHANGES[$section]) as $transport => $changes) {
            $before = $this->read($this->session, 'business_' . $section . '_get', $input);
            $data = $this->call($transport, $client, 'business_' . $section . '_update', [...$input, 'expected_version' => $before['version'], 'changes' => $changes])['data'];
            foreach ($changes as $field => $value) {
                self::assertSame($value, $data['values'][$field], $section . '.' . $field . ' over ' . $transport);
            }
            self::assertNotSame($before['version'], $data['version']);
            self::assertSame($data, $this->read($this->session, 'business_' . $section . '_get', $input), $section . ' persisted over ' . $transport);
        }
    }

    public function testBusinessGeneralUpdateOverHttpAndMcp(): void { $this->assertSettingsUpdate('general'); }
    public function testBusinessBrandingUpdateOverHttpAndMcp(): void { $this->assertSettingsUpdate('branding'); }
    public function testBusinessBookingpageUpdateOverHttpAndMcp(): void { $this->assertSettingsUpdate('bookingpage'); }
    public function testBusinessBookingUpdateOverHttpAndMcp(): void { $this->assertSettingsUpdate('booking'); }
    public function testBusinessPrivacyUpdateOverHttpAndMcp(): void { $this->assertSettingsUpdate('privacy'); }
    public function testBusinessNotificationsUpdateOverHttpAndMcp(): void { $this->assertSettingsUpdate('notifications'); }
    public function testBusinessEmbedUpdateOverHttpAndMcp(): void { $this->assertSettingsUpdate('embed'); }

    public function testEmailTemplatesGetOverHttpAndMcp(): void
    {
        $client = $this->connect($this->session, ['email_templates_get']);
        $expected = $this->read($this->session, 'email_templates_get', ['business_id' => $this->business]);
        foreach (self::TRANSPORTS as $transport) {
            $data = $this->call($transport, $client, 'email_templates_get', ['business_id' => $this->business])['data'];
            self::assertSame($expected, $data, $transport);
            self::assertSame(EmailTemplates::TYPES, array_column($data['templates'], 'type'));
        }
    }

    public function testEmailTemplatesUpdateOverHttpAndMcp(): void
    {
        $client = $this->connect($this->session, ['email_templates_get', 'email_templates_update']);
        $input = ['business_id' => $this->business];
        foreach (self::TRANSPORTS as $transport) {
            $before = $this->read($this->session, 'email_templates_get', $input);
            $subject = 'Hello {customer_name} via ' . $transport;
            $data = $this->call($transport, $client, 'email_templates_update', [...$input, 'expected_version' => $before['version'],
                'templates' => [['type' => 'confirmation', 'subject' => $subject, 'is_enabled' => true]]])['data'];
            self::assertSame($subject, array_column($data['templates'], null, 'type')['confirmation']['subject'], $transport);
            self::assertSame($data, $this->read($this->session, 'email_templates_get', $input), $transport);
            self::assertSame($subject, Database::query("SELECT subject FROM tenant_email_templates WHERE tenant_id=? AND type='confirmation'", [$this->business])[0]['subject']);
        }
    }

    private function seedReminderTemplate(): array
    {
        $this->seedCommand($this->session, 'email_templates_update', ['business_id' => $this->business,
            'expected_version' => $this->read($this->session, 'email_templates_get', ['business_id' => $this->business])['version'],
            'templates' => [['type' => 'reminder', 'subject' => 'Reminder for {customer_name}', 'is_enabled' => true]]]);
        return $this->connect($this->session, ['email_template_preview']);
    }

    private function assertReminderSample(array $data): void
    {
        self::assertSame('reminder', $data['type']);
        self::assertTrue($data['synthetic']);
        // The sample uses invented people, never a real customer.
        self::assertSame('Reminder for Alex Example', $data['content']['subject']);
    }

    public function testEmailTemplatePreviewOverHttp(): void
    {
        $client = $this->seedReminderTemplate();
        $this->assertReminderSample($this->call('http', $client, 'email_template_preview', ['business_id' => $this->business, 'type' => 'reminder'])['data']);
    }

    /**
     * email_template_preview is an operation of its own whose name ends in
     * "_preview"; MCP must not mistake it for the preview of another command.
     */
    public function testEmailTemplatePreviewOverMcp(): void
    {
        $client = $this->seedReminderTemplate();
        $arguments = ['business_id' => $this->business, 'type' => 'reminder'];
        $this->assertReminderSample($this->call('mcp', $client, 'email_template_preview', $arguments)['data']);
    }

    public function testMediaUploadPrepareOverHttpAndMcp(): void
    {
        $client = $this->connect($this->session, ['media_upload_prepare']);
        foreach (self::TRANSPORTS as $transport) {
            $data = $this->call($transport, $client, 'media_upload_prepare', ['business_id' => $this->business, 'purpose' => 'logo'])['data'];
            self::assertStringStartsWith(\App\Agent\Endpoints::baseUrl() . '/api/agent/v2/uploads/', $data['upload_url'], $transport);
            self::assertGreaterThan(0, $data['max_bytes']);
            $grant = Database::query('SELECT tenant_id, consumed_at FROM agent_media_grants WHERE id=?', [$data['media_id']]);
            self::assertSame([['tenant_id' => $this->business, 'consumed_at' => null]], $grant, $transport);
        }
    }
}
