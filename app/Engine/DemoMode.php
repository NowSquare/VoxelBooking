<?php

declare(strict_types=1);

namespace App\Engine;

/**
 * Demo mode sentinel and configuration.
 *
 * Demo mode activates when the `.demo` file exists in the project root.
 * When active:
 * - Interactive demo with daily reset: bookings, status changes, and
 *   operational workflows are allowed so reviewers can test full flows.
 * - System settings (SMTP, general config, cron token) are locked.
 * - Tenant settings (branding, booking page config) are locked.
 * - Install wizard, updates, file uploads, and security-sensitive
 *   operations are blocked.
 * - Email sending is allowed when configured, but suppressed to
 *   demo/seeded domains (.test, example.com, etc.) via Mailer.
 * - Database is reset/reseeded daily via cron or manual command.
 *
 * The `.demo` file is a zero-byte sentinel. It is only checked for
 * existence via `file_exists()`. SMTP and database configuration
 * belong in `.env` or the server's environment variables.
 */
final class DemoMode
{
    /**
     * Route patterns that are BLOCKED in demo mode.
     *
     * Everything NOT on this list is allowed through.
     * This is a denylist — operational workflows pass by default.
     */
    private const BLOCKED_ROUTE_PATTERNS = [
        // System settings
        'POST /admin/settings',
        'POST /admin/settings/email',
        'POST /admin/settings/cron/run',

        // Install wizard
        'POST /install/step/2',
        'POST /install/step/3',
        'POST /install/step/4',
        'POST /install/step/5',
        'POST /install/complete',

        // Updates
        'POST /admin/updates/upload',
        'POST /admin/updates/apply',

        // Account password changes (preserve demo credentials)
        'POST /admin/account',

        // Deletion queue (GDPR — don't let demo visitors anonymize data)
        'POST /admin/deletion-queue/confirm',

        // Request access form (don't let demo visitors submit applications)
        'POST /request-access',
    ];

    /**
     * Route prefixes that are BLOCKED in demo mode.
     * Matched with str_starts_with against "METHOD /path".
     */
    private const BLOCKED_ROUTE_PREFIXES = [
        // Tenant settings (all sub-pages)
        'POST /admin/tenants/',
    ];

    /**
     * Suffixes within blocked prefixes that are ALLOWED (exceptions).
     * These override the prefix block for operational workflows.
     */
    private const ALLOWED_SUFFIX_EXCEPTIONS = [
        // Booking operations (status, reschedule, create)
        '/bookings',
        '/bookings/create',
        '/status',
        '/reschedule',
        // Impersonation
        '/impersonate',
        // Staff/service/resource/event activate/deactivate (toggle)
        '/activate',
        '/deactivate',
    ];

    /**
     * Domains that are considered demo/seeded and should NOT receive email.
     * Checked against the domain part of recipient email addresses.
     */
    public const SUPPRESSED_EMAIL_DOMAINS = [
        'example.com',
        'example.org',
        'example.net',
        'test',             // catches .test TLD
        'invalid',          // catches .invalid TLD
        'localhost',
        'booking.test',
        'voxelbooking.test',
    ];

    private static ?bool $active = null;
    private static string $basePath = '';

    /**
     * Initialize with the application base path.
     */
    public static function init(string $basePath): void
    {
        self::$basePath = rtrim($basePath, '/');
        self::$active = null; // Force re-check
    }

    /**
     * Check if demo mode is active.
     *
     * Caches the result for the duration of the request.
     */
    public static function isActive(): bool
    {
        if (self::$active !== null) {
            return self::$active;
        }

        // Check for sentinel file
        self::$active = file_exists(self::sentinelPath());

        return self::$active;
    }


    /**
     * Get the path to the demo sentinel file.
     */
    public static function sentinelPath(): string
    {
        return self::$basePath . '/.demo';
    }

    /**
     * Check if a request method + path combination is allowed in demo mode.
     *
     * Uses a denylist approach: everything is allowed unless explicitly blocked.
     * Operational workflows (bookings, status changes, etc.) pass through.
     * System/tenant settings and security-sensitive operations are blocked.
     *
     * @param string $method HTTP method (GET, POST, etc.)
     * @param string $path   Request path
     */
    public static function isWriteAllowed(string $method, string $path): bool
    {
        // GET/HEAD/OPTIONS are always allowed (read-only)
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return true;
        }

        $key = strtoupper($method) . ' ' . $path;

        // Exact-match block
        if (in_array($key, self::BLOCKED_ROUTE_PATTERNS, true)) {
            return false;
        }

        // Prefix block with suffix exceptions
        foreach (self::BLOCKED_ROUTE_PREFIXES as $prefix) {
            if (str_starts_with($key, $prefix)) {
                // Check for always-blocked sub-paths first (before suffix exceptions)
                // Settings sub-pages: /settings, /settings/branding, etc.
                if (str_contains($path, '/settings')) {
                    return false;
                }
                // User management (invite/deactivate/activate)
                if (str_contains($path, '/users/')) {
                    return false;
                }

                // Check if this is an allowed exception (operational workflow)
                foreach (self::ALLOWED_SUFFIX_EXCEPTIONS as $suffix) {
                    if (str_ends_with($path, $suffix)) {
                        return true;
                    }
                }

                // Allow other tenant operations (archive, tenant create, etc.)
                return true;
            }
        }

        // Not in any blocklist — allow
        return true;
    }

    /**
     * Check if an email address belongs to a suppressed demo domain.
     *
     * In demo mode, emails to seeded/fictional addresses are suppressed
     * to prevent spam and ensure only real reviewer addresses receive mail.
     */
    public static function isEmailSuppressed(string $email): bool
    {
        $domain = strtolower(substr($email, strrpos($email, '@') + 1));

        foreach (self::SUPPRESSED_EMAIL_DOMAINS as $suppressed) {
            // Exact match or TLD match (e.g., "test" matches "anything.test")
            if ($domain === $suppressed || str_ends_with($domain, '.' . $suppressed)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Reset internal state (for testing).
     */
    public static function reset(): void
    {
        self::$active = null;
        self::$basePath = '';
    }
}
