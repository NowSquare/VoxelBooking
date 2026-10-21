<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Engine\Database;
use App\Engine\Outbox;
use App\Engine\Ulid;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestEnvironment;

/**
 * Outbox delivery guarantees that do not depend on any one topic.
 *
 * Synthetic topics with their own handlers keep these cases independent of
 * mail and webhook behaviour.
 */
final class OutboxDeliveryTest extends TestCase
{
    /** @var list<string> */
    private array $rows = [];

    protected function setUp(): void
    {
        TestEnvironment::boot();
        TestFixtures::provision();
        // Leftover rows from an earlier request in this process must not leak in.
        Outbox::deliverEnqueued([]);
    }

    protected function tearDown(): void
    {
        foreach ($this->rows as $id) {
            Database::execute('DELETE FROM delivery_outbox WHERE id = ?', [$id]);
        }
    }

    public function testLeaseOutlivesTheSlowestHandler(): void
    {
        $id = $this->enqueue('test.lease');
        $remaining = null;

        Outbox::deliverEnqueued(['test.lease'], ['test.lease' => function () use ($id, &$remaining): array {
            $lease = Database::query('SELECT lease_until FROM delivery_outbox WHERE id = ?', [$id])[0]['lease_until'];
            $remaining = strtotime($lease . ' UTC') - time();
            return ['sent' => true];
        }]);

        // SMTP and webhook calls time out within seconds, far inside ten minutes.
        $this->assertGreaterThanOrEqual(590, $remaining);
    }

    public function testAnExpiredLeaseIsClaimedAgain(): void
    {
        $id = $this->enqueue('test.reclaim');
        Outbox::deliverEnqueued([]);
        Database::execute(
            "UPDATE delivery_outbox SET status = 'sending', attempts = 1, lease_token = 'dead-worker', lease_until = ?, available_at = '2000-01-01' WHERE id = ?",
            [gmdate('Y-m-d H:i:s', time() - 1), $id]
        );
        $runs = 0;

        Outbox::dispatch(100, ['test.reclaim' => function () use (&$runs): array {
            $runs++;
            return ['sent' => true];
        }]);

        $this->assertSame(1, $runs, 'A worker that died mid-delivery does not strand its row.');
        $this->assertSame('delivered', Database::query('SELECT status FROM delivery_outbox WHERE id = ?', [$id])[0]['status']);
    }

    public function testImmediateDeliverySendsOnlyTheChosenTopicsOfThisRequest(): void
    {
        $now = $this->enqueue('test.now');
        $later = $this->enqueue('test.later');
        $sent = [];
        $handler = function (array $payload) use (&$sent): array {
            $sent[] = $payload['marker'];
            return ['sent' => true];
        };

        $outcomes = Outbox::deliverEnqueued(['test.now'], ['test.now' => $handler, 'test.later' => $handler]);

        $this->assertSame([$now], array_column($outcomes, 'id'));
        $this->assertSame('delivered', $outcomes[0]['state']);
        $this->assertTrue($outcomes[0]['sent']);
        $this->assertSame(['test.now'], $sent);
        $this->assertSame('pending', Database::query('SELECT status FROM delivery_outbox WHERE id = ?', [$later])[0]['status']);
    }

    public function testImmediateFailureStaysDueForTheNextRun(): void
    {
        $id = $this->enqueue('test.flaky');

        $outcomes = Outbox::deliverEnqueued(['test.flaky'], ['test.flaky' => static fn (): array => ['sent' => false]]);

        $this->assertSame('queued', $outcomes[0]['state']);
        $this->assertFalse($outcomes[0]['sent']);
        $row = Database::query('SELECT status, attempts, available_at FROM delivery_outbox WHERE id = ?', [$id])[0];
        $this->assertSame('pending', $row['status']);
        $this->assertSame(1, (int) $row['attempts']);
        $this->assertLessThanOrEqual(time(), strtotime($row['available_at'] . ' UTC'), 'No backoff after the attempt made at commit.');
    }

    public function testDeliveryWaitsForTheCommit(): void
    {
        $this->expectException(\LogicException::class);
        Database::transaction(static fn () => Outbox::deliverEnqueued(['test.now']));
    }

    public function testSuppressedMailIsDeliveredButNotSent(): void
    {
        $this->enqueue('test.demo');

        $outcomes = Outbox::deliverEnqueued(['test.demo'], ['test.demo' => static fn (): array => ['sent' => false, 'demo_suppressed' => true]]);

        $this->assertSame('delivered', $outcomes[0]['state']);
        $this->assertFalse($outcomes[0]['sent'], 'Demo mode never claims an email left.');
    }

    private function enqueue(string $topic): string
    {
        $id = Outbox::enqueue($topic, ['marker' => $topic], null, $topic . ':' . Ulid::generate());
        $this->rows[] = $id;

        return $id;
    }
}
