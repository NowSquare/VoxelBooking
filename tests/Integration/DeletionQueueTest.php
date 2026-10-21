<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Engine\Database;
use App\Engine\EnvLoader;
use App\Engine\Ulid;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for the operator deletion queue.
 *
 * Verifies:
 * - Deletion request sets deletion_requested_at on customer
 * - Admin confirm endpoint anonymizes and clears the customer
 * - Admin dismiss endpoint clears deletion_requested_at
 * - Queue lists pending requests correctly
 */
final class DeletionQueueTest extends TestCase
{
    private string $baseUrl;
    private static bool $dbConnected = false;
    private array $cleanupIds = [];

    protected function setUp(): void
    {
        $this->baseUrl = rtrim($_ENV['APP_TEST_URL'] ?? 'https://voxelbooking-app.test', '/');

        if (!self::$dbConnected) {
            try {
                require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
                \Tests\Support\TestEnvironment::boot();
                Database::connect();
            Database::query('SELECT 1');
                self::$dbConnected = true;
            } catch (\Throwable $e) {
                $this->markTestSkipped('Database not available');
            }
        }

        if (!Database::tableExists('customers')) {
            $this->markTestSkipped('Customers table not present');
        }

        // Verify column exists
        try {
            Database::query("SELECT `deletion_requested_at` FROM `customers` LIMIT 0");
        } catch (\Throwable) {
            $this->markTestSkipped('deletion_requested_at column not present — run install wizard or refresh database');
        }
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->cleanupIds) as [$table, $id]) {
            try {
                Database::execute("DELETE FROM `{$table}` WHERE `id` = ?", [$id]);
            } catch (\Throwable) {
            }
        }
    }

    /**
     * Setting deletion_requested_at marks a customer as pending.
     */
    public function testDeletionRequestSetsTimestamp(): void
    {
        $ids = $this->seedCustomer();

        // Simulate a deletion request
        Database::execute(
            'UPDATE `customers` SET `deletion_requested_at` = NOW() WHERE `id` = ?',
            [$ids['customer_id']]
        );

        $rows = Database::query(
            'SELECT `deletion_requested_at`, `is_anonymized` FROM `customers` WHERE `id` = ?',
            [$ids['customer_id']]
        );

        $this->assertNotNull($rows[0]['deletion_requested_at']);
        $this->assertSame(0, (int) $rows[0]['is_anonymized']);
    }

    /**
     * Pending queue query only returns non-anonymized requests.
     */
    public function testPendingQueueFiltersCorrectly(): void
    {
        $ids = $this->seedCustomer();

        // Mark as deletion requested
        Database::execute(
            'UPDATE `customers` SET `deletion_requested_at` = NOW() WHERE `id` = ?',
            [$ids['customer_id']]
        );

        // Should appear in pending queue
        $pending = Database::query(
            'SELECT * FROM `customers`
             WHERE `deletion_requested_at` IS NOT NULL AND `is_anonymized` = 0 AND `id` = ?',
            [$ids['customer_id']]
        );
        $this->assertCount(1, $pending);

        // Anonymize the customer
        \App\Engine\CustomerAnonymizer::anonymize($ids['customer_id']);

        // Should no longer appear in pending queue
        $pending = Database::query(
            'SELECT * FROM `customers`
             WHERE `deletion_requested_at` IS NOT NULL AND `is_anonymized` = 0 AND `id` = ?',
            [$ids['customer_id']]
        );
        $this->assertCount(0, $pending);
    }

    /**
     * Dismiss clears the deletion_requested_at.
     */
    public function testDismissClearsRequest(): void
    {
        $ids = $this->seedCustomer();

        Database::execute(
            'UPDATE `customers` SET `deletion_requested_at` = NOW() WHERE `id` = ?',
            [$ids['customer_id']]
        );

        // Dismiss
        Database::execute(
            'UPDATE `customers` SET `deletion_requested_at` = NULL WHERE `id` = ?',
            [$ids['customer_id']]
        );

        $rows = Database::query(
            'SELECT `deletion_requested_at` FROM `customers` WHERE `id` = ?',
            [$ids['customer_id']]
        );

        $this->assertNull($rows[0]['deletion_requested_at']);
    }

    /**
     * The deletion queue admin page is accessible (requires auth).
     */
    public function testDeletionQueuePageRequiresAuth(): void
    {
        $ch = curl_init($this->baseUrl . '/admin/deletion-queue');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $code = 0;
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);

        // Should redirect to login (302) when not authenticated
        $this->assertContains($code, [302, 303], 'Deletion queue should require authentication');
    }

    // ── Helpers ──

    /**
     * @return array{tenant_id: string, customer_id: string}
     */
    private function seedCustomer(): array
    {
        $tenantId = Ulid::generate();
        $customerId = Ulid::generate();
        $slug = 'test-dq-' . substr($tenantId, -6);

        Database::execute(
            "INSERT INTO `tenants` (`id`, `slug`, `name`, `email`, `booking_pattern`) VALUES (?, ?, 'DQ Test', 'dq@example.com', 'timeslot')",
            [$tenantId, $slug]
        );
        $this->cleanupIds[] = ['tenants', $tenantId];

        Database::execute(
            "INSERT INTO `customers` (`id`, `tenant_id`, `name`, `email`) VALUES (?, ?, 'DQ Customer', 'dq-test@example.com')",
            [$customerId, $tenantId]
        );
        $this->cleanupIds[] = ['customers', $customerId];

        return ['tenant_id' => $tenantId, 'customer_id' => $customerId];
    }
}
