<?php

declare(strict_types=1);
namespace App\Engine;

use App\Agent\PayloadCipher;

/** Domain code enqueues inside its transaction; cron dispatches after commit. */
final class Outbox
{
    private static ?string $receiptId = null;
    public static function currentReceiptId(): ?string { return self::$receiptId; }

    public static function duringOperation(string $receiptId, callable $command): mixed
    {
        $previous = self::$receiptId;
        self::$receiptId = $receiptId;
        try { return $command(); }
        finally { self::$receiptId = $previous; }
    }

    public static function enqueue(string $topic, array $payload, ?string $tenantId, string $dedupeKey): string
    {
        if ($topic === '' || $dedupeKey === '') { throw new \InvalidArgumentException('Delivery identity required.'); }
        $id = Ulid::generate();
        $hash = hash('sha256', $topic . "\0" . $tenantId . "\0" . $dedupeKey);
        Database::execute('INSERT INTO delivery_outbox (id, topic, tenant_id, operation_receipt_id, dedupe_hash, payload_cipher, available_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE dedupe_hash = dedupe_hash', [$id, $topic, $tenantId, self::$receiptId, $hash, (new PayloadCipher())->encrypt($payload), gmdate('Y-m-d H:i:s'), gmdate('Y-m-d H:i:s')]);
        return Database::query('SELECT id FROM delivery_outbox WHERE dedupe_hash = ?', [$hash])[0]['id'];
    }

    /** Bounded work with leases: slow delivery does not hold domain row locks. */
    public static function dispatch(int $limit = 25, array $handlers = []): array
    {
        $limit = max(1, min(100, $limit));
        $counts = ['delivered' => 0, 'retrying' => 0, 'failed' => 0];
        $handlers += [
            'customer.access_recovery' => [CustomerCapability::class, 'deliverRecovery'],
            'booking.reminder' => [ReminderJob::class, 'deliver'],
            'booking.notification' => [\App\Application\Bookings\BookingNotifications::class, 'deliver'],
            'customer.privacy_notification' => [\App\Application\Privacy\PrivacyService::class, 'deliver'],
            'team.invitation' => [\App\Application\Business\TeamService::class, 'deliverInvitation'],
            'media.delete' => [\App\Application\Media\MediaService::class, 'deliverDeletion'],
            'webhook.deliver' => [\App\Application\Integrations\WebhookService::class, 'deliver'],
        ];
        for ($i = 0; $i < $limit; $i++) {
            $lease = bin2hex(random_bytes(32));
            $row = Database::transaction(static function () use ($lease): ?array {
                $now = gmdate('Y-m-d H:i:s');
                $rows = Database::query("SELECT * FROM delivery_outbox WHERE (status = 'pending' AND available_at <= ?) OR (status = 'sending' AND lease_until < ?) ORDER BY available_at, id LIMIT 1 FOR UPDATE SKIP LOCKED", [$now, $now]);
                if (!$rows) { return null; }
                $row = $rows[0];
                Database::execute("UPDATE delivery_outbox SET status = 'sending', attempts = attempts + 1, lease_token = ?, lease_until = ? WHERE id = ?", [$lease, gmdate('Y-m-d H:i:s', time() + 120), $row['id']]);
                $row['attempts'] = (int) $row['attempts'] + 1;
                return $row;
            });
            if ($row === null) { break; }
            try {
                if (!isset($handlers[$row['topic']])) { throw new \RuntimeException('unknown_delivery_topic'); }
                $result = Mailer::duringDelivery($row['id'], static fn() => $handlers[$row['topic']]((new PayloadCipher())->decrypt($row['payload_cipher'])));
                if (is_array($result) && isset($result['sent']) && !$result['sent'] && empty($result['demo_suppressed']) && empty($result['logged']) && empty($result['skipped']) && empty($result['suppressed'])) {
                    throw new \RuntimeException('delivery_failed');
                }
                Database::execute("UPDATE delivery_outbox SET status = 'delivered', delivered_at = ?, lease_until = NULL, lease_token = NULL, last_error_code = NULL WHERE id = ? AND lease_token = ?", [gmdate('Y-m-d H:i:s'), $row['id'], $lease]);
                $counts['delivered']++;
            } catch (\Throwable) {
                // Provider error strings may contain credentials or personal data.
                $terminal = $row['attempts'] >= 8;
                Database::execute('UPDATE delivery_outbox SET status = ?, available_at = ?, lease_until = NULL, lease_token = NULL, last_error_code = ? WHERE id = ? AND lease_token = ?', [$terminal ? 'failed' : 'pending', gmdate('Y-m-d H:i:s', time() + min(86400, 30 * 2 ** min(12, $row['attempts']))), 'delivery_failed', $row['id'], $lease]);
                $counts[$terminal ? 'failed' : 'retrying']++;
            }
        }
        return $counts;
    }

    public static function notificationState(string $receiptId): ?string
    {
        $rows = Database::query("SELECT status FROM delivery_outbox WHERE operation_receipt_id = ? AND topic != 'media.delete'",[$receiptId]);
        if ($rows === []) { return null; }
        $states = array_column($rows,'status');
        if (in_array('failed',$states,true)) { return 'failed'; }
        if (array_diff($states,['delivered']) === []) { return 'delivered'; }
        return 'queued';
    }

    public static function cleanup(): void
    {
        Database::execute("DELETE FROM delivery_outbox WHERE status = 'delivered' AND delivered_at < ?", [gmdate('Y-m-d H:i:s', time() - 30 * 86400)]);
        Database::execute('DELETE FROM agent_rate_budgets WHERE expires_at < ?', [gmdate('Y-m-d H:i:s', time() - 3600)]);
        Database::execute('DELETE FROM agent_previews WHERE created_at < ?', [gmdate('Y-m-d H:i:s', time() - 86400)]);
        // Keep the non-PII dedupe identity so expired results cannot create a duplicate.
        Database::execute('UPDATE agent_operation_receipts SET result_cipher = NULL WHERE replay_until < ? AND result_cipher IS NOT NULL', [gmdate('Y-m-d H:i:s')]);
    }
}
