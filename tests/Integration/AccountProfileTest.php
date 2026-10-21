<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Engine\Database;
use App\Engine\EnvLoader;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for the /admin/account profile-edit surface.
 *
 * Covers:
 * - Operator profile update (name + email) with session refresh
 * - Business-user profile update (separate user type, same surface)
 * - Email uniqueness rejection (duplicate email across auth_emails)
 * - auth_emails registry sync on email change
 * - Validation: empty name, invalid email
 * - Password change (success + incorrect current password)
 * - Session/header refresh: after profile save, GET /admin/account
 *   should render the updated name/email values in the form
 */
final class AccountProfileTest extends TestCase
{
    private static bool $appReachable = false;
    private string $baseUrl;
    private string $cookieJar;

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
            // best-effort
        }
    }

    protected function setUp(): void
    {
        if (!self::$appReachable) {
            $this->markTestSkipped('App not reachable');
        }

        $this->baseUrl = rtrim($_ENV['APP_TEST_URL'] ?? 'https://voxelbooking-app.test', '/');
        $this->cookieJar = tempnam(sys_get_temp_dir(), 'vb_acct_test_') ?: '/tmp/vb_acct_test_cookies';
    }

    protected function tearDown(): void
    {
        if (isset($this->cookieJar) && file_exists($this->cookieJar)) {
            unlink($this->cookieJar);
        }

        // Restore operator fixture to canonical state after each test
        // so name/email mutations don't leak across tests
        try {
            $hash = password_hash(TestFixtures::OPERATOR_PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]);
            Database::execute(
                "UPDATE `operators` SET `name` = 'Test Operator', `email` = ? WHERE `id` = ?",
                [TestFixtures::OPERATOR_EMAIL, TestFixtures::OPERATOR_ID]
            );
            Database::execute(
                "UPDATE `auth_emails` SET `email` = ? WHERE `user_type` = 'operator' AND `user_id` = ?",
                [TestFixtures::OPERATOR_EMAIL, TestFixtures::OPERATOR_ID]
            );

            Database::execute(
                "UPDATE `business_users` SET `name` = 'Test Business User', `email` = ? WHERE `id` = ?",
                [TestFixtures::BUSINESS_EMAIL, TestFixtures::BUSINESS_USER_ID]
            );
            Database::execute(
                "UPDATE `auth_emails` SET `email` = ? WHERE `user_type` = 'business_user' AND `user_id` = ?",
                [TestFixtures::BUSINESS_EMAIL, TestFixtures::BUSINESS_USER_ID]
            );
        } catch (\Throwable) {
            // best-effort restore
        }
    }

    // ════════════════════════════════════════════════════════════════
    // Operator: profile update
    // ════════════════════════════════════════════════════════════════

    public function test_operator_can_update_name(): void
    {
        $this->doLoginOperator();
        $csrf = $this->getCsrf('/admin/account');

        $r = $this->post('/admin/account', [
            '_csrf_token' => $csrf,
            '_action'     => 'profile',
            'name'        => 'Updated Operator',
            'email'       => TestFixtures::OPERATOR_EMAIL,
        ]);

        $this->assertSame(302, $r['code'], 'Profile save should redirect');
        $this->assertStringContainsString('/admin/account', $r['location']);

        // Follow redirect and confirm name is reflected in the form
        $page = $this->get('/admin/account');
        $this->assertSame(200, $page['code']);
        $this->assertStringContainsString('Updated Operator', $page['body'],
            'Updated name should appear on the refreshed account page');

        // Verify database
        $rows = Database::query(
            "SELECT `name` FROM `operators` WHERE `id` = ?", [TestFixtures::OPERATOR_ID]
        );
        $this->assertSame('Updated Operator', $rows[0]['name']);
    }

    public function test_operator_can_update_email(): void
    {
        $this->doLoginOperator();
        $csrf = $this->getCsrf('/admin/account');

        $newEmail = 'updated-operator@example.com';

        $r = $this->post('/admin/account', [
            '_csrf_token' => $csrf,
            '_action'     => 'profile',
            'name'        => 'Test Operator',
            'email'       => $newEmail,
        ]);

        $this->assertSame(302, $r['code']);

        // Verify database: operators table
        $rows = Database::query(
            "SELECT `email` FROM `operators` WHERE `id` = ?", [TestFixtures::OPERATOR_ID]
        );
        $this->assertSame($newEmail, $rows[0]['email']);

        // Verify auth_emails registry was synced
        $authRows = Database::query(
            "SELECT `email` FROM `auth_emails` WHERE `user_type` = 'operator' AND `user_id` = ?",
            [TestFixtures::OPERATOR_ID]
        );
        $this->assertNotEmpty($authRows, 'auth_emails should exist for updated operator');
        $this->assertSame($newEmail, $authRows[0]['email'],
            'auth_emails should reflect the new email');

        // Verify session refresh: the page should show the new email
        $page = $this->get('/admin/account');
        $this->assertStringContainsString($newEmail, $page['body'],
            'Refreshed page should show updated email in form');
    }

    public function test_operator_header_reflects_name_change_immediately(): void
    {
        $this->doLoginOperator();
        $csrf = $this->getCsrf('/admin/account');

        $this->post('/admin/account', [
            '_csrf_token' => $csrf,
            '_action'     => 'profile',
            'name'        => 'Jony Ive',
            'email'       => TestFixtures::OPERATOR_EMAIL,
        ]);

        // The sidebar/header with the profile trigger should show "Jony Ive"
        $page = $this->get('/admin');
        $this->assertSame(200, $page['code']);
        $this->assertStringContainsString('Jony Ive', $page['body'],
            'Dashboard header should reflect updated operator name immediately via session');
    }

    // ════════════════════════════════════════════════════════════════
    // Business user: profile update
    // ════════════════════════════════════════════════════════════════

    public function test_business_user_can_update_profile(): void
    {
        $this->doLoginBusinessUser();
        $csrf = $this->getCsrf('/admin/account');

        $r = $this->post('/admin/account', [
            '_csrf_token' => $csrf,
            '_action'     => 'profile',
            'name'        => 'Updated Business User',
            'email'       => TestFixtures::BUSINESS_EMAIL,
        ]);

        $this->assertSame(302, $r['code']);

        // Verify database: business_users table
        $rows = Database::query(
            "SELECT `name` FROM `business_users` WHERE `id` = ?", [TestFixtures::BUSINESS_USER_ID]
        );
        $this->assertSame('Updated Business User', $rows[0]['name']);

        // Verify session refresh
        $page = $this->get('/admin/account');
        $this->assertStringContainsString('Updated Business User', $page['body']);
    }

    // ════════════════════════════════════════════════════════════════
    // Validation: uniqueness, empty name, invalid email
    // ════════════════════════════════════════════════════════════════

    public function test_profile_update_rejects_duplicate_email(): void
    {
        $this->doLoginOperator();
        $csrf = $this->getCsrf('/admin/account');

        // Try to set operator email to the business user's email
        $r = $this->post('/admin/account', [
            '_csrf_token' => $csrf,
            '_action'     => 'profile',
            'name'        => 'Test Operator',
            'email'       => TestFixtures::BUSINESS_EMAIL,
        ]);

        $this->assertSame(302, $r['code']);

        // Follow redirect — should see error flash
        $page = $this->get('/admin/account');
        $this->assertStringContainsString('already in use', $page['body'],
            'Duplicate email should show error flash');

        // Verify operator email unchanged
        $rows = Database::query(
            "SELECT `email` FROM `operators` WHERE `id` = ?", [TestFixtures::OPERATOR_ID]
        );
        $this->assertSame(TestFixtures::OPERATOR_EMAIL, $rows[0]['email'],
            'Operator email should not change on duplicate rejection');
    }

    public function test_profile_update_rejects_empty_name(): void
    {
        $this->doLoginOperator();
        $csrf = $this->getCsrf('/admin/account');

        $r = $this->post('/admin/account', [
            '_csrf_token' => $csrf,
            '_action'     => 'profile',
            'name'        => '',
            'email'       => TestFixtures::OPERATOR_EMAIL,
        ]);

        $this->assertSame(302, $r['code']);

        $page = $this->get('/admin/account');
        $this->assertStringContainsString('required', $page['body'],
            'Empty name should show validation error');
    }

    public function test_profile_update_rejects_invalid_email(): void
    {
        $this->doLoginOperator();
        $csrf = $this->getCsrf('/admin/account');

        $r = $this->post('/admin/account', [
            '_csrf_token' => $csrf,
            '_action'     => 'profile',
            'name'        => 'Test Operator',
            'email'       => 'not-an-email',
        ]);

        $this->assertSame(302, $r['code']);

        $page = $this->get('/admin/account');
        $this->assertStringContainsString('valid email', $page['body'],
            'Invalid email should show validation error');
    }

    // ════════════════════════════════════════════════════════════════
    // Password change flows
    // ════════════════════════════════════════════════════════════════

    public function test_password_change_rejects_incorrect_current(): void
    {
        $this->doLoginOperator();
        $csrf = $this->getCsrf('/admin/account');

        $r = $this->post('/admin/account', [
            '_csrf_token'      => $csrf,
            '_action'          => 'password',
            'current_password' => 'wrongpassword',
            'new_password'     => 'newsecurepass',
            'confirm_password' => 'newsecurepass',
        ]);

        $this->assertSame(302, $r['code']);

        $page = $this->get('/admin/account');
        $this->assertStringContainsString('incorrect', $page['body'],
            'Wrong current password should show error');
    }

    public function test_password_change_rejects_mismatch(): void
    {
        $this->doLoginOperator();
        $csrf = $this->getCsrf('/admin/account');

        $r = $this->post('/admin/account', [
            '_csrf_token'      => $csrf,
            '_action'          => 'password',
            'current_password' => TestFixtures::OPERATOR_PASSWORD,
            'new_password'     => 'newsecurepass1',
            'confirm_password' => 'newsecurepass2',
        ]);

        $this->assertSame(302, $r['code']);

        $page = $this->get('/admin/account');
        $this->assertStringContainsString('do not match', $page['body'],
            'Mismatched passwords should show error');
    }

    public function test_password_change_rejects_short_password(): void
    {
        $this->doLoginOperator();
        $csrf = $this->getCsrf('/admin/account');

        $r = $this->post('/admin/account', [
            '_csrf_token'      => $csrf,
            '_action'          => 'password',
            'current_password' => TestFixtures::OPERATOR_PASSWORD,
            'new_password'     => 'short',
            'confirm_password' => 'short',
        ]);

        $this->assertSame(302, $r['code']);

        $page = $this->get('/admin/account');
        $this->assertStringContainsString('8 characters', $page['body'],
            'Short password should show min-length error');
    }

    // ════════════════════════════════════════════════════════════════
    // Account page contract: editable form, not read-only
    // ════════════════════════════════════════════════════════════════

    public function test_account_page_renders_editable_profile_form(): void
    {
        $this->doLoginOperator();
        $r = $this->get('/admin/account');

        $this->assertSame(200, $r['code']);

        // Editable profile form markers
        $this->assertStringContainsString('name="name"', $r['body'],
            'Account page should have editable name input');
        $this->assertStringContainsString('name="email"', $r['body'],
            'Account page should have editable email input');
        $this->assertStringContainsString('_action', $r['body'],
            'Account page should have action discriminator');
        $this->assertStringContainsString('Save profile', $r['body'],
            'Account page should have Save profile button');

        // Should NOT have settings tabs (standalone surface)
        $this->assertStringNotContainsString('vb-tabs', $r['body'],
            'Account page should not render settings tab bar');
    }

    public function test_account_page_renders_password_form(): void
    {
        $this->doLoginOperator();
        $r = $this->get('/admin/account');

        $this->assertSame(200, $r['code']);
        $this->assertStringContainsString('name="current_password"', $r['body']);
        $this->assertStringContainsString('name="new_password"', $r['body']);
        $this->assertStringContainsString('name="confirm_password"', $r['body']);
        $this->assertStringContainsString('Update password', $r['body']);
    }

    // ── Helpers ──

    private function doLoginOperator(): void
    {
        $response = $this->get('/admin/login');
        preg_match('/name="_csrf_token" value="([^"]+)"/', $response['body'], $m);
        $csrf = $m[1] ?? '';

        $this->post('/admin/login', [
            'email'       => TestFixtures::OPERATOR_EMAIL,
            'password'    => TestFixtures::OPERATOR_PASSWORD,
            '_csrf_token' => $csrf,
        ]);
    }

    private function doLoginBusinessUser(): void
    {
        $response = $this->get('/admin/login');
        preg_match('/name="_csrf_token" value="([^"]+)"/', $response['body'], $m);
        $csrf = $m[1] ?? '';

        $this->post('/admin/login', [
            'email'       => TestFixtures::BUSINESS_EMAIL,
            'password'    => TestFixtures::BUSINESS_PASSWORD,
            '_csrf_token' => $csrf,
        ]);
    }

    private function getCsrf(string $path): string
    {
        $r = $this->get($path);
        if (preg_match('/name="_csrf_token"\s+value="([^"]+)"/', $r['body'], $m)) {
            return $m[1];
        }
        return '';
    }

    /**
     * @return array{code: int, body: string, location: string}
     */
    private function get(string $path): array
    {
        $ch = curl_init($this->baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIEJAR      => $this->cookieJar,
            CURLOPT_COOKIEFILE     => $this->cookieJar,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADER         => true,
        ]);
        $response = (string) curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        unset($ch);

        $body = substr($response, $headerSize);
        $headers = substr($response, 0, $headerSize);
        $location = '';
        if (preg_match('/^Location:\s*(.+)$/mi', $headers, $m)) {
            $location = trim($m[1]);
        }

        return compact('code', 'body', 'location');
    }

    private function post(string $path, array $data): array
    {
        if($path==='/admin/account'&&($data['_action']??'profile')==='profile'&&!isset($data['expected_version'])) {
            $form=$this->get($path);
            if(preg_match('/name="expected_version"\s+value="([^"]+)"/',$form['body'],$match)) { $data['expected_version']=$match[1]; }
        }
        $ch = curl_init($this->baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($data),
            CURLOPT_COOKIEJAR      => $this->cookieJar,
            CURLOPT_COOKIEFILE     => $this->cookieJar,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADER         => true,
        ]);
        $response = (string) curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        unset($ch);

        $body = substr($response, $headerSize);
        $headers = substr($response, 0, $headerSize);
        $location = '';
        if (preg_match('/^Location:\s*(.+)$/mi', $headers, $m)) {
            $location = trim($m[1]);
        }

        return compact('code', 'body', 'location');
    }
}
