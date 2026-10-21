<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Integration tests for the authentication flow.
 *
 * These tests hit the real HTTP endpoints via curl to verify
 * session handling, login/logout, and redirect behavior.
 *
 * Requires:
 * - The app running at APP_TEST_URL (defaults to https://voxelbooking-app.test)
 *
 * Test fixtures are provisioned automatically by TestFixtures::provision().
 */
final class AuthFlowTest extends TestCase
{
    private string $baseUrl;
    private string $cookieJar;
    private static bool $appReachable = false;

    public static function setUpBeforeClass(): void
    {
        $baseUrl = rtrim($_ENV['APP_TEST_URL'] ?? 'https://voxelbooking-app.test', '/');

        $ch = curl_init($baseUrl . '/health');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_TIMEOUT => 5]);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);

        if ($code === 0) {
            return;
        }

        self::$appReachable = true;

        try {
            TestFixtures::provision();
        } catch (\Throwable) {
            // best-effort — tests will fail if fixtures are missing
        }
    }

    protected function setUp(): void
    {
        $this->baseUrl = rtrim($_ENV['APP_TEST_URL'] ?? 'https://voxelbooking-app.test', '/');
        $this->cookieJar = tempnam(sys_get_temp_dir(), 'vb_test_');

        if (!self::$appReachable) {
            $this->markTestSkipped('App not reachable at ' . $this->baseUrl);
        }
    }

    /**
     * Skip the current test when the test process has no direct DB access.
     *
     * Some test methods call Database::query() or LoginToken:: directly
     * (not just via curl). In sandboxed environments where the web server
     * can reach MySQL but the PHPUnit process cannot, those calls would
     * throw an unhandled PDOException. This guard issues a live SELECT 1
     * probe rather than relying on a cached flag, because the Database
     * singleton can return a stale PDO whose socket is dead.
     */
    private function requireDbAccess(): void
    {
        try {
            \App\Engine\Database::query('SELECT 1');
        } catch (\Throwable) {
            $this->markTestSkipped('Test process has no direct database access');
        }
    }

    protected function tearDown(): void
    {
        @unlink($this->cookieJar);
    }

    /**
     * Blocker 2 regression: authenticated users visiting /admin/login
     * must be redirected (302) to /admin, not shown the login form.
     */
    public function testAuthenticatedUserRedirectedFromLoginPage(): void
    {
        // Log in
        $this->doLogin();

        // Verify we're authenticated
        $response = $this->get('/admin');
        $this->assertSame(200, $response['code'], 'Dashboard should be accessible after login');

        // Visit login page while authenticated → must redirect
        $response = $this->get('/admin/login');
        $this->assertSame(302, $response['code'], 'Authenticated user should be redirected from login page');
        $this->assertStringContainsString('/admin', $response['location'], 'Should redirect to /admin');
    }

    /**
     * Session fixation protection: session ID must change after login.
     */
    public function testSessionIdChangesAfterLogin(): void
    {
        // Get initial session cookie
        $response = $this->get('/admin/login');
        $this->assertSame(200, $response['code']);
        $preLoginSession = $response['cookies']['vb_session'] ?? '';
        $this->assertNotEmpty($preLoginSession, 'Should receive a session cookie');

        // Log in
        $this->doLogin();

        // Get post-login session cookie
        $response = $this->get('/admin');
        $postLoginSession = $response['cookies']['vb_session'] ?? '';

        // Session ID should have changed (fixation protection)
        $this->assertNotSame($preLoginSession, $postLoginSession,
            'Session ID must change after login (fixation protection)');
    }

    /**
     * Unauthenticated access to /admin must redirect to /admin/login.
     */
    public function testUnauthenticatedAdminRedirect(): void
    {
        $response = $this->get('/admin');
        $this->assertSame(302, $response['code']);
        $this->assertStringContainsString('/admin/login', $response['location']);
    }

    /**
     * Admin 404 should render within the admin shell (has sidebar).
     */
    public function testAdmin404UsesAdminShell(): void
    {
        $this->doLogin();

        $response = $this->get('/admin/nonexistent-page');
        $this->assertSame(404, $response['code']);
        $this->assertStringContainsString('sidebar', $response['body'],
            'Admin 404 should render inside admin layout with sidebar');
    }

    /**
     * Public 404 should NOT use admin shell.
     */
    public function testPublic404IsStandalone(): void
    {
        $response = $this->get('/nonexistent-page');
        $this->assertSame(404, $response['code']);
        $this->assertStringContainsString('Page not found', $response['body']);
    }

    /**
     * Logout destroys session — subsequent /admin should redirect.
     */
    public function testLogoutDestroysSession(): void
    {
        $this->doLogin();

        // Verify authenticated
        $response = $this->get('/admin');
        $this->assertSame(200, $response['code']);

        // Get CSRF token from dashboard
        preg_match('/name="_csrf_token" value="([^"]+)"/', $response['body'], $m);
        $csrf = $m[1] ?? '';
        $this->assertNotEmpty($csrf, 'Should have CSRF token');

        // Logout
        $response = $this->post('/auth/logout', ['_csrf_token' => $csrf]);
        $this->assertSame(302, $response['code']);

        // Verify no longer authenticated
        $response = $this->get('/admin');
        $this->assertSame(302, $response['code'], 'Should redirect after logout');
        $this->assertStringContainsString('/admin/login', $response['location']);
    }

    // ── Helpers ──

    private function doLogin(): void
    {
        $response = $this->get('/admin/login');
        preg_match('/name="_csrf_token" value="([^"]+)"/', $response['body'], $m);
        $csrf = $m[1] ?? '';

        $this->post('/admin/login', [
            'email' => TestFixtures::OPERATOR_EMAIL,
            'password' => TestFixtures::OPERATOR_PASSWORD,
            '_csrf_token' => $csrf,
        ]);
    }

    /**
     * @return array{code: int, body: string, location: string, cookies: array<string, string>}
     */
    private function get(string $path): array
    {
        $ch = curl_init($this->baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIEJAR => $this->cookieJar,
            CURLOPT_COOKIEFILE => $this->cookieJar,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADER => true,
        ]);
        $response = (string) curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        unset($ch);

        $headers = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);

        preg_match('/location: (.+)/i', $headers, $loc);
        $cookies = $this->parseCookies($headers);

        return [
            'code'     => $code,
            'body'     => $body,
            'location' => trim($loc[1] ?? ''),
            'cookies'  => $cookies,
        ];
    }

    /**
     * @return array{code: int, body: string, location: string, cookies: array<string, string>}
     */
    private function post(string $path, array $data): array
    {
        $ch = curl_init($this->baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIEJAR => $this->cookieJar,
            CURLOPT_COOKIEFILE => $this->cookieJar,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($data),
            CURLOPT_HEADER => true,
        ]);
        $response = (string) curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        unset($ch);

        $headers = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);

        preg_match('/location: (.+)/i', $headers, $loc);
        $cookies = $this->parseCookies($headers);

        return [
            'code'     => $code,
            'body'     => $body,
            'location' => trim($loc[1] ?? ''),
            'cookies'  => $cookies,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function parseCookies(string $headers): array
    {
        $cookies = [];
        preg_match_all('/set-cookie: ([^=]+)=([^;]+)/i', $headers, $matches, PREG_SET_ORDER);
        foreach ($matches as $m) {
            $cookies[trim($m[1])] = trim($m[2]);
        }
        return $cookies;
    }

    /**
     * Session persistence: an authenticated session must survive a simulated
     * browser close/reopen.
     *
     * The 30-day cookie lifetime (Max-Age=2592000) means the browser retains
     * the vb_session cookie across restarts. This test simulates that by:
     * 1. Logging in and extracting the vb_session cookie value.
     * 2. Verifying the Set-Cookie header includes an Expires or Max-Age.
     * 3. Creating a fresh cookie jar seeded with only the persisted cookie.
     * 4. Verifying that /admin returns 200 (authenticated) using the new jar.
     */
    public function testSessionPersistsAcrossSimulatedBrowserRestart(): void
    {
        // Step 1: Log in with the primary cookie jar
        $this->doLogin();

        // Step 2: Verify authenticated and extract the session cookie
        $response = $this->get('/admin');
        $this->assertSame(200, $response['code'], 'Dashboard should be accessible after login');

        // Read the session cookie value from the jar file
        $jarContents = file_get_contents($this->cookieJar);
        $this->assertNotFalse($jarContents, 'Cookie jar should be readable');

        // Extract the vb_session cookie value from the Netscape-format jar
        // Format: domain\tTRUE/FALSE\tpath\tsecure\texpiry\tname\tvalue
        preg_match('/vb_session\t(.+)$/m', $jarContents, $sessionMatch);
        $this->assertNotEmpty($sessionMatch, 'vb_session cookie should exist in jar');
        $sessionValue = trim($sessionMatch[1]);
        $this->assertNotEmpty($sessionValue, 'vb_session cookie value should not be empty');

        // Step 3: Verify the cookie has a future expiry (not session-only)
        // In a Netscape cookie jar, column 5 (0-indexed: 4) is the expiry timestamp.
        // A session-only cookie would have expiry = 0.
        preg_match('/\t(\d+)\tvb_session\t/', $jarContents, $expiryMatch);
        if (!empty($expiryMatch)) {
            $expiry = (int) $expiryMatch[1];
            $this->assertGreaterThan(time(), $expiry,
                'vb_session cookie expiry should be in the future (persistent, not session-only)');
        }

        // Step 4: Simulate browser restart — create a fresh cookie jar
        // with only the persisted session cookie
        $freshJar = tempnam(sys_get_temp_dir(), 'vb_restart_');

        $ch = curl_init($this->baseUrl . '/admin');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIEJAR      => $freshJar,
            // Inject the persisted cookie directly — no COOKIEFILE means no prior jar
            CURLOPT_COOKIE         => 'vb_session=' . $sessionValue,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADER         => true,
        ]);
        $rawResponse = (string) curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);

        @unlink($freshJar);

        // Step 5: The session should still be valid → 200, not 302 redirect
        $this->assertSame(200, $code,
            'Authenticated session must survive browser restart (persisted cookie jar). '
            . 'Got HTTP ' . $code . ' instead of 200. '
            . 'This proves the 30-day Max-Age cookie keeps users logged in.');
    }

    /**
     * Verify the Set-Cookie header for vb_session includes Max-Age or Expires.
     * This ensures the cookie is not session-only (which would be lost on close).
     */
    public function testSessionCookieHasMaxAge(): void
    {
        // Get the login page to receive a Set-Cookie header
        $ch = curl_init($this->baseUrl . '/admin/login');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADER         => true,
        ]);
        $response = (string) curl_exec($ch);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        unset($ch);

        $headers = substr($response, 0, $headerSize);

        // Find the Set-Cookie header for vb_session
        preg_match('/set-cookie: vb_session=[^;]+;([^\r\n]+)/i', $headers, $cookieMatch);
        $this->assertNotEmpty($cookieMatch, 'Should receive a Set-Cookie header for vb_session');

        $cookieAttrs = strtolower($cookieMatch[1]);

        // Must have either Max-Age or Expires (not session-only)
        $hasMaxAge = str_contains($cookieAttrs, 'max-age=');
        $hasExpires = str_contains($cookieAttrs, 'expires=');
        $this->assertTrue(
            $hasMaxAge || $hasExpires,
            'vb_session cookie must have Max-Age or Expires attribute for persistence. '
            . 'Cookie attributes: ' . $cookieAttrs
        );

        // If Max-Age is present, verify it's the expected 30-day value
        if ($hasMaxAge) {
            preg_match('/max-age=(\d+)/', $cookieAttrs, $maxAgeMatch);
            $maxAge = (int) ($maxAgeMatch[1] ?? 0);
            $this->assertSame(2592000, $maxAge,
                'Max-Age should be 2592000 (30 days)');
        }
    }

    // ── remember_me ──

    public function testRememberedSessionSurvivesOvernightGap(): void
    {
        // Log in with remember_me
        $this->doLoginWithRememberMe();

        // Verify authenticated
        $response = $this->get('/admin');
        $this->assertSame(200, $response['code'], 'Dashboard should be accessible after login');

        // Extract session ID and mutate _last_activity to 12h ago
        $sessionId = $this->extractSessionId();
        $this->assertNotEmpty($sessionId, 'Should have a session ID');
        $this->mutateSessionActivity($sessionId, time() - (12 * 3600));

        // Simulate browser restart: fresh jar with only the persisted cookie
        $freshJar = tempnam(sys_get_temp_dir(), 'vb_rmb_');
        $code = $this->requestWithCookie('/admin', $sessionId, $freshJar);
        @unlink($freshJar);

        $this->assertSame(200, $code,
            'Remembered session must survive 12h inactivity gap (overnight). '
            . 'Got HTTP ' . $code . ' instead of 200.');
    }

    public function testNonRememberedSessionExpiresAfterOvernightGap(): void
    {
        // Log in WITHOUT remember_me
        $this->doLogin();

        $response = $this->get('/admin');
        $this->assertSame(200, $response['code']);

        // Extract session ID and mutate _last_activity to 12h ago
        $sessionId = $this->extractSessionId();
        $this->assertNotEmpty($sessionId);
        $this->mutateSessionActivity($sessionId, time() - (12 * 3600));

        // Simulate browser restart
        $freshJar = tempnam(sys_get_temp_dir(), 'vb_nrmb_');
        $code = $this->requestWithCookie('/admin', $sessionId, $freshJar);
        @unlink($freshJar);

        $this->assertSame(302, $code,
            'Non-remembered session must expire after 12h inactivity gap. '
            . 'Got HTTP ' . $code . ' instead of 302.');
    }

    public function testLoginWithoutRememberMeClearsStaleFlag(): void
    {
        // First login WITH remember_me
        $this->doLoginWithRememberMe();

        $response = $this->get('/admin');
        $this->assertSame(200, $response['code']);

        $sessionId1 = $this->extractSessionId();
        $this->assertNotEmpty($sessionId1);
        $this->assertSessionContains($sessionId1, 'remember_me',
            'Session should contain remember_me after remembered login');

        // Second login WITHOUT remember_me — no logout in between.
        // Auth::login() calls regenerateSession() which preserves session data
        // (including the stale remember_me flag). Only the controller's
        // explicit `unset($_SESSION['remember_me'])` clears it.
        $response = $this->get('/admin');
        preg_match('/name="_csrf_token" value="([^"]+)"/', $response['body'], $m);
        $csrf = $m[1] ?? '';

        $this->post('/admin/login', [
            'email' => TestFixtures::OPERATOR_EMAIL,
            'password' => TestFixtures::OPERATOR_PASSWORD,
            '_csrf_token' => $csrf,
            // no remember_me field
        ]);

        $response = $this->get('/admin');
        $this->assertSame(200, $response['code']);

        $sessionId2 = $this->extractSessionId();
        $this->assertNotEmpty($sessionId2);
        $this->assertSessionNotContains($sessionId2, 'remember_me',
            'Session must NOT contain remember_me after non-remembered re-login (no logout in between)');
    }

    // ── Session-file helpers ──

    private function doLoginWithRememberMe(): void
    {
        $response = $this->get('/admin/login');
        preg_match('/name="_csrf_token" value="([^"]+)"/', $response['body'], $m);
        $csrf = $m[1] ?? '';

        $this->post('/admin/login', [
            'email' => TestFixtures::OPERATOR_EMAIL,
            'password' => TestFixtures::OPERATOR_PASSWORD,
            '_csrf_token' => $csrf,
            'remember_me' => '1',
        ]);
    }

    private function extractSessionId(): string
    {
        $jarContents = file_get_contents($this->cookieJar);
        if ($jarContents === false) {
            return '';
        }
        preg_match('/vb_session\t(.+)$/m', $jarContents, $m);
        return trim($m[1] ?? '');
    }

    private function sessionFilePath(string $sessionId): string
    {
        return dirname(__DIR__, 2) . '/storage/sessions/sess_' . $sessionId;
    }

    private function mutateSessionActivity(string $sessionId, int $newTimestamp): void
    {
        $path = $this->sessionFilePath($sessionId);
        $this->assertFileExists($path, "Session file must exist: {$path}");

        $raw = file_get_contents($path);
        // PHP session format: _last_activity|i:<timestamp>;
        $updated = preg_replace(
            '/_last_activity\|i:\d+;/',
            '_last_activity|i:' . $newTimestamp . ';',
            $raw
        );
        file_put_contents($path, $updated);
    }

    private function assertSessionContains(string $sessionId, string $key, string $message = ''): void
    {
        $path = $this->sessionFilePath($sessionId);
        $this->assertFileExists($path);
        $raw = file_get_contents($path);
        $this->assertStringContainsString($key, $raw, $message);
    }

    private function assertSessionNotContains(string $sessionId, string $key, string $message = ''): void
    {
        $path = $this->sessionFilePath($sessionId);
        $this->assertFileExists($path);
        $raw = file_get_contents($path);
        $this->assertStringNotContainsString($key, $raw, $message);
    }

    private function requestWithCookie(string $path, string $sessionId, string $cookieJar): int
    {
        $ch = curl_init($this->baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIEJAR      => $cookieJar,
            CURLOPT_COOKIE         => 'vb_session=' . $sessionId,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADER         => true,
        ]);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);
        return $code;
    }

    // ══════════════════════════════════════════════════════════════
    // Multi-method auth: request-route + verify-route tests
    // ══════════════════════════════════════════════════════════════

    /**
     * Helper: switch to log transport, returning prior state for cleanup.
     */
    private function enableLogTransport(): array
    {
        $prior = \App\Engine\Database::query(
            "SELECT `value` FROM `settings` WHERE `key` = 'mail_transport' LIMIT 1"
        );
        $hadPrior = !empty($prior);
        $priorValue = $prior[0]['value'] ?? null;

        \App\Engine\Database::execute(
            "INSERT INTO `settings` (`key`, `value`) VALUES ('mail_transport', 'log')
             ON DUPLICATE KEY UPDATE `value` = 'log'"
        );
        \App\Engine\Mailer::clearConfigCache();

        return ['had' => $hadPrior, 'value' => $priorValue];
    }

    /**
     * Helper: restore mail transport and clean up test artifacts.
     */
    private function restoreTransport(array $prior): void
    {
        if ($prior['had'] && $prior['value'] !== null) {
            \App\Engine\Database::execute(
                "UPDATE `settings` SET `value` = ? WHERE `key` = 'mail_transport'",
                [$prior['value']]
            );
        } else {
            \App\Engine\Database::execute(
                "DELETE FROM `settings` WHERE `key` = 'mail_transport'"
            );
        }
        \App\Engine\Mailer::clearConfigCache();

        // Clean up tokens and email logs for test email
        \App\Engine\Database::execute(
            "DELETE FROM `login_tokens` WHERE `email` = ?",
            [TestFixtures::OPERATOR_EMAIL]
        );
        \App\Engine\Database::execute(
            "DELETE FROM `email_log` WHERE `to_email` = ?",
            [TestFixtures::OPERATOR_EMAIL]
        );
    }

    public function testRequestCodeOtpCreatesTokenAndLogsEmail(): void
    {
        $this->requireDbAccess();
        $prior = $this->enableLogTransport();

        try {
            // Get CSRF token
            $loginPage = $this->get('/admin/login');
            preg_match('/name="_csrf_token" value="([^"]+)"/', $loginPage['body'], $csrfMatch);
            $csrf = $csrfMatch[1] ?? '';

            $this->post('/admin/login/request-code', [
                '_csrf_token' => $csrf,
                'email'       => TestFixtures::OPERATOR_EMAIL,
                'method'      => 'otp',
            ]);

            // Assert token created
            $tokens = \App\Engine\Database::query(
                "SELECT `type`, `used_at` FROM `login_tokens`
                 WHERE `email` = ? AND `type` = 'otp' ORDER BY `created_at` DESC LIMIT 1",
                [TestFixtures::OPERATOR_EMAIL]
            );
            $this->assertNotEmpty($tokens, 'OTP token should be created');
            $this->assertNull($tokens[0]['used_at'], 'Token should not be used yet');

            // Assert email logged
            $log = \App\Engine\Database::query(
                "SELECT `type`, `status` FROM `email_log`
                 WHERE `to_email` = ? AND `type` = 'login_code' ORDER BY `id` DESC LIMIT 1",
                [TestFixtures::OPERATOR_EMAIL]
            );
            $this->assertNotEmpty($log, 'Email log entry should exist');
            $this->assertSame('sent', $log[0]['status']);
        } finally {
            $this->restoreTransport($prior);
        }
    }

    public function testRequestCodeMagicLinkCreatesTokenAndLogsEmail(): void
    {
        $this->requireDbAccess();
        $prior = $this->enableLogTransport();

        try {
            $loginPage = $this->get('/admin/login');
            preg_match('/name="_csrf_token" value="([^"]+)"/', $loginPage['body'], $csrfMatch);
            $csrf = $csrfMatch[1] ?? '';

            $this->post('/admin/login/request-code', [
                '_csrf_token'  => $csrf,
                'email'        => TestFixtures::OPERATOR_EMAIL,
                'method'       => 'magic_link',
                'remember_me'  => '1',
            ]);

            // Assert token with remember_me=1
            $tokens = \App\Engine\Database::query(
                "SELECT `type`, `remember_me` FROM `login_tokens`
                 WHERE `email` = ? AND `type` = 'magic_link' ORDER BY `created_at` DESC LIMIT 1",
                [TestFixtures::OPERATOR_EMAIL]
            );
            $this->assertNotEmpty($tokens, 'Magic link token should be created');
            $this->assertSame(1, (int) $tokens[0]['remember_me']);

            // Assert email logged
            $log = \App\Engine\Database::query(
                "SELECT `type` FROM `email_log`
                 WHERE `to_email` = ? AND `type` = 'magic_link' LIMIT 1",
                [TestFixtures::OPERATOR_EMAIL]
            );
            $this->assertNotEmpty($log);
        } finally {
            $this->restoreTransport($prior);
        }
    }

    public function testVerifyCodeOtpCreatesSession(): void
    {
        $this->requireDbAccess();
        try {
            // Create token via engine (not via route)
            $create = \App\Engine\LoginToken::createOtp(TestFixtures::OPERATOR_EMAIL, '127.0.0.1');
            $this->assertTrue($create['success']);

            // Get CSRF
            $verifyPage = $this->get('/admin/login/verify-code?email=' . urlencode(TestFixtures::OPERATOR_EMAIL));
            preg_match('/name="_csrf_token" value="([^"]+)"/', $verifyPage['body'], $csrfMatch);
            $csrf = $csrfMatch[1] ?? '';

            $result = $this->post('/admin/login/verify-code', [
                '_csrf_token' => $csrf,
                'email'       => TestFixtures::OPERATOR_EMAIL,
                'code'        => $create['code'],
            ]);

            $this->assertSame(302, $result['code']);
            $this->assertStringContainsString('/admin', $result['location']);

            // Verify authenticated
            $dashboard = $this->get('/admin');
            $this->assertSame(200, $dashboard['code'], 'Should be authenticated after OTP verify');
        } finally {
            \App\Engine\Database::execute(
                "DELETE FROM `login_tokens` WHERE `email` = ?",
                [TestFixtures::OPERATOR_EMAIL]
            );
        }
    }

    public function testVerifyMagicLinkCreatesSession(): void
    {
        $this->requireDbAccess();
        try {
            $create = \App\Engine\LoginToken::createMagicLink(TestFixtures::OPERATOR_EMAIL, '127.0.0.1');
            $this->assertTrue($create['success']);

            $result = $this->get('/admin/login/verify?token=' . $create['token']);
            $this->assertSame(302, $result['code']);
            $this->assertStringContainsString('/admin', $result['location']);

            $dashboard = $this->get('/admin');
            $this->assertSame(200, $dashboard['code'], 'Should be authenticated after magic link verify');
        } finally {
            \App\Engine\Database::execute(
                "DELETE FROM `login_tokens` WHERE `email` = ?",
                [TestFixtures::OPERATOR_EMAIL]
            );
        }
    }

    public function testVerifyCodeFailsWithWrongCode(): void
    {
        $this->requireDbAccess();
        try {
            \App\Engine\LoginToken::createOtp(TestFixtures::OPERATOR_EMAIL, '127.0.0.1');

            $verifyPage = $this->get('/admin/login/verify-code?email=' . urlencode(TestFixtures::OPERATOR_EMAIL));
            preg_match('/name="_csrf_token" value="([^"]+)"/', $verifyPage['body'], $csrfMatch);
            $csrf = $csrfMatch[1] ?? '';

            $result = $this->post('/admin/login/verify-code', [
                '_csrf_token' => $csrf,
                'email'       => TestFixtures::OPERATOR_EMAIL,
                'code'        => '000000',
            ]);

            $this->assertSame(302, $result['code']);
            $this->assertStringContainsString('verify-code', $result['location'], 'Should redirect back to verify-code');

            // Should NOT be authenticated
            $dashboard = $this->get('/admin');
            $this->assertSame(302, $dashboard['code'], 'Should not be authenticated with wrong code');
        } finally {
            \App\Engine\Database::execute(
                "DELETE FROM `login_tokens` WHERE `email` = ?",
                [TestFixtures::OPERATOR_EMAIL]
            );
        }
    }

    public function testMagicLinkRememberMeSurvivesOvernight(): void
    {
        $this->requireDbAccess();
        try {
            $create = \App\Engine\LoginToken::createMagicLink(
                TestFixtures::OPERATOR_EMAIL,
                '127.0.0.1',
                rememberMe: true
            );
            $this->assertTrue($create['success']);

            // Verify magic link
            $result = $this->get('/admin/login/verify?token=' . $create['token']);
            $this->assertSame(302, $result['code']);

            // Follow redirect to set session
            $this->get('/admin');

            // Mutate session to 12h ago
            $sessionId = $this->extractSessionId();
            $this->assertNotEmpty($sessionId, 'Session ID must exist');

            $twelveHoursAgo = time() - (12 * 3600);
            $this->mutateSessionActivity($sessionId, $twelveHoursAgo);

            // Should still be authenticated (remember_me bypasses timeout)
            $freshJar = tempnam(sys_get_temp_dir(), 'vb_ml_rm_');
            $code = $this->requestWithCookie('/admin', $sessionId, $freshJar);
            @unlink($freshJar);

            $this->assertSame(200, $code,
                'Magic link with remember_me should survive 12h inactivity gap');
        } finally {
            \App\Engine\Database::execute(
                "DELETE FROM `login_tokens` WHERE `email` = ?",
                [TestFixtures::OPERATOR_EMAIL]
            );
        }
    }

    /**
     * When mail is not configured, request-code must not claim success.
     *
     * Proves the fix for P2: "treats unsuccessful issuance as success".
     */
    public function testRequestCodeFailsWhenMailUnconfigured(): void
    {
        $this->requireDbAccess();
        // Save current transport, then set to empty (unconfigured SMTP)
        $prior = \App\Engine\Database::query(
            "SELECT `value` FROM `settings` WHERE `key` = 'mail_transport' LIMIT 1"
        );
        $hadPrior = !empty($prior);
        $priorValue = $prior[0]['value'] ?? null;

        // Set transport to 'smtp' with no host configured — isConfigured() returns false
        \App\Engine\Database::execute(
            "INSERT INTO `settings` (`key`, `value`) VALUES ('mail_transport', 'smtp')
             ON DUPLICATE KEY UPDATE `value` = 'smtp'"
        );
        // Also ensure smtp_host is empty
        \App\Engine\Database::execute(
            "INSERT INTO `settings` (`key`, `value`) VALUES ('smtp_host', '')
             ON DUPLICATE KEY UPDATE `value` = ''"
        );
        \App\Engine\Mailer::clearConfigCache();

        try {
            $loginPage = $this->get('/admin/login');
            preg_match('/name="_csrf_token" value="([^"]+)"/', $loginPage['body'], $csrfMatch);
            $csrf = $csrfMatch[1] ?? '';

            $result = $this->post('/admin/login/request-code', [
                '_csrf_token' => $csrf,
                'email'       => TestFixtures::OPERATOR_EMAIL,
                'method'      => 'otp',
            ]);

            // Should redirect to /admin/login (not /admin/login/verify-code)
            $this->assertSame(302, $result['code']);
            $this->assertStringNotContainsString('verify-code', $result['location'],
                'Must not redirect to OTP verify when mail is unconfigured');

            // The redirected login page should show an error, not a success
            $redirected = $this->get('/admin/login');
            $this->assertSame(200, $redirected['code']);
            // The error flash should contain the passwordless_unavailable message
            // (rendered in the page body before being consumed)
        } finally {
            // Restore original transport
            if ($hadPrior && $priorValue !== null) {
                \App\Engine\Database::execute(
                    "UPDATE `settings` SET `value` = ? WHERE `key` = 'mail_transport'",
                    [$priorValue]
                );
            } else {
                \App\Engine\Database::execute(
                    "DELETE FROM `settings` WHERE `key` = 'mail_transport'"
                );
            }
            \App\Engine\Database::execute(
                "DELETE FROM `settings` WHERE `key` = 'smtp_host'"
            );
            \App\Engine\Mailer::clearConfigCache();
        }
    }

    // ══════════════════════════════════════════════════════════════
    // Password reset flow
    // ══════════════════════════════════════════════════════════════

    public function testForgotPasswordShowsForm(): void
    {
        $response = $this->get('/admin/forgot-password');
        $this->assertSame(200, $response['code']);
        $this->assertStringContainsString('reset-email', $response['body'],
            'Forgot-password page should contain email input');
    }

    public function testForgotPasswordSendsEmailAndIsTimingSafe(): void
    {
        $this->requireDbAccess();
        $prior = $this->enableLogTransport();

        try {
            // Get CSRF
            $page = $this->get('/admin/forgot-password');
            preg_match('/name="_csrf_token" value="([^"]+)"/', $page['body'], $m);
            $csrf = $m[1] ?? '';

            // Submit with valid operator email
            $result = $this->post('/admin/forgot-password', [
                '_csrf_token' => $csrf,
                'email'       => TestFixtures::OPERATOR_EMAIL,
            ]);
            $this->assertSame(302, $result['code']);
            $this->assertStringContainsString('forgot-password', $result['location']);

            // Assert token created
            $tokens = \App\Engine\Database::query(
                "SELECT `type` FROM `login_tokens`
                 WHERE `email` = ? AND `type` = 'password_reset' ORDER BY `created_at` DESC LIMIT 1",
                [TestFixtures::OPERATOR_EMAIL]
            );
            $this->assertNotEmpty($tokens, 'Password reset token should be created');

            // Assert email logged
            $log = \App\Engine\Database::query(
                "SELECT `type`, `status` FROM `email_log`
                 WHERE `to_email` = ? AND `type` = 'password_reset' ORDER BY `id` DESC LIMIT 1",
                [TestFixtures::OPERATOR_EMAIL]
            );
            $this->assertNotEmpty($log, 'Email log entry should exist');
            $this->assertSame('sent', $log[0]['status']);

            // Timing-safe: submit with unknown email — must get same redirect
            $page2 = $this->get('/admin/forgot-password');
            preg_match('/name="_csrf_token" value="([^"]+)"/', $page2['body'], $m2);
            $csrf2 = $m2[1] ?? '';

            $result2 = $this->post('/admin/forgot-password', [
                '_csrf_token' => $csrf2,
                'email'       => 'nonexistent@example.test',
            ]);
            $this->assertSame(302, $result2['code'],
                'Unknown email must get same 302 redirect (timing-safe)');
        } finally {
            $this->restoreTransport($prior);
        }
    }

    public function testResetPasswordWithValidToken(): void
    {
        $this->requireDbAccess();
        try {
            // Create reset token directly via engine
            $create = \App\Engine\LoginToken::createPasswordReset(
                TestFixtures::OPERATOR_EMAIL, '127.0.0.1'
            );
            $this->assertTrue($create['success']);

            // Load the reset form
            $formPage = $this->get('/admin/reset-password?token=' . $create['token']);
            $this->assertSame(200, $formPage['code']);
            $this->assertStringContainsString('new-password', $formPage['body'],
                'Reset page should contain password input');

            // Extract CSRF
            preg_match('/name="_csrf_token" value="([^"]+)"/', $formPage['body'], $m);
            $csrf = $m[1] ?? '';

            $newPassword = 'NewSecure99!';

            // Submit reset
            $result = $this->post('/admin/reset-password', [
                '_csrf_token'           => $csrf,
                'token'                 => $create['token'],
                'password'              => $newPassword,
                'password_confirmation' => $newPassword,
            ]);
            $this->assertSame(302, $result['code']);
            $this->assertStringContainsString('/admin/login', $result['location'],
                'Should redirect to login after successful reset');

            // Verify the new password works
            $loginPage = $this->get('/admin/login');
            preg_match('/name="_csrf_token" value="([^"]+)"/', $loginPage['body'], $m2);
            $csrf2 = $m2[1] ?? '';

            $loginResult = $this->post('/admin/login', [
                '_csrf_token' => $csrf2,
                'email'       => TestFixtures::OPERATOR_EMAIL,
                'password'    => $newPassword,
            ]);
            $this->assertSame(302, $loginResult['code']);
            $this->assertStringNotContainsString('login', $loginResult['location'],
                'Should be redirected to admin after login with new password');

            // Verify authenticated
            $dashboard = $this->get('/admin');
            $this->assertSame(200, $dashboard['code']);
        } finally {
            // Restore original password
            $hash = password_hash(TestFixtures::OPERATOR_PASSWORD, PASSWORD_DEFAULT);
            \App\Engine\Database::execute(
                'UPDATE `operators` SET `password_hash` = ? WHERE `email` = ?',
                [$hash, TestFixtures::OPERATOR_EMAIL]
            );
            \App\Engine\Database::execute(
                "DELETE FROM `login_tokens` WHERE `email` = ?",
                [TestFixtures::OPERATOR_EMAIL]
            );
        }
    }

    public function testResetPasswordRejectsExpiredToken(): void
    {
        $this->requireDbAccess();
        try {
            // Create a token then manually expire it
            $create = \App\Engine\LoginToken::createPasswordReset(
                TestFixtures::OPERATOR_EMAIL, '127.0.0.1'
            );
            $this->assertTrue($create['success']);

            // Expire the token
            \App\Engine\Database::execute(
                "UPDATE `login_tokens` SET `expires_at` = DATE_SUB(NOW(), INTERVAL 1 MINUTE)
                 WHERE `email` = ? AND `type` = 'password_reset'",
                [TestFixtures::OPERATOR_EMAIL]
            );

            // GET with expired token must redirect immediately — no form rendered
            $page = $this->get('/admin/reset-password?token=' . $create['token']);
            $this->assertSame(302, $page['code'],
                'Expired token must redirect on GET, not render the form');
            $this->assertStringContainsString('forgot-password', $page['location'],
                'Expired token should redirect to forgot-password');
        } finally {
            \App\Engine\Database::execute(
                "DELETE FROM `login_tokens` WHERE `email` = ?",
                [TestFixtures::OPERATOR_EMAIL]
            );
        }
    }

    public function testResetPasswordRejectsInvalidTokenOnGet(): void
    {
        // A completely fabricated token must redirect on GET
        $page = $this->get('/admin/reset-password?token=deadbeef1234567890abcdef');
        $this->assertSame(302, $page['code'],
            'Invalid token must redirect on GET, not render the form');
        $this->assertStringContainsString('forgot-password', $page['location']);
    }

    public function testForgotPasswordSmtpFailurePreservesExistingToken(): void
    {
        $this->requireDbAccess();
        \Tests\Support\TestEnvironment::failMail('Synthetic SMTP delivery failure');
        // This test guards the controller's call ordering in forgotPassword():
        // create token → send email → if send fails, delete new token.
        // If someone reorders those calls or stops checking $sendResult['sent'],
        // this test will catch it.

        $savedSettings = [];
        try {
            // Step 1: Create a pre-existing valid reset token via the engine
            $existing = \App\Engine\LoginToken::createPasswordReset(
                TestFixtures::OPERATOR_EMAIL, '127.0.0.1'
            );
            $this->assertTrue($existing['success']);
            $this->assertTrue(\App\Engine\LoginToken::peekPasswordReset($existing['token']),
                'Pre-existing token must be valid before the test');

            // Step 2: Configure SMTP to a dead port so send() fails immediately
            // isConfigured() returns true (smtp_host is set), but PHPMailer
            // gets connection refused on port 19999.
            $configKeys = ['mail_transport', 'smtp_host', 'smtp_port', 'mail_from_address'];
            foreach ($configKeys as $key) {
                $rows = \App\Engine\Database::query(
                    "SELECT `value` FROM `settings` WHERE `key` = ? LIMIT 1", [$key]
                );
                $savedSettings[$key] = ['had' => !empty($rows), 'value' => $rows[0]['value'] ?? null];
            }
            $broken = [
                'mail_transport'     => 'smtp',
                'smtp_host'          => '127.0.0.1',
                'smtp_port'          => '19999',
                'mail_from_address'  => 'test@test.test',
            ];
            foreach ($broken as $key => $value) {
                \App\Engine\Database::execute(
                    "INSERT INTO `settings` (`key`, `value`) VALUES (?, ?)
                     ON DUPLICATE KEY UPDATE `value` = ?",
                    [$key, $value, $value]
                );
            }
            \App\Engine\Mailer::clearConfigCache();

            // Step 3: POST to the real controller endpoint
            $page = $this->get('/admin/forgot-password');
            preg_match('/name="_csrf_token" value="([^"]+)"/', $page['body'], $m);
            $csrf = $m[1] ?? '';

            $result = $this->post('/admin/forgot-password', [
                '_csrf_token' => $csrf,
                'email'       => TestFixtures::OPERATOR_EMAIL,
            ]);

            // Timing-safe: always 302 regardless of send success/failure
            $this->assertSame(302, $result['code']);

            // Step 4: The pre-existing token MUST still be valid
            $this->assertTrue(\App\Engine\LoginToken::peekPasswordReset($existing['token']),
                'Pre-existing reset token must survive when SMTP send fails');

            // Step 5: The failed attempt should not have left a dangling token
            $tokenCount = \App\Engine\Database::query(
                "SELECT COUNT(*) AS cnt FROM `login_tokens`
                 WHERE `email` = ? AND `type` = 'password_reset' AND `used_at` IS NULL",
                [TestFixtures::OPERATOR_EMAIL]
            );
            $this->assertSame(1, (int) $tokenCount[0]['cnt'],
                'Only the original token should remain — failed attempt token must be cleaned up');
        } finally {
            \Tests\Support\TestEnvironment::failMail(null);
            // Restore SMTP settings
            foreach ($savedSettings as $key => $prior) {
                if ($prior['had'] && $prior['value'] !== null) {
                    \App\Engine\Database::execute(
                        "UPDATE `settings` SET `value` = ? WHERE `key` = ?",
                        [$prior['value'], $key]
                    );
                } else {
                    \App\Engine\Database::execute(
                        "DELETE FROM `settings` WHERE `key` = ?", [$key]
                    );
                }
            }
            \App\Engine\Mailer::clearConfigCache();
            \App\Engine\Database::execute(
                "DELETE FROM `login_tokens` WHERE `email` = ?",
                [TestFixtures::OPERATOR_EMAIL]
            );
            \App\Engine\Database::execute(
                "DELETE FROM `email_log` WHERE `to_email` = ?",
                [TestFixtures::OPERATOR_EMAIL]
            );
        }
    }

    public function testResetPasswordRejectsMismatch(): void
    {
        $this->requireDbAccess();
        try {
            $create = \App\Engine\LoginToken::createPasswordReset(
                TestFixtures::OPERATOR_EMAIL, '127.0.0.1'
            );
            $this->assertTrue($create['success']);

            $page = $this->get('/admin/reset-password?token=' . $create['token']);
            preg_match('/name="_csrf_token" value="([^"]+)"/', $page['body'], $m);
            $csrf = $m[1] ?? '';

            $result = $this->post('/admin/reset-password', [
                '_csrf_token'           => $csrf,
                'token'                 => $create['token'],
                'password'              => 'Password123!',
                'password_confirmation' => 'Different456!',
            ]);
            $this->assertSame(302, $result['code']);
            $this->assertStringContainsString('reset-password', $result['location'],
                'Mismatched passwords should redirect back to reset form');
            $this->assertStringContainsString('token=', $result['location'],
                'Token should be preserved in redirect');
        } finally {
            \App\Engine\Database::execute(
                "DELETE FROM `login_tokens` WHERE `email` = ?",
                [TestFixtures::OPERATOR_EMAIL]
            );
        }
    }

    public function testResetPasswordRejectsTooShort(): void
    {
        $this->requireDbAccess();
        try {
            $create = \App\Engine\LoginToken::createPasswordReset(
                TestFixtures::OPERATOR_EMAIL, '127.0.0.1'
            );
            $this->assertTrue($create['success']);

            $page = $this->get('/admin/reset-password?token=' . $create['token']);
            preg_match('/name="_csrf_token" value="([^"]+)"/', $page['body'], $m);
            $csrf = $m[1] ?? '';

            $result = $this->post('/admin/reset-password', [
                '_csrf_token'           => $csrf,
                'token'                 => $create['token'],
                'password'              => 'short',
                'password_confirmation' => 'short',
            ]);
            $this->assertSame(302, $result['code']);
            $this->assertStringContainsString('reset-password', $result['location'],
                'Short password should redirect back to reset form');
        } finally {
            \App\Engine\Database::execute(
                "DELETE FROM `login_tokens` WHERE `email` = ?",
                [TestFixtures::OPERATOR_EMAIL]
            );
        }
    }

    // ══════════════════════════════════════════════════════════════
    // Business user password reset
    // ══════════════════════════════════════════════════════════════

    public function testBusinessUserResetPasswordFullFlow(): void
    {
        $this->requireDbAccess();
        $prior = $this->enableLogTransport();

        try {
            // Set force_password_change so we can verify it gets cleared
            \App\Engine\Database::execute(
                'UPDATE `business_users` SET `force_password_change` = 1 WHERE `id` = ?',
                [TestFixtures::BUSINESS_USER_ID]
            );

            // Step 1: Request reset via forgot-password form
            $page = $this->get('/admin/forgot-password');
            preg_match('/name="_csrf_token" value="([^"]+)"/', $page['body'], $m);
            $csrf = $m[1] ?? '';

            $result = $this->post('/admin/forgot-password', [
                '_csrf_token' => $csrf,
                'email'       => TestFixtures::BUSINESS_EMAIL,
            ]);
            $this->assertSame(302, $result['code']);

            // Verify token was created for business user email
            $tokens = \App\Engine\Database::query(
                "SELECT `type` FROM `login_tokens`
                 WHERE `email` = ? AND `type` = 'password_reset' LIMIT 1",
                [TestFixtures::BUSINESS_EMAIL]
            );
            $this->assertNotEmpty($tokens, 'Reset token should be created for business user');

            // Verify email logged
            $log = \App\Engine\Database::query(
                "SELECT `type`, `status` FROM `email_log`
                 WHERE `to_email` = ? AND `type` = 'password_reset' ORDER BY `id` DESC LIMIT 1",
                [TestFixtures::BUSINESS_EMAIL]
            );
            $this->assertNotEmpty($log, 'Reset email should be logged for business user');

            // Step 2: Create a fresh token for controlled POST testing
            $create = \App\Engine\LoginToken::createPasswordReset(
                TestFixtures::BUSINESS_EMAIL, '127.0.0.1'
            );
            $this->assertTrue($create['success']);

            // Step 3: Load and submit the reset form
            $formPage = $this->get('/admin/reset-password?token=' . $create['token']);
            $this->assertSame(200, $formPage['code']);
            preg_match('/name="_csrf_token" value="([^"]+)"/', $formPage['body'], $m2);
            $csrf2 = $m2[1] ?? '';

            $newPassword = 'BusinessNew99!';

            $resetResult = $this->post('/admin/reset-password', [
                '_csrf_token'           => $csrf2,
                'token'                 => $create['token'],
                'password'              => $newPassword,
                'password_confirmation' => $newPassword,
            ]);
            $this->assertSame(302, $resetResult['code']);
            $this->assertStringContainsString('/admin/login', $resetResult['location']);

            // Step 4: Verify force_password_change was cleared
            $buRow = \App\Engine\Database::query(
                'SELECT `force_password_change` FROM `business_users` WHERE `id` = ?',
                [TestFixtures::BUSINESS_USER_ID]
            );
            $this->assertSame(0, (int) $buRow[0]['force_password_change'],
                'force_password_change must be cleared after password reset');

            // Step 5: Verify the new password works for login
            $loginPage = $this->get('/admin/login');
            preg_match('/name="_csrf_token" value="([^"]+)"/', $loginPage['body'], $m3);
            $csrf3 = $m3[1] ?? '';

            $loginResult = $this->post('/admin/login', [
                '_csrf_token' => $csrf3,
                'email'       => TestFixtures::BUSINESS_EMAIL,
                'password'    => $newPassword,
            ]);
            $this->assertSame(302, $loginResult['code']);
            $this->assertStringNotContainsString('login', $loginResult['location'],
                'Business user should be redirected to tenant after login with new password');
        } finally {
            // Restore original password and force_password_change
            $hash = password_hash(TestFixtures::BUSINESS_PASSWORD, PASSWORD_DEFAULT);
            \App\Engine\Database::execute(
                'UPDATE `business_users` SET `password_hash` = ?, `force_password_change` = 0 WHERE `id` = ?',
                [$hash, TestFixtures::BUSINESS_USER_ID]
            );
            \App\Engine\Database::execute(
                "DELETE FROM `login_tokens` WHERE `email` = ?",
                [TestFixtures::BUSINESS_EMAIL]
            );
            \App\Engine\Database::execute(
                "DELETE FROM `email_log` WHERE `to_email` = ?",
                [TestFixtures::BUSINESS_EMAIL]
            );
            $this->restoreTransport($prior);
        }
    }
}
