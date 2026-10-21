<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Engine\Database;
use App\Engine\FormState;
use App\Engine\Locale;
use App\Engine\Request;
use App\Engine\Response;
use App\Engine\Ulid;
use App\Middleware\CsrfMiddleware;

/**
 * Public landing page and access request handler.
 *
 * GET  /                → homepage (simple logo/name default, or full landing
 *                         page with request form when enable_applications = 1)
 * POST /request-access  → processes the access request form submission
 *                         (only reachable when enable_applications = 1)
 */
final class HomeController
{
    public function index(Request $request): Response
    {
        $applicationsEnabled = $this->applicationsEnabled();

        // Only start a NEW session when the form is rendered (applications mode).
        // The simple homepage must not set a session cookie for anonymous visitors
        // to maintain the privacy posture that cookies are admin-only.
        //
        // However, if a vb_session cookie already exists (admin previously logged in),
        // resume that session so the nav shows "Dashboard" instead of "Log in".
        $csrfToken = '';
        $flash     = null;
        $isLoggedIn = false;
        $hasExistingSession = isset($_COOKIE['vb_session']);

        if ($applicationsEnabled || $hasExistingSession) {
            \App\Engine\Auth::startSession();
        }

        if ($applicationsEnabled) {
            $csrfToken = CsrfMiddleware::generateToken();
            $flash     = FormState::getToast();
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            $isLoggedIn = \App\Engine\Auth::check();
        }

        ob_start();
        require __DIR__ . '/../../templates/home.php';

        return Response::html(ob_get_clean());
    }

    public function submitRequest(Request $request): Response
    {
        if (!$this->applicationsEnabled()) {
            return Response::redirect('/');
        }

        // Anti-spam: timestamp check (field populated by JS on page load)
        $ts = $request->string('__ts');
        if ($ts === '' || !is_numeric($ts)) {
            FormState::toast('error', __('public.error_spam'));
            return Response::redirect('/');
        }
        $elapsed = time() * 1000 - (int) $ts;
        // Reject submissions under 3 seconds (bot speed) or over 1 hour (stale form)
        if ($elapsed < 3000 || $elapsed > 3_600_000) {
            FormState::toast('error', __('public.error_spam'));
            return Response::redirect('/');
        }

        // Anti-spam: honeypot (hidden field — bots fill it, humans don't)
        if (trim($request->string('__hp')) !== '') {
            FormState::toast('error', __('public.error_spam'));
            return Response::redirect('/');
        }

        return \App\Application\Business\BusinessUi::submitApplication($request);
    }

    private function applicationsEnabled(): bool
    {
        try {
            $rows = Database::query(
                "SELECT `value` FROM `settings` WHERE `key` = 'enable_applications' LIMIT 1"
            );
            return ($rows[0]['value'] ?? '0') === '1';
        } catch (\Throwable) {
            return false;
        }
    }
}
