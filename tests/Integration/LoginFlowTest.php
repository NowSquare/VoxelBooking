<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Integration tests for the login flow.
 *
 * Verifies:
 * - Failed login preserves the submitted email in the form
 * - Error message is shown after failed login
 */
final class LoginFlowTest extends TestCase
{
    private string $baseUrl;

    protected function setUp(): void
    {
        $this->baseUrl = rtrim($_ENV['APP_TEST_URL'] ?? 'https://voxelbooking-app.test', '/');
    }

    /**
     * After a failed login, the email field is repopulated with the submitted address.
     */
    public function testFailedLoginPreservesEmail(): void
    {
        $cookieJar = tempnam(sys_get_temp_dir(), 'vb_login_test_');

        try {
            // Step 1: GET the login page to obtain CSRF token
            $ch = curl_init($this->baseUrl . '/admin/login');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_COOKIEJAR      => $cookieJar,
                CURLOPT_COOKIEFILE     => $cookieJar,
            ]);
            $loginPage = curl_exec($ch);
            unset($ch);

            if ($loginPage === false) {
                $this->markTestSkipped('App not reachable (curl_exec failed)');
            }

            preg_match('/name="_csrf_token"\s+value="([^"]+)"/', $loginPage, $m);
            $csrf = $m[1] ?? '';
            $this->assertNotEmpty($csrf, 'CSRF token must be present on login page');

            // Step 2: POST bad credentials
            $testEmail = 'preserved-test-' . time() . '@example.com';
            $ch = curl_init($this->baseUrl . '/admin/login');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => http_build_query([
                    '_csrf_token' => $csrf,
                    'email'       => $testEmail,
                    'password'    => 'definitelywrong123',
                ]),
                CURLOPT_COOKIEJAR      => $cookieJar,
                CURLOPT_COOKIEFILE     => $cookieJar,
                CURLOPT_FOLLOWLOCATION => true,
            ]);
            $result = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            unset($ch);

            if ($result === false) {
                $this->markTestSkipped('App not reachable (curl_exec failed on POST)');
            }

            // Step 3: Verify the redirected page contains the email in the input field
            $this->assertSame(200, $code, 'Should follow redirect back to login page');
            $this->assertStringContainsString($testEmail, $result, 'Email must be preserved in form after failed login');
            $this->assertStringContainsString('value="' . htmlspecialchars($testEmail, ENT_QUOTES, 'UTF-8') . '"', $result, 'Email must appear as input value attribute');
        } finally {
            @unlink($cookieJar);
        }
    }

    /**
     * After a failed login, an error message is displayed.
     */
    public function testFailedLoginShowsError(): void
    {
        $cookieJar = tempnam(sys_get_temp_dir(), 'vb_login_test_');

        try {
            $ch = curl_init($this->baseUrl . '/admin/login');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_COOKIEJAR      => $cookieJar,
                CURLOPT_COOKIEFILE     => $cookieJar,
            ]);
            $loginPage = curl_exec($ch);
            unset($ch);

            if ($loginPage === false) {
                $this->markTestSkipped('App not reachable (curl_exec failed)');
            }

            preg_match('/name="_csrf_token"\s+value="([^"]+)"/', $loginPage, $m);
            $csrf = $m[1] ?? '';

            $ch = curl_init($this->baseUrl . '/admin/login');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => http_build_query([
                    '_csrf_token' => $csrf,
                    'email'       => 'error-test@example.com',
                    'password'    => 'wrongpassword123',
                ]),
                CURLOPT_COOKIEJAR      => $cookieJar,
                CURLOPT_COOKIEFILE     => $cookieJar,
                CURLOPT_FOLLOWLOCATION => true,
            ]);
            $result = curl_exec($ch);
            unset($ch);

            if ($result === false) {
                $this->markTestSkipped('App not reachable (curl_exec failed on POST)');
            }

            // The error message class should be present
            $this->assertStringContainsString('login-error', $result, 'Error indicator must be present after failed login');
        } finally {
            @unlink($cookieJar);
        }
    }
}
