<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Engine\DemoMode;
use App\Engine\Request;
use App\Engine\Response;
use App\Engine\FormState;

/**
 * Demo mode middleware.
 *
 * When demo mode is active (.demo sentinel exists):
 * 1. Checks write requests against DemoMode's denylist.
 *    - Blocked routes: returns 403 JSON (API) or redirect with toast (browser).
 *    - Allowed routes: passes through to the next middleware (interactive demo).
 * 2. Operational workflows (bookings, status changes, reschedule) are allowed.
 * 3. System settings, tenant settings, install, updates are blocked.
 *
 * This middleware should run AFTER SecurityMiddleware and InstalledMiddleware,
 * but BEFORE CsrfMiddleware (blocked requests don't need CSRF validation).
 */
final class DemoMiddleware
{
    public function handle(Request $request, callable $next): Response
    {
        if (!DemoMode::isActive()) {
            return $next($request);
        }

        // Block non-allowed write requests
        if (!DemoMode::isWriteAllowed($request->method(), $request->path())) {
            // API routes: always JSON 403 (Agent API, Booking API — no header dependency)
            if (str_starts_with($request->path(), '/api/')) {
                return Response::json([
                    'error'   => 'demo_mode',
                    'message' => __('admin.demo.write_blocked'),
                ], 403);
            }

            // Browser form submissions: redirect back with toast
            FormState::toast('error', __('admin.demo.settings_locked'));

            $referer = $request->header('Referer');
            $fallback = str_starts_with($request->path(), '/book/')
                ? $request->path()   // Public pages: redirect to same path (GET handler)
                : '/admin/settings'; // Admin pages: redirect to settings
            return Response::redirect($referer ?: $fallback);
        }

        return $next($request);
    }
}
