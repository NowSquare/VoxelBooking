<?php

declare(strict_types=1);

namespace App\Engine;

use App\Agent\PayloadCipher;

/**
 * Durable delivery of email, webhooks and other side effects.
 *
 * Domain code enqueues inside its transaction, so a delivery exists exactly
 * when its change commits. Cron dispatches due rows. A surface that used to
 * send at once, such as the booking page, calls deliverEnqueued() after its
 * transaction commits; cron then retries anything that did not go out.
 *
 * Delivery is at least once. A worker that stops after the provider accepts a
 * message but before recording it lets the message go out again after the
 * lease expires. Retries keep the outbox row's Message-ID, so mail clients
 * and webhook receivers can recognise a repeat.
 */
final class Outbox
{
    /** Longer than any handler can run: SMTP and webhook calls time out well within it. */
    private const LEASE_SECONDS = 600;

    /** The operation receipt whose command is running, so enqueued deliveries can report back to it. */
    private static ?string $receiptId = null;

    /**
     * Rows this request enqueued, in order, so the adapter that committed them can deliver them.
     *
     * @var array<string, array{topic: string, audience: ?string}>
     */
    private static array $enqueued = [];

    public static function currentReceiptId(): ?string
    {
        return self::$receiptId;
    }

    public static function duringOperation(string $receiptId, callable $command): mixed
    {
        $previous = self::$receiptId;
        self::$receiptId = $receiptId;
        try {
            return $command();
        } finally {
            self::$receiptId = $previous;
        }
    }

    public static function enqueue(string $topic, array $payload, ?string $tenantId, string $dedupeKey): string
    {
        if ($topic === '' || $dedupeKey === '') {
            throw new \InvalidArgumentException('Delivery identity required.');
        }
        $id = Ulid::generate();
        $hash = hash('sha256', $topic . "\0" . $tenantId . "\0" . $dedupeKey);
        Database::execute('INSERT INTO delivery_outbox (id, topic, tenant_id, operation_receipt_id, dedupe_hash, payload_cipher, available_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE dedupe_hash = dedupe_hash', [
            $id,
            $topic,
            $tenantId,
            self::$receiptId,
            $hash,
            (new PayloadCipher())->encrypt($payload),
            gmdate('Y-m-d H:i:s'),
            gmdate('Y-m-d H:i:s')
        ]);
        // A duplicate enqueue is a no-op, so return the id of whichever row won.
        $winner = Database::query('SELECT id FROM delivery_outbox WHERE dedupe_hash = ?', [$hash])[0]['id'];
        self::$enqueued[$winner] = ['topic' => $topic, 'audience' => isset($payload['audience']) ? (string) $payload['audience'] : null];

        return $winner;
    }

    /**
     * Deliver the rows this request enqueued for the given topics, after commit.
     *
     * Rows of other topics stay with cron. A row this request cannot claim
     * (another worker holds it, or its transaction rolled back) reports its
     * stored state. A failed attempt leaves the row due at once for cron.
     *
     * @param list<string> $topics
     * @return list<array{id: string, topic: string, audience: ?string, state: string, sent: bool}>
     */
    public static function deliverEnqueued(array $topics, array $handlers = []): array
    {
        if (Database::pdo()->inTransaction()) {
            throw new \LogicException('Deliver only after the transaction that enqueued the rows commits.');
        }
        $enqueued = self::$enqueued;
        self::$enqueued = [];

        $outcomes = [];
        foreach ($enqueued as $id => $meta) {
            if (!in_array($meta['topic'], $topics, true)) {
                continue;
            }
            $lease = bin2hex(random_bytes(32));
            $row = self::claim($lease, $id);
            $outcome = $row === null
                ? ['state' => self::state($id), 'sent' => false]
                : self::process($row, $lease, $handlers, retryAtOnce: true);
            $outcomes[] = ['id' => $id] + $meta + $outcome;
        }

        return $outcomes;
    }

    /** Bounded work with leases: slow delivery does not hold domain row locks. */
    public static function dispatch(int $limit = 25, array $handlers = []): array
    {
        $limit = max(1, min(100, $limit));
        $counts = ['delivered' => 0, 'retrying' => 0, 'failed' => 0];
        for ($i = 0; $i < $limit; $i++) {
            $lease = bin2hex(random_bytes(32));
            $row = self::claim($lease);
            if ($row === null) {
                break;
            }
            $outcome = self::process($row, $lease, $handlers, retryAtOnce: false);
            $counts[match ($outcome['state']) {
                'delivered' => 'delivered',
                'failed' => 'failed',
                default => 'retrying',
            }]++;
        }
        return $counts;
    }

    /**
     * Claim the next due row, or one specific row when it is due.
     *
     * @return array<string, mixed>|null the claimed row with its new attempt count
     */
    private static function claim(string $lease, ?string $id = null): ?array
    {
        return Database::transaction(static function () use ($lease, $id): ?array {
            $now = gmdate('Y-m-d H:i:s');
            // An expired lease means the previous worker died mid-delivery, so the row is claimable again.
            $due = "((status = 'pending' AND available_at <= ?) OR (status = 'sending' AND lease_until < ?))";
            $rows = $id === null
                ? Database::query(
                    "SELECT * FROM delivery_outbox WHERE {$due} ORDER BY available_at, id LIMIT 1 FOR UPDATE SKIP LOCKED",
                    [$now, $now],
                )
                : Database::query("SELECT * FROM delivery_outbox WHERE id = ? AND {$due} FOR UPDATE SKIP LOCKED", [$id, $now, $now]);
            if (!$rows) {
                return null;
            }
            $row = $rows[0];
            Database::execute(
                "UPDATE delivery_outbox SET status = 'sending', attempts = attempts + 1, lease_token = ?, lease_until = ? WHERE id = ?",
                [$lease, gmdate('Y-m-d H:i:s', time() + self::LEASE_SECONDS), $row['id']]
            );
            $row['attempts'] = (int) $row['attempts'] + 1;
            return $row;
        });
    }

    /**
     * Run one claimed row's handler and record the outcome.
     *
     * `sent` is true only when a message actually left: suppressed, skipped
     * and logged mail count as delivered but were not sent.
     *
     * @return array{state: 'delivered'|'queued'|'failed', sent: bool}
     */
    private static function process(array $row, string $lease, array $handlers, bool $retryAtOnce): array
    {
        $handlers += [
            'customer.access_recovery' => [CustomerCapability::class, 'deliverRecovery'],
            'booking.reminder' => [ReminderJob::class, 'deliver'],
            'booking.notification' => [\App\Application\Bookings\BookingNotifications::class, 'deliver'],
            'customer.privacy_notification' => [\App\Application\Privacy\PrivacyService::class, 'deliver'],
            'team.invitation' => [\App\Application\Business\TeamService::class, 'deliverInvitation'],
            'media.delete' => [\App\Application\Media\MediaService::class, 'deliverDeletion'],
            'webhook.deliver' => [\App\Application\Integrations\WebhookService::class, 'deliver'],
        ];
        try {
            if (!isset($handlers[$row['topic']])) {
                throw new \RuntimeException('unknown_delivery_topic');
            }
            $result = Mailer::duringDelivery(
                $row['id'],
                static fn () => $handlers[$row['topic']]((new PayloadCipher())->decrypt($row['payload_cipher']))
            );
            $deliberatelyHeld = is_array($result) && (
                !empty($result['demo_suppressed'])
                || !empty($result['logged'])
                || !empty($result['skipped'])
                || !empty($result['suppressed'])
            );
            // Suppressed, skipped or logged mail is a deliberate outcome, not a failure worth retrying.
            if (is_array($result) && isset($result['sent']) && !$result['sent'] && !$deliberatelyHeld) {
                throw new \RuntimeException('delivery_failed');
            }
            // Matching the lease token stops a worker whose lease expired from overwriting a newer claim.
            Database::execute(
                "UPDATE delivery_outbox SET status = 'delivered', delivered_at = ?, lease_until = NULL, lease_token = NULL, last_error_code = NULL WHERE id = ? AND lease_token = ?",
                [gmdate('Y-m-d H:i:s'), $row['id'], $lease]
            );

            return ['state' => 'delivered', 'sent' => is_array($result) && ($result['sent'] ?? false) === true && !$deliberatelyHeld];
        } catch (\Throwable) {
            // Provider error strings may contain credentials or personal data.
            $terminal = $row['attempts'] >= 8;
            // Exponential backoff from 30 seconds, capped at one day. An attempt made
            // right after commit stays due, so the next cron run tries again.
            $backoff = $retryAtOnce ? 0 : min(86400, 30 * 2 ** min(12, $row['attempts']));
            Database::execute(
                'UPDATE delivery_outbox SET status = ?, available_at = ?, lease_until = NULL, lease_token = NULL, last_error_code = ? WHERE id = ? AND lease_token = ?',
                [
                    $terminal ? 'failed' : 'pending',
                    gmdate('Y-m-d H:i:s', time() + $backoff),
                    'delivery_failed',
                    $row['id'],
                    $lease
                ]
            );

            return ['state' => $terminal ? 'failed' : 'queued', 'sent' => false];
        }
    }

    private static function state(string $id): string
    {
        $status = Database::query('SELECT status FROM delivery_outbox WHERE id = ?', [$id])[0]['status'] ?? null;

        return match ($status) {
            'delivered' => 'delivered',
            'failed' => 'failed',
            null => 'none',
            default => 'queued',
        };
    }

    public static function notificationState(string $receiptId): ?string
    {
        $rows = Database::query(
            "SELECT status FROM delivery_outbox WHERE operation_receipt_id = ? AND topic != 'media.delete'",
            [$receiptId]
        );
        if ($rows === []) {
            return null;
        }
        $states = array_column($rows, 'status');
        if (in_array('failed', $states, true)) {
            return 'failed';
        }
        if (array_diff($states, ['delivered']) === []) {
            return 'delivered';
        }
        return 'queued';
    }

    public static function cleanup(): void
    {
        Database::execute(
            "DELETE FROM delivery_outbox WHERE status = 'delivered' AND delivered_at < ?",
            [gmdate('Y-m-d H:i:s', time() - 30 * 86400)]
        );
        Database::execute('DELETE FROM agent_rate_budgets WHERE expires_at < ?', [gmdate('Y-m-d H:i:s', time() - 3600)]);
        Database::execute('DELETE FROM agent_previews WHERE created_at < ?', [gmdate('Y-m-d H:i:s', time() - 86400)]);
        // Keep the non-PII dedupe identity so expired results cannot create a duplicate.
        Database::execute(
            'UPDATE agent_operation_receipts SET result_cipher = NULL WHERE replay_until < ? AND result_cipher IS NOT NULL',
            [gmdate('Y-m-d H:i:s')]
        );
    }
}
