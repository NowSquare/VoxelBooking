<?php

declare(strict_types=1);

namespace App\Engine;

/**
 * Agent API authentication engine.
 *
 * Per PRD §XII and .ai/22-VoxelBooking-Agent-API-Checklist.md:
 * - Bearer token auth via SHA-256 hash lookup
 * - Scope enforcement per key role (agent vs viewer)
 * - Key generation with one-time raw display
 * - Keys are never stored in plaintext — only SHA-256 hash + 8-char prefix
 */
final class AgentAuth
{
    /**
     * All valid scopes in the system.
     */
    public const ALL_SCOPES = [
        'tenants:read',
        'tenants:write',
        'bookings:read',
        'bookings:write',
        'services:read',
        'services:write',
        'availability:read',
        'customers:read',
        'customers:write',
        'settings:read',
        'reports:read',
    ];

    /**
     * Default scopes per role.
     *
     * Agent: full read + write (except settings:write which never auto-grants)
     * Viewer: read-only across all resources
     */
    public const ROLE_DEFAULTS = [
        'agent' => [
            'tenants:read', 'tenants:write',
            'bookings:read', 'bookings:write',
            'services:read', 'services:write',
            'availability:read',
            'customers:read', 'customers:write',
            'settings:read',
            'reports:read',
        ],
        'viewer' => [
            'tenants:read',
            'bookings:read',
            'services:read',
            'availability:read',
            'customers:read',
            'settings:read',
            'reports:read',
        ],
    ];

    /**
     * Validate a bearer token and return the API key record.
     *
     * @param string $bearerToken Raw bearer token from Authorization header
     * @return array|null API key record if valid, null if invalid/expired/inactive
     */
    public static function validate(string $bearerToken): ?array
    {
        if (empty($bearerToken)) {
            return null;
        }

        $hash = hash('sha256', $bearerToken);

        try {
            $keys = Database::query(
                'SELECT * FROM `api_keys` WHERE `key_hash` = ? AND `is_active` = 1 LIMIT 1',
                [$hash]
            );

            if (empty($keys)) {
                return null;
            }

            $key = $keys[0];

            // Check expiry
            if (!empty($key['expires_at']) && strtotime($key['expires_at']) < time()) {
                return null;
            }

            // Update last_used_at
            try {
                Database::execute(
                    'UPDATE `api_keys` SET `last_used_at` = NOW() WHERE `id` = ?',
                    [$key['id']]
                );
            } catch (\Throwable) {
                // Non-fatal
            }

            // Parse scopes from JSON
            $storedScopes = json_decode($key['scopes'] ?? '[]', true);
            $key['scopes_array'] = array_values(array_intersect(
                is_array($storedScopes) ? $storedScopes : [],
                self::ROLE_DEFAULTS['viewer'],
            ));

            return $key;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Check if an API key has a specific scope.
     *
     * @param array  $key   API key record (from validate())
     * @param string $scope Scope string (e.g., 'bookings:read')
     */
    public static function hasScope(array $key, string $scope): bool
    {
        // v1 write names were inert at issue time. An upgrade cannot grant new authority.
        return in_array($scope, self::ROLE_DEFAULTS['viewer'], true)
            && in_array($scope, $key['scopes_array'] ?? [], true);
    }

    /**
     * Generate a new API key.
     *
     * Returns the raw key (show once to operator) and the key record for storage.
     *
     * @return array{raw_key: string, id: string, key_hash: string, key_prefix: string, scopes: string[]}
     */
    public static function generateKey(string $name, string $role = 'viewer', ?string $createdBy = null): array
    {
        $rawKey = 'vb_' . bin2hex(random_bytes(32)); // 67 chars: vb_ + 64 hex
        $hash = hash('sha256', $rawKey);
        $prefix = substr($rawKey, 0, 8);
        $id = Ulid::generate();
        $scopes = self::ROLE_DEFAULTS['viewer'];

        Database::execute(
            'INSERT INTO `api_keys`
                (`id`, `name`, `key_hash`, `key_prefix`, `scopes`, `role`, `is_active`, `created_by`)
             VALUES (?, ?, ?, ?, ?, ?, 1, ?)',
            [$id, $name, $hash, $prefix, json_encode($scopes), $role, $createdBy]
        );

        AuditLog::log('api_key.created', 'api_key', $id, [
            'name'    => $name,
            'role'    => $role,
            'scopes'  => $scopes,
        ]);

        return [
            'raw_key'    => $rawKey,
            'id'         => $id,
            'key_hash'   => $hash,
            'key_prefix' => $prefix,
            'scopes'     => $scopes,
        ];
    }

    /**
     * Revoke (deactivate) an API key.
     */
    public static function revokeKey(string $keyId): bool
    {
        $affected = Database::execute(
            'UPDATE `api_keys` SET `is_active` = 0, `updated_at` = NOW() WHERE `id` = ?',
            [$keyId]
        );

        if ($affected > 0) {
            AuditLog::log('api_key.revoked', 'api_key', $keyId);
        }

        return $affected > 0;
    }

    /**
     * List all API keys (for admin UI). Excludes the hash.
     *
     * @return array<int, array>
     */
    public static function listKeys(): array
    {
        return Database::query(
            'SELECT `id`, `name`, `key_prefix`, `scopes`, `role`, `is_active`, `last_used_at`, `expires_at`, `created_at`
             FROM `api_keys`
             ORDER BY `created_at` DESC'
        );
    }

    /**
     * Extract bearer token from Authorization header.
     */
    public static function extractBearerToken(string $authHeader): string
    {
        if (preg_match('/\ABearer +([A-Za-z0-9._~+\/=-]+)\z/iD', $authHeader, $matches) === 1) {
            return $matches[1];
        }

        return '';
    }
}
