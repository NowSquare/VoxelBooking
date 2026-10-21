<?php

declare(strict_types=1);

namespace App\Engine;

/**
 * Structured audit logging engine.
 *
 * Per PRD §XIX (Compliance, Privacy & Audit Architecture) and
 * .ai/23-VoxelBooking-Legal-Logging.md §5 (Audit Logging Gate).
 *
 * Every meaningful mutation produces a structured log entry with:
 * - Actor identification (who did it)
 * - Entity identification (what was affected)
 * - Action key (machine-readable event type)
 * - Redacted details (PII-safe before/after context)
 * - Request correlation (request_id for tracing)
 *
 * Redaction rules (§5):
 * - Passwords, raw tokens, SMTP credentials: NEVER logged (→ [REDACTED])
 * - Email addresses: centrally hashed to SHA-256 prefix (first 8 hex chars)
 *   Any detail key containing 'email' is transformed automatically.
 *   Callers do NOT need to hash emails — the engine enforces this.
 * - Customer PII: log action, not personal data
 * - Sensitive settings: "[REDACTED]" placeholder
 */
final class AuditLog
{
    /** Current request correlation ID — set by SecurityMiddleware */
    private static string $requestId = '';

    /** Keys whose values must be redacted in details JSON */
    private const REDACTED_KEYS = [
        'password', 'password_hash', 'password_confirm', 'current_password', 'new_password',
        'smtp_password', 'smtp_pass', 'cron_secret', 'cron_token',
        'api_key', 'bearer_token', 'token', 'secret',
        'session_id', 'csrf_token', '_csrf_token',
    ];

    /**
     * Keys whose values contain email addresses and must be hashed.
     * These are transformed to SHA-256 prefixes, not blanked — allowing
     * correlation without exposing the raw email.
     */
    private const EMAIL_KEYS = [
        'email', 'customer_email', 'operator_email', 'to_email', 'from_email',
        'notification_email', 'mail_from_address', 'user_email',
    ];

    /**
     * Set the request correlation ID for this request lifecycle.
     * Called by SecurityMiddleware at the start of every request.
     */
    public static function setRequestId(string $requestId): void
    {
        self::$requestId = $requestId;
    }

    /**
     * Get the current request ID.
     */
    public static function getRequestId(): string
    {
        return self::$requestId;
    }

    /**
     * Log an audit event.
     *
     * @param string      $action     Machine-readable action key (e.g., 'auth.login', 'settings.updated')
     * @param string      $entityType What was acted on ('operator', 'tenant', 'booking', 'settings')
     * @param string|null $entityId   ULID of the affected entity (NULL for bulk/system operations)
     * @param array       $details    Context data (will be redacted automatically)
     * @param string|null $tenantId   Tenant scope (NULL for system-level events)
     * @param string|null $actorType  Override actor type (auto-detected from session if NULL)
     * @param string|null $actorId    Override actor ID (auto-detected from session if NULL)
     * @param string|null $ipAddress  Override IP (auto-detected from $_SERVER if NULL)
     */
    public static function log(
        string $action,
        string $entityType,
        ?string $entityId = null,
        array $details = [],
        ?string $tenantId = null,
        ?string $actorType = null,
        ?string $actorId = null,
        ?string $ipAddress = null,
    ): void {
        // Auto-detect actor from session
        if ($actorType === null) {
            $user = self::resolveActor();
            $actorType = $user['type'];
            $actorId = $user['id'];
        }

        // Auto-detect tenant from session if not provided
        // Check impersonation context first, then business user session
        if ($tenantId === null) {
            $tenantId = $_SESSION['impersonation_tenant_id']
                ?? $_SESSION['auth_tenant_id']
                ?? null;
        }

        // Auto-detect IP
        if ($ipAddress === null) {
            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
        }

        // Redact sensitive values in details
        $safeDetails = self::redact($details);

        $id = Ulid::generate();

        try {
            Database::execute(
                'INSERT INTO `audit_log`
                    (`id`, `tenant_id`, `actor_type`, `actor_id`, `action`,
                     `entity_type`, `entity_id`, `details`, `ip_address`, `request_id`, `created_at`)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())',
                [
                    $id,
                    $tenantId,
                    $actorType,
                    $actorId,
                    $action,
                    $entityType,
                    $entityId,
                    !empty($safeDetails) ? json_encode($safeDetails, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
                    $ipAddress,
                    self::$requestId ?: self::generateFallbackRequestId(),
                ]
            );
        } catch (\Throwable $e) {
            // Domain mutations and their evidence commit together in every transport.
            // Authentication and other non-transactional logging retain the safe fallback.
            if (Database::pdo()->inTransaction()) {
                throw new \App\Agent\OperationException('audit_unavailable',503,nextAction:'retry_later',retryable:true);
            }
            Logger::warning('Audit log write failed', [
                'action'      => $action,
                'entity_type' => $entityType,
                'entity_id'   => $entityId,
                'actor_type'  => $actorType,
            ]);
        }
    }

    /**
     * Convenience: log a successful authentication.
     */
    public static function logLogin(string $actorType, string $actorId, string $emailHash): void
    {
        self::log(
            'auth.login',
            $actorType,
            $actorId,
            ['email_prefix' => $emailHash],
            actorType: $actorType,
            actorId: $actorId,
        );
    }

    /**
     * Convenience: log a failed authentication attempt.
     */
    public static function logLoginFailed(string $email, string $reason = 'Invalid credentials'): void
    {
        self::log(
            'auth.login_failed',
            'auth',
            null,
            ['email_prefix' => self::hashEmail($email), 'reason' => $reason],
            actorType: 'system',
            actorId: null,
        );
    }

    /**
     * Convenience: log a logout.
     */
    public static function logLogout(): void
    {
        self::log('auth.logout', 'auth');
    }

    /**
     * Convenience: log a settings change.
     *
     * @param array<string, array{old: mixed, new: mixed}> $changes Key => [old, new] pairs
     */
    public static function logSettingsChanged(array $changes, ?string $tenantId = null): void
    {
        // Redact sensitive setting values
        $safeChanges = [];
        foreach ($changes as $key => $diff) {
            if (self::isSensitiveKey($key)) {
                $safeChanges[$key] = ['old' => '[REDACTED]', 'new' => '[REDACTED]'];
            } else {
                $safeChanges[$key] = $diff;
            }
        }

        self::log('settings.updated', 'settings', null, ['changes' => $safeChanges], $tenantId);
    }

    /**
     * Hash an email address for audit log storage.
     * Returns the first 8 hex characters of SHA-256 — enough for correlation,
     * not enough to reverse.
     */
    public static function hashEmail(string $email): string
    {
        return substr(hash('sha256', strtolower(trim($email))), 0, 8);
    }

    /**
     * Redact sensitive values from a details array (recursive).
     */
    public static function redact(array $data): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && self::isSensitiveKey($key)) {
                $result[$key] = '[REDACTED]';
            } elseif (is_string($key) && self::isEmailKey($key)) {
                // Email-like key: hash the value whether it's a flat string
                // or a nested structure (e.g. ['old' => 'a@b.com', 'new' => 'c@d.com'])
                if (is_string($value)) {
                    $result[$key] = self::hashEmail($value);
                } elseif (is_array($value)) {
                    $result[$key] = self::hashEmailValues($value);
                } else {
                    $result[$key] = $value;
                }
            } elseif (is_array($value)) {
                $result[$key] = self::redact($value);
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * Hash all string values in an array (recursive).
     *
     * Used when an email-like key contains a sub-array (e.g. old/new diffs).
     * Every string leaf is treated as an email address and hashed.
     */
    private static function hashEmailValues(array $data): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $result[$key] = self::hashEmail($value);
            } elseif (is_array($value)) {
                $result[$key] = self::hashEmailValues($value);
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * Query recent audit log entries (for admin viewer).
     *
     * @param int         $limit    Max entries to return
     * @param int         $offset   Pagination offset
     * @param string|null $tenantId Filter by tenant (NULL = all/system)
     * @param string|null $action   Filter by action key
     *
     * @return array{entries: array, total: int}
     */
    public static function query(
        int $limit = 50,
        int $offset = 0,
        ?string $tenantId = null,
        ?string $action = null,
    ): array {
        $where = [];
        $bindings = [];

        if ($tenantId !== null) {
            $where[] = '`tenant_id` = ?';
            $bindings[] = $tenantId;
        }

        if ($action !== null) {
            $where[] = '`action` = ?';
            $bindings[] = $action;
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $total = Database::query(
            "SELECT COUNT(*) as cnt FROM `audit_log` {$whereClause}",
            $bindings
        );

        $entries = Database::query(
            "SELECT * FROM `audit_log` {$whereClause} ORDER BY `created_at` DESC LIMIT ? OFFSET ?",
            array_merge($bindings, [$limit, $offset])
        );

        return [
            'entries' => $entries,
            'total'   => (int) ($total[0]['cnt'] ?? 0),
        ];
    }

    /**
     * Query audit log entries for CSV export (no pagination, capped at 10 000).
     *
     * @return list<array<string, mixed>>
     */
    public static function queryForExport(?string $action = null): array
    {
        $where = [];
        $bindings = [];

        if ($action !== null) {
            $where[] = '`action` = ?';
            $bindings[] = $action;
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        return Database::query(
            "SELECT `action`, `entity_type`, `entity_id`, `actor_type`, `actor_id`,
                    `ip_address`, `created_at`
             FROM `audit_log` {$whereClause}
             ORDER BY `created_at` DESC
             LIMIT 10000",
            $bindings
        );
    }

    /**
     * Get audit log entries for a specific entity.
     *
     * Used by booking detail to render an event timeline.
     * Returns only display-safe columns (no IP, no request_id).
     *
     * @return list<array<string, mixed>>
     */
    public static function forEntity(string $entityType, string $entityId, int $limit = 20): array
    {
        return Database::query(
            'SELECT `action`, `actor_type`, `details`, `created_at`
             FROM `audit_log`
             WHERE `entity_type` = ? AND `entity_id` = ?
             ORDER BY `created_at` DESC
             LIMIT ?',
            [$entityType, $entityId, $limit]
        );
    }

    /**
     * Delete audit log entries older than the given number of days.
     * The cleanup itself is logged.
     *
     * @return int Number of entries deleted
     */
    public static function cleanup(int $retentionDays = 365): int
    {
        $count = Database::execute(
            'DELETE FROM `audit_log` WHERE `created_at` < DATE_SUB(NOW(), INTERVAL ? DAY)',
            [$retentionDays]
        );

        if ($count > 0) {
            self::log(
                'system.audit_cleanup',
                'audit_log',
                null,
                ['deleted_count' => $count, 'retention_days' => $retentionDays],
                actorType: 'system',
                actorId: null,
            );
        }

        return $count;
    }

    /**
     * Resolve the current actor from PHP session.
     *
     * @return array{type: string, id: string|null}
     */
    private static function resolveActor(): array
    {
        $type = $_SESSION['auth_type'] ?? null;
        $id = $_SESSION['auth_id'] ?? null;

        if ($type !== null && $id !== null) {
            return ['type' => $type, 'id' => $id];
        }

        return ['type' => 'system', 'id' => null];
    }

    /**
     * Check if a key name corresponds to sensitive data.
     */
    private static function isSensitiveKey(string $key): bool
    {
        $lower = strtolower($key);

        foreach (self::REDACTED_KEYS as $sensitive) {
            if ($lower === $sensitive || str_contains($lower, $sensitive)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a key name corresponds to an email address field.
     * Matches exact keys and keys containing 'email' as a substring.
     */
    private static function isEmailKey(string $key): bool
    {
        $lower = strtolower($key);

        // Check exact matches first
        foreach (self::EMAIL_KEYS as $emailKey) {
            if ($lower === $emailKey) {
                return true;
            }
        }

        // Catch-all: any key containing 'email' is treated as an email field
        return str_contains($lower, 'email');
    }

    /**
     * Generate a fallback request ID when SecurityMiddleware hasn't set one.
     * This can happen during installation or CLI contexts.
     */
    private static function generateFallbackRequestId(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }

    /**
     * Reset state (for testing).
     */
    public static function reset(): void
    {
        self::$requestId = '';
    }
}
