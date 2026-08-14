<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Engine\Database;
use App\Engine\EnvLoader;
use PHPUnit\Framework\TestCase;

/**
 * Credential persistence for the admin email settings form.
 *
 * These tests submit the form the way a browser does: every enabled control
 * inside the form element, in document order, with the values the server
 * rendered. That is what exposed the original defect, where the SMTP password
 * and the Resend API key shared a name and only the last one survived.
 *
 * Reads the field names out of the rendered HTML instead of hardcoding them,
 * so the test keeps describing what a real browser posts.
 */
final class EmailSettingsCredentialTest extends TestCase
{
    private const MAIL_KEYS = [
        'mail_transport',
        'smtp_host',
        'smtp_port',
        'smtp_username',
        'smtp_password',
        'smtp_encryption',
        'mail_from_address',
        'mail_from_name',
    ];

    private static string $baseUrl;
    private static bool $ready = false;
    private static string $setupError = '';
    private static string $cookie = '';

    /** @var array<string, string|null> Mail settings as they were before the suite ran. */
    private static array $savedSettings = [];

    public static function setUpBeforeClass(): void
    {
        self::$baseUrl = rtrim($_ENV['APP_TEST_URL'] ?? 'https://voxelbooking-app.test', '/');

        $health = self::http('GET', '/health');
        if ($health['code'] === 0) {
            self::$setupError = 'App not reachable at ' . self::$baseUrl;
            return;
        }

        // Demo mode locks system settings, so DemoMiddleware turns every save
        // here into a redirect and the suite would test the guard, not the form.
        if (file_exists(dirname(__DIR__, 2) . '/.demo')) {
            self::$setupError = 'Demo mode is active — system settings are locked';
            return;
        }

        try {
            require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
            EnvLoader::load(dirname(__DIR__, 2) . '/.env');
            Database::connect();
            Database::query('SELECT 1');
        } catch (\Throwable $e) {
            self::$setupError = 'DB init failed: ' . $e->getMessage();
            return;
        }

        try {
            Database::execute('TRUNCATE TABLE `rate_limits`');
        } catch (\Throwable) {}

        try {
            TestFixtures::provision();
            self::rememberSettings();
            self::loginOperator();
        } catch (\Throwable $e) {
            self::$setupError = 'Provisioning failed: ' . $e->getMessage();
            return;
        }

        self::$ready = self::$cookie !== '';
        if (!self::$ready) {
            self::$setupError = 'Operator login failed';
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::restoreSettings();
    }

    protected function setUp(): void
    {
        if (!self::$ready) {
            $this->markTestSkipped(self::$setupError ?: 'Email settings suite not ready');
        }

        try {
            Database::execute('TRUNCATE TABLE `rate_limits`');
        } catch (\Throwable) {}
    }

    public function testSmtpPasswordSurvivesTheSubmission(): void
    {
        $password = 'smtp-secret-' . bin2hex(random_bytes(4));

        $response = $this->submitEmailForm([
            'mail_transport' => 'smtp',
            'smtp_host'      => 'smtp.example.com',
            'smtp_port'      => '587',
            'smtp_username'  => 'noreply@example.com',
            'smtp_password'  => $password,
        ]);

        $this->assertSame(302, $response['code'], 'Saving email settings should redirect');
        $this->assertSame($password, $this->setting('smtp_password'));
        $this->assertSame('smtp.example.com', $this->setting('smtp_host'));
    }

    public function testResendApiKeySurvivesTheSubmission(): void
    {
        $apiKey = 're_test_' . bin2hex(random_bytes(4));

        $response = $this->submitEmailForm([
            'mail_transport'  => 'resend',
            'resend_api_key'  => $apiKey,
        ]);

        $this->assertSame(302, $response['code']);
        $this->assertSame($apiKey, $this->setting('smtp_password'));
    }

    /**
     * The password field renders empty on every page load, so an operator who
     * edits the host alone must not lose the credential they stored earlier.
     */
    public function testBlankPasswordLeavesTheStoredCredentialAlone(): void
    {
        $password = 'smtp-keep-' . bin2hex(random_bytes(4));

        $this->submitEmailForm([
            'mail_transport' => 'smtp',
            'smtp_host'      => 'smtp.example.com',
            'smtp_port'      => '587',
            'smtp_password'  => $password,
        ]);

        $this->submitEmailForm([
            'mail_transport' => 'smtp',
            'smtp_host'      => 'smtp2.example.com',
            'smtp_port'      => '2525',
        ]);

        $this->assertSame($password, $this->setting('smtp_password'));
        $this->assertSame('smtp2.example.com', $this->setting('smtp_host'));
    }

    /**
     * The audit trail records that the credential changed without recording
     * the credential itself (.ai/23 §5).
     */
    public function testAuditTrailNeverStoresTheCredential(): void
    {
        $password = 'smtp-audit-' . bin2hex(random_bytes(4));

        $this->submitEmailForm([
            'mail_transport' => 'smtp',
            'smtp_host'      => 'smtp.example.com',
            'smtp_port'      => '587',
            'smtp_password'  => $password,
        ]);

        $rows = Database::query(
            "SELECT `details` FROM `audit_log` WHERE `action` = 'settings.updated' ORDER BY `created_at` DESC LIMIT 5"
        );

        $this->assertNotEmpty($rows, 'Saving email settings must write an audit entry');
        foreach ($rows as $row) {
            $this->assertStringNotContainsString($password, (string) ($row['details'] ?? ''));
        }
    }

    // ════════════════════════════════════════════════════════════════
    // Form submission
    // ════════════════════════════════════════════════════════════════

    /**
     * Fetch the form, apply the given values by control name, then post every
     * enabled control in document order.
     *
     * @param array<string, string> $values
     * @return array{code: int, headers: string, body: string}
     */
    private function submitEmailForm(array $values): array
    {
        $page = self::http('GET', '/admin/settings/email', null, self::$cookie);
        $this->assertSame(200, $page['code'], 'Email settings page should load for the operator');

        $transport = $values['mail_transport'] ?? 'smtp';
        $body = $this->serializeForm($page['body'], $values, $transport);

        $this->assertStringContainsString('_csrf_token=', $body, 'Form must carry a CSRF token');

        return self::http('POST', '/admin/settings/email', $body, self::$cookie);
    }

    /**
     * Serialize the email form like a browser: named, enabled controls only,
     * in document order, including controls the transport toggle hides.
     *
     * Values are keyed by element id, because an operator types into one
     * control. Keying them by name would fill every control sharing that name
     * and hide the collision this suite exists to catch.
     *
     * @param array<string, string> $values
     */
    private function serializeForm(string $html, array $values, string $transport): string
    {
        if (!preg_match('/<form\b[^>]*action="\/admin\/settings\/email".*?<\/form>/is', $html, $form)) {
            $this->fail('Email settings form not found in the response');
        }

        $pairs = [];
        preg_match_all('/<(input|select)\b[^>]*>/i', $form[0], $controls);

        foreach ($controls[0] as $control) {
            if (!preg_match('/\bname="([^"]+)"/i', $control, $nameMatch)) {
                continue;
            }
            if (preg_match('/\btype="(submit|button)"/i', $control)) {
                continue;
            }

            // A disabled fieldset excludes its controls from the submission,
            // which is how the browser drops the inactive transport's fields.
            if (!$this->controlIsEnabled($form[0], $control, $transport)) {
                continue;
            }

            $name = $nameMatch[1];
            $id = preg_match('/\bid="([^"]+)"/i', $control, $idMatch) ? $idMatch[1] : '';

            $value = array_key_exists($id, $values)
                ? $values[$id]
                : $this->renderedValue($control);

            $pairs[] = urlencode($name) . '=' . urlencode($value);
        }

        return implode('&', $pairs);
    }

    /**
     * A control is submitted unless it sits inside a disabled fieldset. The
     * server renders the inactive transport's fieldset disabled, and the
     * toggle script keeps that in step with the dropdown.
     */
    private function controlIsEnabled(string $form, string $control, string $transport): bool
    {
        if (preg_match('/\bdisabled\b/i', $control)) {
            return false;
        }

        $position = strpos($form, $control);
        if ($position === false) {
            return true;
        }

        $before = substr($form, 0, $position);
        $openFieldsets = preg_match_all('/<fieldset\b[^>]*>/i', $before, $opens);
        $closedFieldsets = substr_count(strtolower($before), '</fieldset>');

        if ($openFieldsets <= $closedFieldsets) {
            return true;
        }

        $enclosing = $opens[0][$openFieldsets - 1];

        // The browser re-enables the fieldset matching the chosen transport
        // before the operator can type into it.
        if (preg_match('/\bdata-transport="([^"]+)"/i', $enclosing, $owner)) {
            return $owner[1] === $transport;
        }

        return !preg_match('/\bdisabled\b/i', $enclosing);
    }

    private function renderedValue(string $control): string
    {
        if (preg_match('/^<select/i', $control)) {
            return '';
        }

        return preg_match('/\bvalue="([^"]*)"/i', $control, $value) ? html_entity_decode($value[1]) : '';
    }

    // ════════════════════════════════════════════════════════════════
    // Settings
    // ════════════════════════════════════════════════════════════════

    private function setting(string $key): ?string
    {
        $rows = Database::query('SELECT `value` FROM `settings` WHERE `key` = ? LIMIT 1', [$key]);

        return $rows === [] ? null : (string) $rows[0]['value'];
    }

    private static function rememberSettings(): void
    {
        foreach (self::MAIL_KEYS as $key) {
            $rows = Database::query('SELECT `value` FROM `settings` WHERE `key` = ? LIMIT 1', [$key]);
            self::$savedSettings[$key] = $rows === [] ? null : (string) $rows[0]['value'];
        }
    }

    private static function restoreSettings(): void
    {
        if (self::$savedSettings === []) {
            return;
        }

        foreach (self::$savedSettings as $key => $value) {
            try {
                if ($value === null) {
                    Database::execute('DELETE FROM `settings` WHERE `key` = ?', [$key]);
                } else {
                    Database::upsertSetting($key, $value);
                }
            } catch (\Throwable) {}
        }
    }

    // ════════════════════════════════════════════════════════════════
    // HTTP
    // ════════════════════════════════════════════════════════════════

    private static function loginOperator(): void
    {
        $page = self::http('GET', '/admin/login');
        if (!preg_match('/vb_session=([^;]+)/', $page['headers'], $session)) {
            return;
        }
        $cookie = 'vb_session=' . $session[1];

        if (!preg_match('/name="_csrf_token"\s+value="([^"]+)"/', $page['body'], $csrf)) {
            return;
        }

        $body = http_build_query([
            '_csrf_token' => $csrf[1],
            'email'       => TestFixtures::OPERATOR_EMAIL,
            'password'    => TestFixtures::OPERATOR_PASSWORD,
        ]);

        $res = self::http('POST', '/admin/login', $body, $cookie);
        if (preg_match('/vb_session=([^;]+)/', $res['headers'], $session)) {
            $cookie = 'vb_session=' . $session[1];
        }

        self::$cookie = $cookie;
    }

    /** @return array{code: int, headers: string, body: string} */
    private static function http(string $method, string $path, ?string $body = null, string $cookie = ''): array
    {
        $ch = curl_init(self::$baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADER         => true,
        ]);

        $headers = [];
        if ($cookie !== '') {
            $headers[] = 'Cookie: ' . $cookie;
        }
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, (string) $body);
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        }
        if ($headers !== []) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        $response = (string) curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);

        return [
            'code'    => $code,
            'headers' => substr($response, 0, $headerSize),
            'body'    => substr($response, $headerSize),
        ];
    }
}
