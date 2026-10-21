<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Agent\{OperationException, PublicContinuation, PublicTransport, TransportAuth};
use App\Engine\{Database, Request};
use PHPUnit\Framework\TestCase;

/**
 * L4: anonymous agent budgets belong to the actor that spends them.
 *
 * One address or one public session running dry must never starve another
 * address's booking task at the same business.
 */
final class PublicTransportBudgetTest extends TestCase
{
    private array $server = [];

    protected function setUp(): void
    {
        TestFixtures::provision();
        $this->server = $_SERVER;
        Database::execute('DELETE FROM agent_rate_budgets');
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
        // Later suites share loopback addresses; leave no exhausted window behind.
        Database::execute('DELETE FROM agent_rate_budgets');
    }

    private function requestFrom(string $address, ?string $continuation = null): Request
    {
        $_SERVER['REMOTE_ADDR'] = $address;
        unset($_SERVER['HTTP_X_BOOKING_CONTINUATION']);
        if ($continuation !== null) {
            $_SERVER['HTTP_X_BOOKING_CONTINUATION'] = $continuation;
        }
        $request = new Request();
        $request->setAttribute('slug', 'test-fixture');
        return $request;
    }

    private function continuation(): string
    {
        return (new PublicContinuation())->issue(TestFixtures::BUSINESS_TENANT_ID)['continuation'];
    }

    /** @return string|null The problem code, or null when the write was admitted. */
    private function write(string $address, ?string $continuation): ?string
    {
        try {
            PublicTransport::actor($this->requestFrom($address, $continuation), write: true);
            return null;
        } catch (OperationException $error) {
            return $error->problemCode;
        }
    }

    public function testManyExhaustedAddressesDoNotBlockAnotherAddressFromBooking(): void
    {
        // The old shared business bucket fell to about 25 addresses. More than
        // that, each pushing past its own budget, must leave a newcomer untouched.
        for ($attacker = 1; $attacker <= 30; $attacker++) {
            $address = '203.0.113.' . $attacker;
            $admitted = 0;
            for ($attempt = 0; $attempt < PublicTransport::WRITES_PER_ADDRESS + 3; $attempt++) {
                // A fresh session whenever the previous one is spent, so only the
                // address budget can stop this actor.
                if ($attempt % PublicTransport::WRITES_PER_CONTINUATION === 0) {
                    $continuation = $this->continuation();
                }
                if ($this->write($address, $continuation) === null) {
                    $admitted++;
                }
            }
            self::assertSame(PublicTransport::WRITES_PER_ADDRESS, $admitted);
            self::assertSame('rate_limited', $this->write($address, $this->continuation()));
        }

        self::assertNull($this->write('198.51.100.7', $this->continuation()));
    }

    public function testOneContinuationCannotExceedItsBudgetAcrossRotatingAddresses(): void
    {
        $continuation = $this->continuation();
        for ($write = 0; $write < PublicTransport::WRITES_PER_CONTINUATION; $write++) {
            self::assertNull($this->write('192.0.2.' . ($write + 1), $continuation));
        }

        self::assertSame('rate_limited', $this->write('192.0.2.250', $continuation));
        // The address itself still has room for a different booking task.
        self::assertNull($this->write('192.0.2.250', $this->continuation()));
    }

    public function testInvalidContinuationsOnlySpendTheSendingAddress(): void
    {
        for ($attempt = 0; $attempt < PublicTransport::WRITES_PER_ADDRESS + 3; $attempt++) {
            $code = $this->write('203.0.113.200', $attempt % 2 === 0 ? 'forged' : null);
            self::assertContains($code, ['continuation_expired', 'continuation_required', 'rate_limited']);
        }

        self::assertSame('rate_limited', $this->write('203.0.113.200', $this->continuation()));
        self::assertNull($this->write('203.0.113.201', $this->continuation()));
    }

    public function testSessionMintingIsBoundedPerAddressAndAnswersWithRetryAfter(): void
    {
        for ($session = 0; $session < PublicTransport::SESSION_STARTS_PER_ADDRESS; $session++) {
            self::assertArrayHasKey('continuation', PublicTransport::start($this->requestFrom('192.0.2.10')));
        }

        $blocked = $this->requestFrom('192.0.2.10');
        try {
            PublicTransport::start($blocked);
            self::fail('An address minted continuations past its budget');
        } catch (OperationException $error) {
            self::assertSame(429, $error->status);
            self::assertSame('rate_limited', $error->problemCode);
            $headers = TransportAuth::problem($error, $blocked)->getHeaders();
            self::assertGreaterThanOrEqual(1, (int) $headers['Retry-After']);
            self::assertLessThanOrEqual(3600, (int) $headers['Retry-After']);
            self::assertSame('0', $headers['X-RateLimit-Remaining']);
            self::assertSame((string) PublicTransport::SESSION_STARTS_PER_ADDRESS, $headers['X-RateLimit-Limit']);
        }

        // Minting is the only thing that address lost; its live sessions still book,
        // and every other address still starts sessions.
        self::assertNull($this->write('192.0.2.10', $this->continuation()));
        self::assertArrayHasKey('continuation', PublicTransport::start($this->requestFrom('192.0.2.11')));
    }

    public function testIpv6PrefixSharesOneAddressBudget(): void
    {
        for ($session = 0; $session < PublicTransport::SESSION_STARTS_PER_ADDRESS; $session++) {
            PublicTransport::start($this->requestFrom('2001:db8:1:2::' . dechex($session + 1)));
        }

        $this->expectExceptionObject(new OperationException('rate_limited', 429));
        PublicTransport::start($this->requestFrom('2001:db8:1:2:ffff::1'));
    }
}
