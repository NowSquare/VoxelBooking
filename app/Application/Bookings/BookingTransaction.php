<?php

declare(strict_types=1);

namespace App\Application\Bookings;

use App\Agent\OperationException;
use App\Engine\Database;

/** One lock order for every booking write, including empty inventory. */
final class BookingTransaction
{
    public static function run(string $businessId, callable $command): mixed
    {
        $pdo = Database::pdo();
        $ownsTransaction = !$pdo->inTransaction();

        // Nested callers already hold the transaction; only the outermost run commits or rolls back.
        if ($ownsTransaction) $pdo->beginTransaction();

        try {
            $tenant = Database::query(
                "SELECT * FROM tenants WHERE id = ? AND status != 'archived' FOR UPDATE",
                [$businessId]
            )[0] ?? null;
            if ($tenant === null) throw new OperationException('not_found', 404);

            $result = $command($tenant);
            if ($ownsTransaction) $pdo->commit();

            return $result;
        } catch (\Throwable $error) {
            if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();

            throw $error;
        }
    }
}
