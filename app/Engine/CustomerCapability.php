<?php

declare(strict_types=1);

namespace App\Engine;

/** Independent, revocable customer authority. IDs are never credentials. */
final class CustomerCapability
{
    public const MANAGE = 'booking.manage';
    public const PRIVACY = 'customer.privacy';
    public const MANAGE_LIFETIME = 90 * 86400;
    public const PRIVACY_LIFETIME = 3600;

    public static function issueManage(string $tenantId, string $bookingId): string
    {
        return self::issue(self::MANAGE, $tenantId, $bookingId, self::MANAGE_LIFETIME);
    }

    public static function issuePrivacy(string $tenantId, string $customerId): string
    {
        return self::issue(self::PRIVACY, $tenantId, $customerId, self::PRIVACY_LIFETIME);
    }

    private static function issue(string $purpose, string $tenantId, string $subjectId, int $lifetime): string
    {
        $customerId = self::currentCustomer($purpose, $tenantId, $subjectId);
        if ($customerId === null) {
            throw new \InvalidArgumentException('Customer capability subject is unavailable.');
        }
        $token = bin2hex(random_bytes(32));
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        Database::execute(
            'INSERT INTO `customer_capabilities` (`id`, `token_hash`, `purpose`, `tenant_id`, `subject_id`, `customer_id`, `created_at`, `expires_at`)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                Ulid::generate(),
                hash('sha256', $token),
                $purpose,
                $tenantId,
                $subjectId,
                $customerId,
                $now->format('Y-m-d H:i:s'),
                $now->modify('+' . $lifetime . ' seconds')->format('Y-m-d H:i:s')
            ]
        );
        return $token;
    }

    /** The digest is returned only to trusted application code, never API output. */
    public static function verify(string $token, string $purpose, string $tenantId, string $subjectId): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $token)) {
            return null;
        }
        return self::verifyHash(hash('sha256', $token), $purpose, $tenantId, $subjectId);
    }

    private static function verifyHash(string $hash, string $purpose, string $tenantId, string $subjectId): ?array
    {
        $rows = Database::query(
            'SELECT * FROM `customer_capabilities` WHERE `token_hash` = ? AND `purpose` = ? AND `tenant_id` = ?
             AND `subject_id` = ? AND `revoked_at` IS NULL AND `expires_at` > ? LIMIT 1',
            [$hash, $purpose, $tenantId, $subjectId, gmdate('Y-m-d H:i:s')]
        );
        $grant = $rows[0] ?? null;
        if ($grant === null || self::currentCustomer($purpose, $tenantId, $subjectId) !== $grant['customer_id']) {
            return null;
        }
        return $grant;
    }

    private static function currentCustomer(string $purpose, string $tenantId, string $subjectId): ?string
    {
        if ($purpose === self::MANAGE) {
            $rows = Database::query(
                'SELECT c.`id` FROM `bookings` b JOIN `customers` c ON c.`id` = b.`customer_id` AND c.`tenant_id` = b.`tenant_id`
                 JOIN `tenants` t ON t.`id` = b.`tenant_id`
                 WHERE b.`id` = ? AND b.`tenant_id` = ? AND c.`is_anonymized` = 0 AND t.`status` = ? LIMIT 1',
                [$subjectId, $tenantId, 'active']
            );
        } elseif ($purpose === self::PRIVACY) {
            $rows = Database::query(
                'SELECT c.`id` FROM `customers` c JOIN `tenants` t ON t.`id` = c.`tenant_id`
                 WHERE c.`id` = ? AND c.`tenant_id` = ? AND c.`is_anonymized` = 0 AND t.`status` = ? LIMIT 1',
                [$subjectId, $tenantId, 'active']
            );
        } else {
            return null;
        }
        return $rows[0]['id'] ?? null;
    }

    public static function requestAuthorized(Request $request, string $purpose, string $tenantId, string $subjectId): bool
    {
        return self::requestGrant($request, $purpose, $tenantId, $subjectId) !== null;
    }

    private static function requestGrant(Request $request, string $purpose, string $tenantId, string $subjectId): ?array
    {
        $token = $request->header('X-Customer-Capability');
        if ($token !== null) return self::verify($token, $purpose, $tenantId, $subjectId);
        if (session_status() !== PHP_SESSION_ACTIVE) Auth::startSession();
        $hash = $_SESSION['customer_capabilities'][self::sessionKey($purpose, $tenantId, $subjectId)] ?? null;
        if (is_string($hash)) return self::verifyHash($hash, $purpose, $tenantId, $subjectId);
        // A native reschedule can move this exact grant while the browser is idle.
        // Its bounded session still holds proof, but under the predecessor's index.
        // Reindex only after verifying the stored hash against the current full scope.
        foreach (array_slice($_SESSION['customer_capabilities'] ?? [], -30, null, true) as $key => $candidate) {
            if (!is_string($candidate)) continue;
            $grant = self::verifyHash($candidate, $purpose, $tenantId, $subjectId);
            if ($grant === null) continue;
            unset($_SESSION['customer_capabilities'][$key]);
            $_SESSION['customer_capabilities'][self::sessionKey($purpose, $tenantId, $subjectId)] = $candidate;
            return $grant;
        }
        return null;
    }

    public static function actor(Request $request, string $purpose, string $tenantId, string $subjectId): ?\App\Agent\ActorContext
    {
        $grant = self::requestGrant($request, $purpose, $tenantId, $subjectId);
        return $grant === null ? null : self::actorForGrant($grant);
    }

    public static function refreshActor(\App\Agent\ActorContext $actor): \App\Agent\ActorContext
    {
        $grant = Database::query(
            'SELECT * FROM customer_capabilities WHERE id = ? AND revoked_at IS NULL AND expires_at > ?',
            [$actor->credentialId, gmdate('Y-m-d H:i:s')]
        )[0] ?? null;
        // The actor must still match the grant exactly: same customer, business and subject.
        if ($grant === null
            || $grant['customer_id'] !== $actor->principalId
            || !in_array($grant['tenant_id'], $actor->businessIds, true)
            || self::currentCustomer($grant['purpose'], $grant['tenant_id'], $grant['subject_id']) !== $grant['customer_id']
            || ($grant['purpose'] === self::MANAGE && $actor->bookingId !== $grant['subject_id'])
            || ($grant['purpose'] === self::PRIVACY && ($actor->bookingId !== null || $actor->customerId !== $grant['subject_id']))) {
            throw new \App\Agent\OperationException('unauthorized', 401, nextAction: 'verify_customer');
        }
        return self::actorForGrant($grant);
    }

    /** Internal connection resolver. Possession of this identifier is never proof. */
    public static function actorById(string $id): ?\App\Agent\ActorContext
    {
        $grant = Database::query(
            'SELECT * FROM customer_capabilities WHERE id = ? AND revoked_at IS NULL AND expires_at > ?',
            [$id, gmdate('Y-m-d H:i:s')]
        )[0] ?? null;
        if ($grant === null
            || self::currentCustomer($grant['purpose'], $grant['tenant_id'], $grant['subject_id']) !== $grant['customer_id'])
            return null;
        return self::actorForGrant($grant);
    }

    public static function verifiedContinuation(string $purpose, string $tenantId, string $subjectId): ?string
    {
        $return = $_SESSION['oauth_customer_return'] ?? null;
        // Resume OAuth only for the exact subject just verified and a pending request that is still alive.
        if (!is_array($return)
            || ($return['purpose'] ?? null) !== $purpose
            || ($return['tenant_id'] ?? null) !== $tenantId
            || ($return['subject_id'] ?? null) !== $subjectId
            || ($return['expires_at'] ?? 0) <= time()
            || !preg_match('/^[a-f0-9]{64}$/D', $return['handle'] ?? '')
            || ($_SESSION['oauth_pending'][$return['handle']]['expires_at'] ?? 0) <= time())
            return null;
        unset($_SESSION['oauth_customer_return']);
        return '/oauth/authorize?request=' . $return['handle'];
    }

    /** Move only the executing verified grant; unrelated historic links keep their original scope. */
    public static function followReplacement(\App\Agent\ActorContext $actor, string $originalId, string $replacementId): void
    {
        if ($actor->role !== 'verified_customer' || $actor->bookingId !== $originalId) return;
        // A delegated connection acts through the capability it was granted from.
        $id = $actor->authMode === 'customer' ? $actor->credentialId : (Database::query(
            "SELECT customer_capability_id FROM agent_connections WHERE id = ? AND principal_type = 'customer' AND principal_id = ?",
            [$actor->connectionId, $actor->principalId]
        )[0]['customer_capability_id'] ?? null);
        if ($id === null || self::currentCustomer(self::MANAGE, $actor->business(), $replacementId) !== $actor->customerId) {
            throw new \App\Agent\OperationException('unauthorized', 401);
        }
        Database::execute(
            'UPDATE customer_capabilities SET subject_id = ? WHERE id = ? AND purpose = ? AND tenant_id = ? AND subject_id = ? AND customer_id = ? AND revoked_at IS NULL',
            [$replacementId, $id, self::MANAGE, $actor->business(), $originalId, $actor->customerId]
        );
    }

    private static function actorForGrant(array $grant): \App\Agent\ActorContext
    {
        return new \App\Agent\ActorContext(
            'customer',
            $grant['customer_id'],
            'verified_customer',
            [$grant['tenant_id']],
            $grant['purpose'] === self::MANAGE
                ? ['bookings:read', 'bookings:cancel', 'bookings:reschedule', 'availability:read']
                : ['privacy:read', 'privacy:export', 'privacy:request'],
            credentialId: $grant['id'],
            authMode: 'customer',
            bookingId: $grant['purpose'] === self::MANAGE ? $grant['subject_id'] : null,
            customerId: $grant['customer_id']
        );
    }

    /** Exchange a secret for a bounded browser grant; every use still checks revocation. */
    public static function remember(string $token, string $purpose, string $tenantId, string $subjectId): bool
    {
        $grant = self::verify($token, $purpose, $tenantId, $subjectId);
        if ($grant === null) return false;
        if (session_status() !== PHP_SESSION_ACTIVE) Auth::startSession();
        $_SESSION['customer_capabilities'][self::sessionKey($purpose, $tenantId, $subjectId)] = $grant['token_hash'];
        // Bound session growth for shared devices that access many bookings.
        $_SESSION['customer_capabilities'] = array_slice($_SESSION['customer_capabilities'], -30, null, true);
        return true;
    }

    private static function sessionKey(string $purpose, string $tenantId, string $subjectId): string
    {
        return hash('sha256', $purpose . ':' . $tenantId . ':' . $subjectId);
    }

    public static function revokeForSubject(string $tenantId, string $purpose, string $subjectId): int
    {
        return Database::execute(
            'UPDATE `customer_capabilities` SET `revoked_at` = ? WHERE `tenant_id` = ? AND `purpose` = ? AND `subject_id` = ? AND `revoked_at` IS NULL',
            [gmdate('Y-m-d H:i:s'), $tenantId, $purpose, $subjectId]
        );
    }

    public static function link(string $slug, string $purpose, string $subjectId, string $token = ''): string
    {
        if (!in_array($purpose, [self::MANAGE, self::PRIVACY], true))
            throw new \InvalidArgumentException('Unsupported customer capability purpose.');
        $url = '/book/' . rawurlencode($slug) . '/' . ($purpose === self::MANAGE ? 'manage' : 'privacy') . '/' . rawurlencode($subjectId);
        return $token === '' ? $url : $url . '#access=' . rawurlencode($token);
    }

    public static function manageLink(array $tenant, string $bookingId): string
    {
        return self::link($tenant['slug'], self::MANAGE, $bookingId, self::issueManage($tenant['id'], $bookingId));
    }

    public static function protect(Response $response): Response
    {
        // Capability pages carry secrets in the URL fragment; keep them out of caches, referrers and indexes.
        return $response->header('Cache-Control', 'no-store, private')
            ->header('Referrer-Policy', 'no-referrer')
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }

    public static function deliverRecovery(array $payload): array
    {
        return CustomerCapabilityRecovery::deliver($payload);
    }

    public static function cleanup(): int
    {
        $now = gmdate('Y-m-d H:i:s');
        $count = Database::execute(
            'DELETE FROM `customer_capabilities` WHERE `expires_at` <= ? OR `revoked_at` IS NOT NULL',
            [$now]
        );
        return $count + Database::execute('DELETE FROM `customer_recovery_limits` WHERE `expires_at` <= ?', [$now]);
    }
}
