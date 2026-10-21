<?php

declare(strict_types=1);

namespace App\Agent\OAuth;

use App\Engine\Database;

final class Maintenance
{
    /** Keep rotated refresh records until expiry so reuse remains detectable. */
    public static function cleanup(): array
    {
        $counts = [];
        $now = gmdate('Y-m-d H:i:s');
        foreach (['oauth_auth_codes', 'oauth_access_tokens', 'oauth_refresh_tokens'] as $table) {
            $counts[$table] = Database::execute('DELETE FROM ' . $table . ' WHERE expires_at <= ?', [$now]);
        }
        // Registration churn must not permanently fill the bounded registry.
        // Retain every client that still has a live consented connection.
        $counts['oauth_clients'] = Database::execute(
            "DELETE FROM oauth_clients WHERE registration_type != 'preregistered' AND created_at < ?
             AND NOT EXISTS (SELECT 1 FROM agent_connections c WHERE c.client_id = oauth_clients.id AND c.revoked_at IS NULL AND c.expires_at > ?)",
            [gmdate('Y-m-d H:i:s', time() - 90 * 86400), $now],
        );
        return $counts;
    }
}
