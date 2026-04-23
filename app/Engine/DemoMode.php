<?php

declare(strict_types=1);

namespace App\Engine;

/**
 * Demo mode sentinel and configuration.
 *
 * Demo mode activates when the `.demo` file exists in the project root.
 * When active:
 * - Database::connect() is redirected to the SQLite demo database
 * - All POST/PUT/DELETE requests are blocked by DemoMiddleware (except allowed routes)
 * - Admin UI renders read-only guards and a demo banner
 * - Booking page shows a demo notice instead of submitting bookings
 *
 * The demo database is a read-only SQLite file at storage/demo/demo.db,
 * pre-seeded with fictional data by demo-seed.php.
 */
final class DemoMode
{
    /** Routes that are allowed to POST even in demo mode. */
    public const ALLOWED_WRITE_ROUTES = [
        'POST /admin/login',
        'POST /auth/logout',
        'POST /admin/impersonate/exit',
    ];

    /**
     * Route prefixes that are allowed to POST even in demo mode.
     *
     * Used for routes with dynamic segments (e.g. tenant ID)
     * where exact matching is not possible.
     */
    private const ALLOWED_WRITE_PREFIXES = [
        'POST /admin/tenants/',  // Matches .../impersonate
    ];

    /** Suffixes that qualify a prefix match as allowed. */
    private const ALLOWED_WRITE_SUFFIXES = [
        '/impersonate',
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
     * Get the path to the demo SQLite database.
     */
    public static function databasePath(): string
    {
        return self::$basePath . '/storage/demo/demo.db';
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
     * Impersonation (session-only, no DB writes) is allowed so operators
     * can explore tenant views in the demo.
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

        // Exact match
        if (in_array($key, self::ALLOWED_WRITE_ROUTES, true)) {
            return true;
        }

        // Prefix + suffix match (for routes with dynamic segments)
        foreach (self::ALLOWED_WRITE_PREFIXES as $prefix) {
            if (str_starts_with($key, $prefix)) {
                foreach (self::ALLOWED_WRITE_SUFFIXES as $suffix) {
                    if (str_ends_with($path, $suffix)) {
                        return true;
                    }
                }
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
