<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Engine\Database;
use Tests\Support\TestEnvironment;

/**
 * Shared test fixtures for integration tests.
 *
 * Provisions deterministic operator and business-user accounts via direct
 * database operations. Self-contained: does not depend on install wizard
 * credentials or manual database mutations.
 *
 * Uses DELETE + INSERT to guarantee fresh state on every test run,
 * regardless of pre-existing data.
 *
 * Credentials:
 *   Operator:      operator@example.com / welcome3210
 *   Business user: business@example.com / welcome3210  (tenant-scoped)
 */
final class TestFixtures
{
    /** Submit the same optimistic version a person reads in a rendered catalog form. */
    public static function withCatalogVersion(string $path, array $data, string $cookie): array
    {
        if (array_key_exists('_record_version', $data)) { return $data; }
        if (preg_match('#^(/admin/tenants/[^/]+)/(edit|archive|activate)$#', $path, $match)) {
            $readPath = $match[2] === 'edit' ? $path : '/admin/tenants?status=all';
        } elseif (preg_match('#^/admin/applications/[^/]+/(approve|reject)$#', $path)) {
            $readPath = '/admin/applications?status=all';
        } elseif (preg_match('#^(/admin/tenants/[^/]+/users)/[^/]+/(activate|deactivate)$#', $path, $match)) {
            $readPath = $match[1];
        } elseif (preg_match('#^(/admin/tenants/[^/]+)/(services|staff|resources|events|capacity-slots|blocked-dates|availability)(.*)$#', $path, $parts)) {
            if ($parts[2] !== 'availability' && in_array($parts[3], ['', '/create'], true)) { return $data; }
            $readPath = $parts[1] . '/' . $parts[2];
            if (preg_match('#^/([^/]+)$#', $parts[3]) && in_array($parts[2], ['services', 'staff', 'resources', 'events'], true)) {
                $readPath .= $parts[3] . '/edit';
            } elseif (preg_match('#^/[^/]+/edit$#', $parts[3])) {
                $readPath .= $parts[3];
            } elseif ($parts[2] === 'availability') {
                $readPath .= preg_replace('#/reset$#', '', $parts[3]);
            }
        } else { return $data; }
        $curl = curl_init(rtrim($_ENV['APP_TEST_URL'], '/') . $readPath);
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Cookie: ' . $cookie], CURLOPT_TIMEOUT => 10]);
        $html = curl_exec($curl);
        if (!is_string($html) || $html === '') {
            return $data;
        }
        $document = new \DOMDocument();
        @$document->loadHTML($html);
        foreach ($document->getElementsByTagName('form') as $form) {
            if ($form->getAttribute('action') !== $path) {
                continue;
            }
            foreach ($form->getElementsByTagName('input') as $field) {
                if ($field->getAttribute('name') === '_record_version') {
                    $data['_record_version'] = $field->getAttribute('value');
                    return $data;
                }
            }
        }
        return $data;
    }

    public const OPERATOR_EMAIL = 'operator@example.com';
    public const OPERATOR_PASSWORD = 'welcome3210';
    public const OPERATOR_ID = '01TESTOPERATOR0000000000';

    public const BUSINESS_EMAIL = 'business@example.com';
    public const BUSINESS_PASSWORD = 'welcome3210';
    public const BUSINESS_USER_ID = '01TESTBUSINESSUSR0000000';
    public const BUSINESS_TENANT_ID = '01TESTTENANT000000000000';

    public const CUSTOMER_ID = '01TESTCUSTOMER0000000000';
    public const BOOKING_ID  = '01TESTBOOKING00000000000';

    private static bool $provisioned = false;
    private static bool $dbAvailable = false;

    /**
     * Whether the test process has direct database access.
     *
     * Integration tests that call Database::query() directly in test methods
     * (not just via curl) should check this in setUp() and markTestSkipped
     * when false. This prevents PDOException crashes in sandboxed environments
     * where the web server can reach MySQL but the test process cannot.
     */
    public static function dbAvailable(): bool
    {
        return self::$dbAvailable;
    }

    /**
     * Ensure the test operator, business user, and their tenant exist
     * with exactly the expected credentials and bindings.
     *
     * Deletes any prior records for these IDs/emails before inserting,
     * so stale hashes or tenant bindings are impossible.
     */
    public static function provision(): void
    {
        // Always clean transient tables, even on repeat calls, so later
        // test classes don't inherit stale rate-limit or token rows from
        // earlier classes that share this process.
        require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
        TestEnvironment::assertDatabase();

        try {
            Database::connect();
            Database::query('SELECT 1');
            self::$dbAvailable = true;
        } catch (\Throwable $e) {
            throw new \RuntimeException('Database not available: ' . $e->getMessage(), 0, $e);
        }

        try {
            Database::execute('TRUNCATE TABLE `rate_limits`');
        } catch (\Throwable) {}
        try {
            Database::execute('TRUNCATE TABLE `login_tokens`');
        } catch (\Throwable) {}

        if (self::$provisioned) {
            return;
        }

        $hash = password_hash(self::OPERATOR_PASSWORD, PASSWORD_BCRYPT, ['cost' => 12]);

        // ── Operator: delete by ID and email, then insert fresh ──
        Database::execute(
            "DELETE FROM `operators` WHERE `id` = ? OR `email` = ?",
            [self::OPERATOR_ID, self::OPERATOR_EMAIL]
        );
        Database::execute(
            "INSERT INTO `operators` (`id`, `name`, `email`, `password_hash`)
             VALUES (?, 'Test Operator', ?, ?)",
            [self::OPERATOR_ID, self::OPERATOR_EMAIL, $hash]
        );

        // ── Tenant: delete by ID and slug, then insert fresh ──
        // Business user FK cascades from tenant, so delete tenant first cleans both
        Database::execute(
            "DELETE FROM `business_users` WHERE `id` = ? OR `email` = ?",
            [self::BUSINESS_USER_ID, self::BUSINESS_EMAIL]
        );
        Database::execute(
            "DELETE FROM `tenants` WHERE `id` = ? OR `slug` = 'test-fixture'",
            [self::BUSINESS_TENANT_ID]
        );
        Database::execute(
            "INSERT INTO `tenants`
             (`id`, `name`, `slug`, `email`, `booking_pattern`, `timezone`, `currency`, `brand_color`, `status`)
             VALUES (?, 'Test Tenant', 'test-fixture', ?, 'timeslot', 'UTC', 'EUR', '#2563EB', 'active')",
            [self::BUSINESS_TENANT_ID, self::BUSINESS_EMAIL]
        );

        // ── Business user: insert with known hash and tenant binding ──
        Database::execute(
            "INSERT INTO `business_users`
             (`id`, `tenant_id`, `name`, `email`, `password_hash`, `role`, `is_active`)
             VALUES (?, ?, 'Test Business User', ?, ?, 'owner', 1)",
            [self::BUSINESS_USER_ID, self::BUSINESS_TENANT_ID, self::BUSINESS_EMAIL, $hash]
        );

        // ── Auth emails: clean + insert for both accounts ──
        Database::execute(
            "DELETE FROM `auth_emails` WHERE `email` IN (?, ?)",
            [self::OPERATOR_EMAIL, self::BUSINESS_EMAIL]
        );
        Database::execute(
            "INSERT INTO `auth_emails` (`email`, `user_type`, `user_id`) VALUES (?, 'operator', ?)",
            [self::OPERATOR_EMAIL, self::OPERATOR_ID]
        );
        Database::execute(
            "INSERT INTO `auth_emails` (`email`, `user_type`, `user_id`) VALUES (?, 'business_user', ?)",
            [self::BUSINESS_EMAIL, self::BUSINESS_USER_ID]
        );

        // ── Customer + booking: delete then insert fresh ──
        Database::execute(
            "DELETE FROM `bookings` WHERE `id` = ?",
            [self::BOOKING_ID]
        );
        Database::execute(
            "DELETE FROM `customers` WHERE `id` = ?",
            [self::CUSTOMER_ID]
        );
        Database::execute(
            "INSERT INTO `customers` (`id`, `tenant_id`, `name`, `email`)
             VALUES (?, ?, 'Test Customer', 'customer@example.com')",
            [self::CUSTOMER_ID, self::BUSINESS_TENANT_ID]
        );
        Database::execute(
            "INSERT INTO `bookings`
             (`id`, `tenant_id`, `booking_pattern`, `customer_id`,
              `start_datetime`, `end_datetime`, `status`, `source`)
             VALUES (?, ?, 'timeslot', ?,
              DATE_ADD(CURDATE(), INTERVAL 1 DAY),
              DATE_ADD(DATE_ADD(CURDATE(), INTERVAL 1 DAY), INTERVAL 30 MINUTE),
              'confirmed', 'web')",
            [self::BOOKING_ID, self::BUSINESS_TENANT_ID, self::CUSTOMER_ID]
        );

        self::$provisioned = true;
    }

    /**
     * Get the tenant ID for the business user fixture.
     */
    public static function businessTenantId(): string
    {
        return self::BUSINESS_TENANT_ID;
    }
}
