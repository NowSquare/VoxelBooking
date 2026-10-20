<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Engine\Database;
use App\Engine\Request;
use App\Engine\Response;

/**
 * Checks if VoxelBooking is installed. Routes to /install if not.
 *
 * Installation state is determined by ALL of:
 * 1. .env exists and DB_HOST is non-empty
 * 2. Database connection succeeds
 * 3. `settings` table exists
 * 4. `settings.installed_at` is present and non-empty
 *
 * If any check fails → not installed → route to /install.
 * After installation completes → /install returns 404 (not redirect).
 *
 * Passthrough routes: /health always passes through regardless of state.
 */
final class InstalledMiddleware
{
    public function handle(Request $request, callable $next): Response
    {
        $path = $request->path();

        // Health endpoint always passes through
        if ($path === '/health') {
            return $next($request);
        }

        $isInstallRoute = str_starts_with($path, '/install');
        $installed = self::isInstalled();

        // Not installed → route to /install (unless already there)
        if (!$installed && !$isInstallRoute) {
            return Response::redirect('/install');
        }

        // Installed → /install returns 404, not redirect
        if ($installed && $isInstallRoute) {
            if ($request->isJson()) {
                return Response::json(['error' => 'not_found', 'message' => 'Not found'], 404);
            }

            return Response::html('<h1>404 ' . htmlspecialchars(__('admin.errors.404_title'), ENT_QUOTES, 'UTF-8') . '</h1>', 404);
        }

        return $next($request);
    }

    /**
     * Centralized installation state check.
     *
     * Used by middleware and by public/index.php.
     * Must remain a static method so both can call it without instantiation.
     */
    public static function isInstalled(): bool
    {
        // Check 1: .env loaded and DB_HOST present
        if (empty($_ENV['DB_HOST'] ?? '')) {
            return false;
        }

        try {
            // Check 2: database connection succeeds
            if (!Database::canConnect()) {
                return false;
            }

            // Check 3: settings table exists
            if (!Database::tableExists('settings')) {
                return false;
            }

            // Check 4: installed_at is present and non-empty
            $result = Database::query(
                "SELECT `value` FROM `settings` WHERE `key` = 'installed_at'"
            );

            return !empty($result[0]['value'] ?? '');
        } catch (\Throwable) {
            return false;
        }
    }
}
