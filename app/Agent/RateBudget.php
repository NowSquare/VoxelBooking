<?php

declare(strict_types=1);

namespace App\Agent;

use App\Engine\Database;
use PDO;

/** Atomic fixed-window credential and principal/business budgets. */
final class RateBudget
{
    public function __construct(private ?PDO $db = null)
    {
    }

    /** @param array<string, int> $budgets Server-derived identifiers and limits. */
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
        $allowed = true;
        $remaining = PHP_INT_MAX;
        $limit = min($budgets);

        $db->beginTransaction();
        try {
            foreach ($budgets as $identity => $maximum) {
                $key = hash('sha256', $identity);
                $insert = $sqlite
                    ? 'INSERT INTO agent_rate_budgets (budget_key, window_start, attempts, expires_at) VALUES (?, ?, 1, ?) ON CONFLICT(budget_key, window_start) DO UPDATE SET attempts = attempts + 1'
                    : 'INSERT INTO agent_rate_budgets (budget_key, window_start, attempts, expires_at) VALUES (?, ?, 1, ?) ON DUPLICATE KEY UPDATE attempts = attempts + 1';
                $db->prepare($insert)->execute([$key, $start, gmdate('Y-m-d H:i:s', $reset)]);
                $query = $db->prepare('SELECT attempts FROM agent_rate_budgets WHERE budget_key = ? AND window_start = ?');
                $query->execute([$key, $start]);
                $count = (int) $query->fetchColumn();
                $allowed = $allowed && $count <= $maximum;
                $remaining = min($remaining, max(0, $maximum - $count));
            }
            $db->commit();
        } catch (\Throwable $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $error;
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
