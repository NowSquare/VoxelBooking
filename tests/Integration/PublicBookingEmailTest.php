<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Engine\CustomerCapability;
use App\Engine\Database;
use App\Engine\Outbox;
use PHPUnit\Framework\TestCase;
use Tests\Support\PublicBookingClient;
use Tests\Support\TestEnvironment;
use Tests\Support\TimeslotBusiness;

/**
 * The booking page tells customers whether their email actually left.
 *
 * Booking emails go through the durable outbox. The booking page delivers
 * the customer's email right after the booking commits, so `email_sent` is
 * true only when the provider accepted it. When the provider fails, the
 * booking stands, the page reports the email as queued, and the next
 * delivery run sends it.
 */
final class PublicBookingEmailTest extends TestCase
{
    private ?TimeslotBusiness $business = null;
    private string $service = '';

    /** @var array<string, ?string> mail settings to restore */
    private array $priorMailSettings = [];

    protected function setUp(): void
    {
        TestEnvironment::boot();
        TestFixtures::provision();
        Database::execute('DELETE FROM `rate_limits`');
        $this->business = TimeslotBusiness::create(['allow_cancellation' => 1, 'allow_rescheduling' => 1, 'cancellation_hours_before' => 0, 'rescheduling_hours_before' => 0]);
        $this->service = $this->business->service();
        // A real provider transport: the test sink still captures every message,
        // but only a provider that reaches inboxes may claim an email was sent.
        $this->useMailSetting('mail_transport', 'smtp');
        $this->useMailSetting('smtp_host', 'smtp.example.test');
    }

    protected function tearDown(): void
    {
        TestEnvironment::failMail(null);
        foreach ($this->priorMailSettings as $key => $value) {
            $value === null
                ? Database::execute('DELETE FROM `settings` WHERE `key` = ?', [$key])
                : Database::execute('UPDATE `settings` SET `value` = ? WHERE `key` = ?', [$value, $key]);
        }
        $this->business?->delete();
    }

    public function testLogTransportNeverClaimsTheEmailWasSent(): void
    {
        Database::execute("UPDATE `settings` SET `value` = 'log' WHERE `key` = 'mail_transport'");

        $response = $this->book('09:30');

        $this->assertSame(201, $response['code'], $response['body']);
        $this->assertFalse($response['json']['booking']['email_sent'], 'Logged mail never reaches the customer.');
        $this->assertSame('delivered', $response['json']['booking']['notification_state']);
    }

    public function testConfirmationReportsTheEmailThatLeft(): void
    {
        $response = $this->book('10:00');

        $this->assertSame(201, $response['code'], $response['body']);
        $this->assertTrue($response['json']['booking']['email_sent']);
        $this->assertSame('delivered', $response['json']['booking']['notification_state']);
        $this->assertSame(['confirmation'], $this->sentTypes($response['json']['booking']['id']), 'Sent before any delivery run.');
    }

    public function testProviderFailureKeepsTheBookingAndQueuesTheEmail(): void
    {
        TestEnvironment::failMail('Synthetic provider outage');
        $response = $this->book('11:00');
        TestEnvironment::failMail(null);

        $this->assertSame(201, $response['code'], $response['body']);
        $this->assertFalse($response['json']['booking']['email_sent']);
        $this->assertSame('queued', $response['json']['booking']['notification_state']);
        $bookingId = $response['json']['booking']['id'];
        $this->assertSame('confirmed', Database::query('SELECT status FROM bookings WHERE id = ?', [$bookingId])[0]['status']);
        $this->assertSame([], $this->sentTypes($bookingId));

        // The failed attempt leaves the email due now, not after a backoff.
        Outbox::dispatch(100);
        $this->assertSame(['confirmation'], $this->sentTypes($bookingId));
    }

    public function testCancelAndRescheduleReportTheirEmails(): void
    {
        $client = PublicBookingClient::fromEnvironment();
        $first = $this->book('12:00')['json']['booking']['id'];
        $moved = $client->manage($this->business->slug, $first, 'reschedule', $this->capability($first), [
            'new_date' => TimeslotBusiness::day(5),
            'new_time' => '13:00',
        ]);
        $this->assertSame(200, $moved['code'], $moved['body']);
        $this->assertTrue($moved['json']['email_sent']);
        $this->assertSame('delivered', $moved['json']['notification_state']);

        $second = $this->book('14:00')['json']['booking']['id'];
        $cancelled = $client->manage($this->business->slug, $second, 'cancel', $this->capability($second));
        $this->assertSame(200, $cancelled['code'], $cancelled['body']);
        $this->assertTrue($cancelled['json']['email_sent']);
        $this->assertSame(['confirmation', 'cancellation'], $this->sentTypes($second));
    }

    // ── Helpers ──

    /**
     * @return array{code: int, body: string, json: array<string, mixed>}
     */
    private function book(string $time): array
    {
        return PublicBookingClient::fromEnvironment()->book($this->business->slug, [
            'service_id' => $this->service,
            'start_datetime' => TimeslotBusiness::day(4) . 'T' . $time . ':00',
            'customer' => ['name' => 'Robin Example', 'email' => 'robin@example.com'],
        ]);
    }

    private function useMailSetting(string $key, string $value): void
    {
        $this->priorMailSettings[$key] = Database::query('SELECT `value` FROM `settings` WHERE `key` = ?', [$key])[0]['value'] ?? null;
        Database::execute(
            'INSERT INTO `settings` (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)',
            [$key, $value]
        );
    }

    private function capability(string $bookingId): string
    {
        return CustomerCapability::issueManage($this->business->id, $bookingId);
    }

    /** @return list<string> customer email types sent for one booking */
    private function sentTypes(string $bookingId): array
    {
        $path = TestEnvironment::manifest()['run_root'] . '/mail.jsonl';
        $types = [];
        foreach (is_file($path) ? file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [] as $line) {
            $message = json_decode($line, true);
            if (($message['booking_id'] ?? null) === $bookingId && ($message['error'] ?? null) === null && $message['type'] !== 'staff_notification') {
                $types[] = $message['type'];
            }
        }

        return $types;
    }
}
