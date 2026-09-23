<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Engine\Auth;
use App\Engine\Locale;
use App\Engine\Request;
use App\Engine\Response;

/**
 * CSRF verification for state-changing requests.
 *
 * Per PRD §XV Security:
 * - Per-session CSRF token on all POST/PUT/DELETE
 * - Token stored in session, verified from form field or header
 * - Booking page uses a per-page token generated server-side
 * - The public booking API verifies itself through verifyPublicSubmission(),
 *   which also accepts a same-origin request so the embed widget stays
 *   cookie-free
 */
final class CsrfMiddleware
{
    public function handle(Request $request, callable $next): Response
    {
        $method = $request->method();

        // Only verify on state-changing methods
        if (!in_array($method, ['POST', 'PUT', 'DELETE'], true)) {
            return $next($request);
        }

        // Skip CSRF for API routes (they use bearer token auth)
        if (str_starts_with($request->path(), '/api/')) {
            return $next($request);
        }

        // Skip CSRF for cron endpoint (uses secret token)
        if (str_starts_with($request->path(), '/cron/')) {
            return $next($request);
        }

        // Ensure session is started for CSRF verification
        // Admin/auth routes and the public homepage use Auth::startSession()
        // for consistent vb_session naming. Other routes use PHPSESSID.
        if (session_status() !== PHP_SESSION_ACTIVE) {
            $path = $request->path();
            if (str_starts_with($path, '/admin')
                || str_starts_with($path, '/auth/')
                || $path === '/'
                || $path === '/request-access') {
                Auth::startSession();
            } else {
                session_start();
            }
        }

        if (!self::tokenMatches($request)) {
            if ($request->isJson()) {
                return Response::json([
                    'error' => 'csrf_mismatch',
                    'message' => 'Invalid security token. Please refresh and try again.',
                ], 403);
            }

            // This middleware runs before any locale resolution, so pick the
            // language from the browser to keep the page readable.
            Locale::setLocale(Locale::negotiateFromHeader($request->header('Accept-Language')));

            return Response::html(
                '<h1>' . htmlspecialchars(__('admin.errors.csrf_title'), ENT_QUOTES, 'UTF-8') . '</h1>'
                . '<p>' . htmlspecialchars(__('admin.errors.csrf_desc'), ENT_QUOTES, 'UTF-8') . '</p>',
                403
            );
        }

        return $next($request);
    }

    /**
     * Generate a CSRF token and store it in the session.
     */
    public static function generateToken(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            // Prefer Auth session for admin context; caller should ensure
            // session is started before calling this in admin routes.
            session_start();
        }

        if (empty($_SESSION['_csrf_token'])) {
            $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['_csrf_token'];
    }

    /**
     * Get the current CSRF token (for forms).
     */
    public static function token(): string
    {
        return $_SESSION['_csrf_token'] ?? '';
    }

    /**
     * Verify a state-changing request from the public booking page.
     *
     * The page proves it made the request in one of two ways:
     *
     * 1. Session token. The standalone page echoes the per-session token
     *    in the X-CSRF-Token header. The middleware pipeline skips /api/
     *    routes, so the booking API asks for this check itself.
     * 2. Same origin. The embedded page runs in a cross-site iframe, where
     *    browsers withhold cookies. It has no session and no token. Browsers
     *    attach an Origin header to every POST and scripts cannot forge it,
     *    so an Origin that names this host proves the request came from a
     *    page this installation served.
     *
     * Both proofs stop cross-site forgery from a browser. Neither stops a
     * direct HTTP client, and neither needs to: the booking API carries no
     * ambient credentials. The anti-bot pipeline (timestamp, honeypot,
     * rate limit) handles automation.
     */
    public static function verifyPublicSubmission(Request $request): bool
    {
        return self::tokenMatches($request) || self::isSameOrigin($request);
    }

    /**
     * Whether the request echoes the CSRF token held in its session.
     *
     * The session is resumed only when the browser sent a session cookie.
     * A request without one cannot hold a token, and starting a session
     * for it would set a cookie the embed widget must never set.
     */
    public static function tokenMatches(Request $request): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE && isset($_COOKIE[session_name()])) {
            session_start();
        }

        $sessionToken = $_SESSION['_csrf_token'] ?? '';
        $submittedToken = $request->string('_csrf_token')
            ?: ($request->header('X-CSRF-Token') ?? '');

        return $sessionToken !== ''
            && $submittedToken !== ''
            && hash_equals($sessionToken, $submittedToken);
    }

    /**
     * Whether the browser attests that the request came from this host.
     *
     * Only hostnames are compared. Scheme and port are ignored so that a
     * TLS-terminating proxy or an explicit default port cannot break the
     * embed; a same-host request over another scheme is a network
     * attacker's problem, not a cross-site one. The Host header is the
     * comparator because the widget script builds its own origin from it,
     * so both halves of the embed agree on the name.
     */
    public static function isSameOrigin(Request $request): bool
    {
        $originHost = self::hostOf($request->header('Origin') ?? '');
        $ownHost = self::hostOf('http://' . ($request->header('Host') ?? ''));

        return $originHost !== '' && $originHost === $ownHost;
    }

    /**
     * Lower-cased hostname of a URL, or '' when there is none
     * (missing header, the opaque origin "null", garbage).
     */
    private static function hostOf(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) ? strtolower($host) : '';
    }
}
