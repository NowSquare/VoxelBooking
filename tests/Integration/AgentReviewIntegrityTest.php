<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Agent\OperationException;
use App\Application\Bookings\BookingCommands;
use App\Engine\Database;
use App\Engine\Ulid;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestEnvironment;
use Tests\Support\TimeslotBusiness;

/**
 * Review approval and consent cannot be fabricated by the caller.
 *
 * An anonymous agent receives a commit handle for itself and a review link
 * for the person. The two secrets differ: the link cannot commit and the
 * handle cannot open the review. Because the agent relays the link, the
 * server cannot prove a person rather than a script opened it, so a public
 * approval is recorded as the customer's own browser approval
 * (`customer_browser`), never as a trusted staff review, and the booking is
 * recorded as made through the API. Consent comes only from the review page,
 * with the consent text shown there; staff and agents can never supply it.
 *
 * Every step below is a plain HTTP request, as a script would send it.
 */
final class AgentReviewIntegrityTest extends TestCase
{
    private const CONSENT_TEXT = 'I agree that Example Studio stores my booking details.';

    private ?TimeslotBusiness $business = null;
    private string $service = '';
    private string $cookies = '';

    protected function setUp(): void
    {
        TestEnvironment::boot();
        TestFixtures::provision();
        Database::execute('DELETE FROM `rate_limits`');
        Database::execute('DELETE FROM `agent_rate_budgets`');
        $this->business = TimeslotBusiness::create(['requires_consent' => 1, 'consent_text' => self::CONSENT_TEXT]);
        $this->service = $this->business->service();
        $this->cookies = (string) tempnam(sys_get_temp_dir(), 'vb-review-');
    }

    protected function tearDown(): void
    {
        @unlink($this->cookies);
        $this->business?->delete();
    }

    public function testCommitHandleCannotOpenTheReview(): void
    {
        $preview = $this->preview('10:00');
        [$id] = $this->reviewLink($preview);

        $exchange = $this->exchange($id, $preview['preview_handle']);

        $this->assertSame(404, $exchange['status'], 'The agent\'s commit handle is not a review secret.');
    }

    public function testReviewSecretCannotCommit(): void
    {
        $preview = $this->preview('10:30');
        [, $secret] = $this->reviewLink($preview);

        $commit = $this->commit($secret);

        $this->assertSame(404, $commit['status'], $commit['raw']);
        $this->assertSame(0, (int) Database::query('SELECT COUNT(*) AS n FROM bookings WHERE tenant_id = ?', [$this->business->id])[0]['n']);
    }

    public function testSelfApprovedPublicBookingIsRecordedAsTheCustomersOwnApproval(): void
    {
        $preview = $this->preview('11:00');
        [$id, $secret] = $this->reviewLink($preview);
        $this->assertNotSame($preview['preview_handle'], $secret);

        $this->assertSame(200, $this->exchange($id, $secret)['status']);
        $page = $this->http('GET', '/agent-reviews/' . $id);
        $this->assertSame(1, preg_match('/name="_csrf_token" value="([^"]+)"/', $page['raw'], $token), 'The review page renders its form.');
        $this->assertStringContainsString(e(self::CONSENT_TEXT), $page['raw'], 'The review shows the consent text it records.');
        $this->http('POST', '/agent-reviews/' . $id, http_build_query(['_csrf_token' => $token[1], 'consent' => '1']), form: true);
        $commit = $this->commit($preview['preview_handle']);

        $this->assertSame(200, $commit['status'], $commit['raw']);
        $approval = Database::query('SELECT approval_source FROM agent_previews WHERE id = ?', [$id])[0]['approval_source'];
        $this->assertSame('customer_browser', $approval);
        $booking = Database::query('SELECT source, consent_given_at, consent_text_shown FROM bookings WHERE id = ?', [$commit['json']['data']['id']])[0];
        $this->assertSame('api', $booking['source'], 'An agent booking is not recorded as a booking page booking.');
        $this->assertNotNull($booking['consent_given_at']);
        $this->assertSame(self::CONSENT_TEXT, $booking['consent_text_shown']);
    }

    public function testAgentsCannotSupplyConsent(): void
    {
        $response = $this->http('POST', '/api/agent/v2/public/' . $this->business->slug . '/bookings/preview', json_encode($this->booking('12:00') + ['consent_given' => true]), [
            'X-Booking-Continuation: ' . $this->continuation(),
        ]);

        $this->assertSame(422, $response['status'], $response['raw']);
        $this->assertStringContainsString('consent_given', json_encode($response['json']['field_errors'] ?? []));
    }

    public function testStaffBookingsNeverRecordCustomerConsent(): void
    {
        $receipt = BookingCommands::create($this->business->owner(), $this->booking('13:00') + ['consent_given' => true]);

        $booking = Database::query('SELECT source, consent_given_at, consent_text_shown FROM bookings WHERE id = ?', [$receipt['id']])[0];
        $this->assertSame('api', $booking['source'], 'Staff working through a connection book through the API.');
        $this->assertNull($booking['consent_given_at'], 'Staff cannot give consent on a customer\'s behalf.');
        $this->assertNull($booking['consent_text_shown']);
        $this->assertFalse($receipt['consent_recorded']);
    }

    // ── Helpers ──

    private function booking(string $time): array
    {
        return [
            'business_id' => $this->business->id,
            'service_id' => $this->service,
            'date' => TimeslotBusiness::day(4),
            'time' => $time,
            'customer' => ['name' => 'Robin Example', 'email' => 'robin-' . strtolower(substr(Ulid::generate(), -6)) . '@example.com'],
        ];
    }

    private function continuation(): string
    {
        $session = $this->http('POST', '/api/agent/v2/public/' . $this->business->slug . '/session', '{}');
        $this->assertSame(200, $session['status'], $session['raw']);

        return $session['json']['continuation'];
    }

    private string $continuation = '';

    /** @return array<string, mixed> */
    private function preview(string $time): array
    {
        $this->continuation = $this->continuation();
        $response = $this->http('POST', '/api/agent/v2/public/' . $this->business->slug . '/bookings/preview', json_encode($this->booking($time)), [
            'X-Booking-Continuation: ' . $this->continuation,
        ]);
        $this->assertSame(200, $response['status'], $response['raw']);

        return $response['json'];
    }

    /** @return array{0: string, 1: string} review id and the secret in the link's fragment */
    private function reviewLink(array $preview): array
    {
        $id = basename((string) parse_url($preview['review_url'], PHP_URL_PATH));
        parse_str((string) parse_url($preview['review_url'], PHP_URL_FRAGMENT), $fragment);

        return [$id, (string) ($fragment['review'] ?? '')];
    }

    /** @return array{status: int, raw: string, json: mixed} */
    private function exchange(string $id, string $secret): array
    {
        $page = $this->http('GET', '/agent-reviews/' . $id);
        preg_match('/data-csrf="([^"]+)"/', $page['raw'], $token);

        return $this->http('POST', '/agent-reviews/' . $id . '/exchange', json_encode(['handle' => $secret]), [
            'X-CSRF-Token: ' . ($token[1] ?? ''),
        ]);
    }

    /** @return array{status: int, raw: string, json: mixed} */
    private function commit(string $handle): array
    {
        return $this->http('POST', '/api/agent/v2/public/' . $this->business->slug . '/bookings', json_encode(['preview_handle' => $handle]), [
            'X-Booking-Continuation: ' . $this->continuation,
            'Idempotency-Key: review-' . Ulid::generate(),
        ]);
    }

    /** @return array{status: int, raw: string, json: mixed} */
    private function http(string $method, string $path, ?string $body = null, array $headers = [], bool $form = false): array
    {
        $request = curl_init(rtrim((string) $_ENV['APP_TEST_URL'], '/') . $path);
        curl_setopt_array($request, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_COOKIEJAR => $this->cookies,
            CURLOPT_COOKIEFILE => $this->cookies,
            CURLOPT_HTTPHEADER => [
                'Content-Type: ' . ($form ? 'application/x-www-form-urlencoded' : 'application/json'),
                'Accept: application/json, text/html',
                ...$headers,
            ],
        ]);
        if ($body !== null) {
            curl_setopt($request, CURLOPT_POSTFIELDS, $body);
        }
        $raw = (string) curl_exec($request);
        $status = (int) curl_getinfo($request, CURLINFO_HTTP_CODE);
        // PHP writes the cookie jar when the handle is destroyed.
        unset($request);

        return ['status' => $status, 'raw' => $raw, 'json' => json_decode($raw, true)];
    }
}
