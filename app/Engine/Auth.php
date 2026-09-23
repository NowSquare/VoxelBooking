<?php

declare(strict_types=1);

namespace App\Engine;

/**
 * Unified session-based authentication for operators and business users.
 *
 * Per PRD §XV Security:
 * - login() checks operators table first, then business_users (with is_active = 1)
 * - Sessions use secure, httponly, samesite=lax cookies (path=/)
 * - Session ID regenerated on login to prevent fixation
 * - Sessions expire after 8 hours of inactivity (server-side check)
 *
 * Session keys:
 * - auth_type:      'operator' | 'business_user'
 * - auth_id:        ULID of the authenticated entity
 * - auth_name:      Display name
 * - auth_email:     Email address
 * - auth_tenant_id: (business users only) Tenant ULID
 * - auth_role:      (business users only) 'owner' | 'manager'
 * - _last_activity: Unix timestamp of last verified request
 *
 * Impersonation keys (operator-only, layered on top):
 * - impersonation_active:      bool — true while impersonating
 * - impersonation_tenant_id:   ULID of the tenant being impersonated
 * - impersonation_tenant_name: Display name of the tenant
 */
final class Auth
{
    /** Session inactivity timeout: 8 hours (PRD §XV line 2278) */
    private const SESSION_TIMEOUT = 8 * 3600;

    /**
     * Configure and start a secure session for admin routes.
     *
     * Called once per request from the bootstrap. Sets cookie params
     * per PRD §XV line 2282-2289.
     */
    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $secure = ($_ENV['FORCE_HTTPS'] ?? 'true') === 'true';

        // Store sessions in a known, writable location
        $sessionPath = dirname(__DIR__, 2) . '/storage/sessions';
        if (!is_dir($sessionPath)) {
            mkdir($sessionPath, 0700, true);
        }
        session_save_path($sessionPath);

        session_set_cookie_params([
            'lifetime' => 2592000, // 30 days — persist across browser restarts
            'path'     => '/',
            'domain'   => '',
            'secure'   => $secure,
            'httponly'  => true,
            'samesite'  => 'Lax',
        ]);

        // Match GC lifetime to cookie lifetime so sessions survive
        ini_set('session.gc_maxlifetime', '2592000');

        session_name('vb_session');

        // Cookie collision guard: when a browser sends multiple vb_session cookies
        // (e.g. headless browsers accumulating cookies across tabs/navigation),
        // PHP picks one non-deterministically and may resume a stale session.
        // Fix: parse raw Cookie header, validate each candidate against session
        // storage, and force-set the last valid one before session_start().
        self::resolveSessionCookie($sessionPath);

        session_start();
    }

    /**
     * Resolve the correct session cookie when the browser sends duplicates.
     *
     * Parses the raw Cookie header for all vb_session values, validates
     * each against the session storage directory, and sets session_id()
     * to the most recently valid one. If none are valid, leaves session_id
     * empty so PHP creates a new session.
     */
    private static function resolveSessionCookie(string $sessionPath): void
    {
        $rawCookies = $_SERVER['HTTP_COOKIE'] ?? '';
        if ($rawCookies === '') {
            return;
        }

        // Parse all vb_session values from the raw Cookie header
        $sessionIds = [];
        foreach (explode(';', $rawCookies) as $part) {
            $part = trim($part);
            if (str_starts_with($part, 'vb_session=')) {
                $value = substr($part, strlen('vb_session='));
                if ($value !== '' && preg_match('/^[a-zA-Z0-9,-]{1,128}$/', $value)) {
                    $sessionIds[] = $value;
                }
            }
        }

        // No duplicates → PHP handles it fine
        if (count($sessionIds) <= 1) {
            return;
        }

        // Multiple cookies found — find the last one with a valid session file
        $validId = '';
        foreach (array_reverse($sessionIds) as $id) {
            $file = $sessionPath . '/sess_' . $id;
            if (is_file($file) && filesize($file) > 0) {
                $validId = $id;
                break;
            }
        }

        // Force PHP to use the valid session (or start fresh if none valid)
        if ($validId !== '') {
            session_id($validId);
        }
        // If no valid session file, don't set session_id — PHP will create a new one
    }

    /**
     * Attempt to log in with email and password.
     *
     * Checks the operators table first, then business_users.
     * On success: stores session data and regenerates session ID.
     *
     * @return array{success: bool, error?: string}
     */
    public static function login(string $email, string $password): array
    {
        if (empty($email) || empty($password)) {
            return ['success' => false, 'error' => __('auth.credentials_required')];
        }

        // Check 1: operators table (PRD §XV: operator match takes priority)
        try {
            $operator = Database::query(
                'SELECT `id`, `name`, `email`, `password_hash` FROM `operators` WHERE `email` = ? LIMIT 1',
                [$email]
            );

            if (!empty($operator) && password_verify($password, $operator[0]['password_hash'])) {
                self::regenerateSession();
                self::setSession('operator', $operator[0]['id'], $operator[0]['name'], $operator[0]['email']);

                // Update last_login_at if column exists
                try {
                    Database::execute(
                        'UPDATE `operators` SET `updated_at` = NOW() WHERE `id` = ?',
                        [$operator[0]['id']]
                    );
                } catch (\Throwable) {
                    // Column may not exist yet — non-fatal
                }

                AuditLog::logLogin('operator', $operator[0]['id'], AuditLog::hashEmail($email));

                return ['success' => true];
            }
        } catch (\Throwable) {
            // DB error — fall through to business_users check
        }

        // Check 2: business_users table (PRD §XV: with is_active = 1)
        try {
            $businessUser = Database::query(
                'SELECT `id`, `name`, `email`, `password_hash`, `tenant_id`, `role`, `force_password_change`
                 FROM `business_users`
                 WHERE `email` = ? AND `is_active` = 1
                 LIMIT 1',
                [$email]
            );

            if (!empty($businessUser) && password_verify($password, $businessUser[0]['password_hash'])) {
                self::regenerateSession();
                self::setSession(
                    'business_user',
                    $businessUser[0]['id'],
                    $businessUser[0]['name'],
                    $businessUser[0]['email'],
                    $businessUser[0]['tenant_id'],
                    $businessUser[0]['role']
                );

                // Store force_password_change flag
                if (!empty($businessUser[0]['force_password_change'])) {
                    $_SESSION['force_password_change'] = true;
                }

                // Update last_login_at
                try {
                    Database::execute(
                        'UPDATE `business_users` SET `last_login_at` = NOW() WHERE `id` = ?',
                        [$businessUser[0]['id']]
                    );
                } catch (\Throwable) {
                    // Non-fatal
                }

                AuditLog::logLogin('business_user', $businessUser[0]['id'], AuditLog::hashEmail($email));

                return ['success' => true];
            }
        } catch (\Throwable) {
            // DB error — return generic failure
        }

        AuditLog::logLoginFailed($email);

        return ['success' => false, 'error' => __('auth.invalid_credentials')];
    }

    /**
     * Log in by email alone (passwordless — OTP / magic link).
     *
     * Resolves the user type via the auth_emails registry, then creates
     * the session exactly as login() does for password auth.
     *
     * @return array{success: bool, error?: string}
     */
    public static function loginByEmail(string $email): array
    {
        $reg = Database::query(
            'SELECT `user_type`, `user_id` FROM `auth_emails` WHERE `email` = ? LIMIT 1',
            [$email]
        );

        if (empty($reg)) {
            return ['success' => false, 'error' => __('auth.account_not_found_for_email')];
        }

        $userType = $reg[0]['user_type'];
        $userId   = $reg[0]['user_id'];

        if ($userType === 'operator') {
            $operator = Database::query(
                'SELECT `id`, `name`, `email` FROM `operators` WHERE `id` = ? LIMIT 1',
                [$userId]
            );
            if (empty($operator)) {
                return ['success' => false, 'error' => __('auth.account_not_found')];
            }
            self::regenerateSession();
            self::setSession('operator', $operator[0]['id'], $operator[0]['name'], $operator[0]['email']);
            AuditLog::logLogin('operator', $operator[0]['id'], AuditLog::hashEmail($email));
            return ['success' => true];
        }

        // business_user
        $bu = Database::query(
            "SELECT `id`, `name`, `email`, `tenant_id`, `role`, `force_password_change`
             FROM `business_users` WHERE `id` = ? AND `is_active` = 1 LIMIT 1",
            [$userId]
        );
        if (empty($bu)) {
            return ['success' => false, 'error' => __('auth.account_not_found_or_inactive')];
        }
        self::regenerateSession();
        self::setSession('business_user', $bu[0]['id'], $bu[0]['name'], $bu[0]['email'], $bu[0]['tenant_id'], $bu[0]['role']);
        if (!empty($bu[0]['force_password_change'])) {
            $_SESSION['force_password_change'] = true;
        }
        try {
            Database::execute('UPDATE `business_users` SET `last_login_at` = NOW() WHERE `id` = ?', [$bu[0]['id']]);
        } catch (\Throwable) {
            // Non-fatal
        }
        AuditLog::logLogin('business_user', $bu[0]['id'], AuditLog::hashEmail($email));
        return ['success' => true];
    }

    /**
     * Check if the current session is authenticated and not expired.
     *
     * Updates _last_activity on valid sessions (sliding window).
     * Clears expired sessions automatically.
     */
    public static function check(): bool
    {
        $type = $_SESSION['auth_type'] ?? null;
        $id = $_SESSION['auth_id'] ?? null;
        $lastActivity = $_SESSION['_last_activity'] ?? null;

        if ($type === null || $id === null) {
            return false;
        }

        // Server-side expiry check: 8 hours of inactivity (unless remember_me)
        if ($lastActivity !== null
            && empty($_SESSION['remember_me'])
            && (time() - $lastActivity) > self::SESSION_TIMEOUT) {
            self::clearSession();
            return false;
        }

        // Sliding window: update last activity
        $_SESSION['_last_activity'] = time();

        return true;
    }

    /**
     * Get the authenticated user data, or null if not authenticated.
     *
     * @return array{type: string, id: string, name: string, email: string, tenant_id?: string, role?: string}|null
     */
    public static function user(): ?array
    {
        if (!self::check()) {
            return null;
        }

        $user = [
            'type'  => $_SESSION['auth_type'],
            'id'    => $_SESSION['auth_id'],
            'name'  => $_SESSION['auth_name'] ?? '',
            'email' => $_SESSION['auth_email'] ?? '',
        ];

        if ($_SESSION['auth_type'] === 'business_user') {
            $user['tenant_id'] = $_SESSION['auth_tenant_id'] ?? '';
            $user['role'] = $_SESSION['auth_role'] ?? '';
        }

        return $user;
    }

    /**
     * Log out: clear session data and destroy the session.
     *
     * If impersonation is active, this exits impersonation and keeps
     * the operator session intact (does NOT destroy the session).
     * Returns true if impersonation was exited, false if real logout.
     */
    public static function logout(): bool
    {
        // If impersonating, exit impersonation instead of real logout
        if (self::isImpersonating()) {
            self::endImpersonation();
            return true; // Signal: impersonation ended, session preserved
        }

        AuditLog::logLogout();

        self::clearSession();

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }

        return false; // Signal: real logout
    }

    public static function isOperator(): bool
    {
        return self::check() && ($_SESSION['auth_type'] ?? '') === 'operator';
    }

    public static function isBusinessUser(): bool
    {
        return self::check() && ($_SESSION['auth_type'] ?? '') === 'business_user';
    }

    /**
     * Get the business user's role, or null if not a business user.
     */
    public static function businessUserRole(): ?string
    {
        if (!self::isBusinessUser()) {
            return null;
        }

        return $_SESSION['auth_role'] ?? null;
    }

    /**
     * Check if the current user is a business user with the 'owner' role.
     */
    public static function isOwner(): bool
    {
        return self::businessUserRole() === 'owner';
    }

    /**
     * Check if the current user is a business user with the 'manager' role.
     */
    public static function isManager(): bool
    {
        return self::businessUserRole() === 'manager';
    }

    /**
     * Get the current user's tenant ID, or null if operator/not authenticated.
     */
    public static function tenantId(): ?string
    {
        if (!self::isBusinessUser()) {
            return null;
        }

        return $_SESSION['auth_tenant_id'] ?? null;
    }

    /**
     * Get the effective tenant context.
     *
     * Returns the impersonated tenant ID if impersonating,
     * the business user's tenant ID if authenticated as business user,
     * or null for operators not impersonating.
     */
    public static function effectiveTenantId(): ?string
    {
        if (self::isImpersonating()) {
            return $_SESSION['impersonation_tenant_id'] ?? null;
        }

        return self::tenantId();
    }

    // ── Impersonation ──

    /**
     * Start impersonating a tenant.
     *
     * Operator-only. Sets impersonation session keys without modifying
     * the authentic operator session. Logs the event.
     */
    public static function startImpersonation(string $tenantId, string $tenantName): bool
    {
        if (!self::isOperator()) {
            return false;
        }

        $_SESSION['impersonation_active']      = true;
        $_SESSION['impersonation_tenant_id']   = $tenantId;
        $_SESSION['impersonation_tenant_name'] = $tenantName;

        AuditLog::log(
            'impersonation.started',
            'tenant',
            $tenantId,
            ['tenant_name' => $tenantName],
            $tenantId,
        );

        return true;
    }

    /**
     * End impersonation and return to operator context.
     */
    public static function endImpersonation(): void
    {
        $tenantId = $_SESSION['impersonation_tenant_id'] ?? null;
        $tenantName = $_SESSION['impersonation_tenant_name'] ?? '';

        unset(
            $_SESSION['impersonation_active'],
            $_SESSION['impersonation_tenant_id'],
            $_SESSION['impersonation_tenant_name'],
        );

        if ($tenantId !== null) {
            AuditLog::log(
                'impersonation.ended',
                'tenant',
                $tenantId,
                ['tenant_name' => $tenantName],
                $tenantId,
            );
        }
    }

    /**
     * Check if the current operator is impersonating a tenant.
     */
    public static function isImpersonating(): bool
    {
        return self::isOperator()
            && !empty($_SESSION['impersonation_active'])
            && !empty($_SESSION['impersonation_tenant_id']);
    }

    /**
     * Get the impersonated tenant ID, or null if not impersonating.
     */
    public static function impersonatedTenantId(): ?string
    {
        return self::isImpersonating()
            ? ($_SESSION['impersonation_tenant_id'] ?? null)
            : null;
    }

    /**
     * Get the impersonated tenant name, or null if not impersonating.
     */
    public static function impersonatedTenantName(): ?string
    {
        return self::isImpersonating()
            ? ($_SESSION['impersonation_tenant_name'] ?? null)
            : null;
    }

    /**
     * Check if the current user can access a given tenant.
     *
     * Operators can access any tenant.
     * Business users can only access their own tenant.
     */
    public static function canAccessTenant(string $tenantId): bool
    {
        if (self::isOperator()) {
            return true;
        }

        if (self::isBusinessUser()) {
            return self::tenantId() === $tenantId;
        }

        return false;
    }

    /**
     * Check if the current user has at least 'owner' level access.
     *
     * Returns true for operators (who have full access)
     * and business users with the 'owner' role.
     * Returns false for managers.
     */
    public static function canManageTenant(): bool
    {
        if (self::isOperator()) {
            return true;
        }

        return self::isOwner();
    }

    /**
     * Store session data for an authenticated user.
     *
     * Called by login() after successful credential verification,
     * and by tests for session setup.
     */
    public static function setSession(
        string $type,
        string $id,
        string $name,
        string $email,
        ?string $tenantId = null,
        ?string $role = null
    ): void {
        $_SESSION['auth_type'] = $type;
        $_SESSION['auth_id'] = $id;
        $_SESSION['auth_name'] = $name;
        $_SESSION['auth_email'] = $email;
        $_SESSION['_last_activity'] = time();

        if ($type === 'business_user') {
            $_SESSION['auth_tenant_id'] = $tenantId ?? '';
            $_SESSION['auth_role'] = $role ?? '';
        }
    }

    /**
     * Clear all auth-related session keys.
     */
    public static function clearSession(): void
    {
        $authKeys = [
            'auth_type', 'auth_id', 'auth_name', 'auth_email',
            'auth_tenant_id', 'auth_role', '_last_activity',
            'force_password_change', 'remember_me',
            'impersonation_active', 'impersonation_tenant_id', 'impersonation_tenant_name',
        ];

        foreach ($authKeys as $key) {
            unset($_SESSION[$key]);
        }
    }

    /**
     * Protect against session fixation (PRD §XV).
     *
     * Regenerates the session ID after successful authentication.
     * Preserves CSRF token so the post-login redirect still works.
     * Requires Auth::startSession() to have been called first
     * (enforced by CsrfMiddleware for POST /admin/login).
     */
    private static function regenerateSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        // Preserve the CSRF token across regeneration
        $csrfToken = $_SESSION['_csrf_token'] ?? null;

        session_regenerate_id(true);

        // Restore CSRF token so subsequent forms still validate
        if ($csrfToken !== null) {
            $_SESSION['_csrf_token'] = $csrfToken;
        }
    }

    /**
     * Reset internal state (for testing).
     */
    public static function reset(): void
    {
        // No internal cache to reset — Auth reads from $_SESSION directly
    }
}
