<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Engine\Database;
use App\Engine\Request;
use App\Engine\Response;

/**
 * Cron endpoint controller.
 *
 * All endpoints require the cron_token query parameter.
 * Not listed in any navigation or sitemap.
 *
 * GET /cron/run?token={cron_token}          — unified cron entry point
 * GET /cron/retention?token={cron_token}    — backward-compatible alias
 */
final class CronController
{
    /**
     * Unified cron entry point.
     *
     * Runs all scheduled tasks: retention processing (and reminders, when implemented).
     * Authenticates via cron_token query parameter.
     */
    public function run(Request $request): Response
    {
        $token = $request->query('token');

        if ($token === '' || !$this->validateCronToken($token)) {
            return Response::json(['error' => 'Forbidden'], 403);
        }

        $result = \App\Application\System\JobCoordinator::dispatch(scheduled:true);
        return Response::json($result, $result['status']==='partial' ? 207 : 200);
    }

    /**
     * Validate the cron token against the stored value.
     */
    private function validateCronToken(string $token): bool
    {
        try {
            $rows = Database::query(
                "SELECT `value` FROM `settings` WHERE `key` = 'cron_token' LIMIT 1"
            );

            $storedToken = $rows[0]['value'] ?? '';

            return $storedToken !== '' && hash_equals($storedToken, $token);
        } catch (\Throwable) {
            return false;
        }
    }
}
