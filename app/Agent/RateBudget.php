<?php

declare(strict_types=1);

namespace App\Agent;

use App\Engine\Database;
use PDO;

/**
 * Atomic fixed-window budgets that stack: a request is admitted only when every
 * named budget still has room, and only an admitted request spends them.
 *
 * Denied attempts deliberately spend nothing. Otherwise an actor already blocked
 * by its own narrow budget (one address, one credential) could keep knocking and
 * drain a wide budget it shares with everyone else (a business ceiling).
 */
final class RateBudget
{
    public function __construct(private ?PDO $db = null)
    {
    }

    /**
     * @param array<string, int> $budgets Server-derived identifiers and limits.
     * @return array{allowed: bool, limit: int, remaining: int, reset: int, retry_after: int}
     *         limit and remaining describe the budget closest to running out.
     */
    public function consume(array $budgets, ?int $now = null, int $window = 60): array
    {
        if ($budgets === [] || $window < 1 || min($budgets) < 1) {
            throw new \InvalidArgumentException('A positive rate budget is required.');
        }
        $db = $this->db ?? Database::pdo();
        if ($db->inTransaction()) {
            throw new \LogicException('Rate admission must precede the domain transaction.');
        }
        $now ??= time();
        $start = intdiv($now, $window) * $window;
        $reset = $start + $window;
        $sqlite = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
        // A stable key order keeps row locks in the same sequence across concurrent requests.
        ksort($budgets);

        // Touching the row with a no-op update takes its exclusive lock on MySQL, so the
        // read below and the conditional spend cannot interleave with another request.
        $claim = $sqlite
            ? 'INSERT INTO agent_rate_budgets (budget_key, window_start, attempts, expires_at) VALUES (?, ?, 0, ?) ON CONFLICT(budget_key, window_start) DO NOTHING'
            : 'INSERT INTO agent_rate_budgets (budget_key, window_start, attempts, expires_at) VALUES (?, ?, 0, ?) ON DUPLICATE KEY UPDATE attempts = attempts';
        $read = $sqlite
            ? 'SELECT attempts FROM agent_rate_budgets WHERE budget_key = ? AND window_start = ?'
            : 'SELECT attempts FROM agent_rate_budgets WHERE budget_key = ? AND window_start = ? FOR UPDATE';
        $spend = 'UPDATE agent_rate_budgets SET attempts = attempts + 1 WHERE budget_key = ? AND window_start = ?';

        $db->beginTransaction();
        try {
            $spent = [];
            foreach ($budgets as $identity => $maximum) {
                $key = hash('sha256', (string) $identity);
                $db->prepare($claim)->execute([$key, $start, gmdate('Y-m-d H:i:s', $reset)]);
                $query = $db->prepare($read);
                $query->execute([$key, $start]);
                $spent[$key] = [(int) $query->fetchColumn(), $maximum];
            }
            $allowed = true;
            foreach ($spent as [$count, $maximum]) {
                $allowed = $allowed && $count < $maximum;
            }
            if ($allowed) {
                foreach (array_keys($spent) as $key) {
                    $db->prepare($spend)->execute([$key, $start]);
                }
            }
            $db->commit();
        } catch (\Throwable $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $error;
        }

        // Report the budget that binds, so X-RateLimit-* names the limit an agent actually hit.
        $limit = PHP_INT_MAX;
        $remaining = PHP_INT_MAX;
        foreach ($spent as [$count, $maximum]) {
            $left = max(0, $maximum - $count - ($allowed ? 1 : 0));
            if ($left < $remaining || ($left === $remaining && $maximum < $limit)) {
                $remaining = $left;
                $limit = $maximum;
            }
        }

        return [
            'allowed' => $allowed,
            'limit' => $limit,
            'remaining' => $remaining,
            'reset' => $reset,
            'retry_after' => max(1, $reset - $now)
        ];
    }
}
