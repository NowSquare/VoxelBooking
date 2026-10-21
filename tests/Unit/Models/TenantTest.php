<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Engine\Database;
use App\Engine\EnvLoader;
use App\Models\Tenant;
use PHPUnit\Framework\TestCase;

/**
 * DB-backed unit tests for the Tenant model.
 *
 * Connects to the DB directly (not HTTP-over-curl).
 * Seeds test tenants in setUpBeforeClass(), cleans up in tearDownAfterClass().
 * Skips gracefully if DB is unavailable.
 */
final class TenantTest extends TestCase
{
    private static bool $dbReady = false;

    /** @var list<string> Tenant IDs seeded by this class */
    private static array $seededIds = [];

    /** @var list<string> Tenant IDs created by create() during tests */
    private static array $createdIds = [];

    public static function setUpBeforeClass(): void
    {
        try {
            require_once dirname(__DIR__, 3) . '/vendor/autoload.php';
            \Tests\Support\TestEnvironment::boot();
            Database::connect();
            Database::query('SELECT 1');
            self::$dbReady = true;
        } catch (\Throwable) {
            return;
        }

        // Seed two tenants: one active, one archived
        self::seedTenant('01TTEST0ACME00000000000A', 'test-acme-model', 'Acme Corp Test');
        self::seedTenant('01TTEST0BETA00000000000B', 'test-beta-model', 'Beta LLC Test', 'archived');
    }

    protected function setUp(): void
    {
        if (!self::$dbReady) {
            $this->markTestSkipped('Database not available');
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (!self::$dbReady) {
            return;
        }

        $allIds = array_merge(self::$seededIds, self::$createdIds);
        foreach ($allIds as $id) {
            try {
                Database::execute('DELETE FROM `tenants` WHERE `id` = ?', [$id]);
            } catch (\Throwable) {
                // best-effort
            }
        }

        self::$seededIds = [];
        self::$createdIds = [];
    }

    // ════════════════════════════════════════════════════════════════
    // Tenant::all()
    // ════════════════════════════════════════════════════════════════

    public function test_all_returns_active_tenants(): void
    {
        $tenants = Tenant::all();

        // Filter to our test tenants only (other tests/seeds may exist)
        $ours = array_filter($tenants, fn($t) => in_array($t['id'], self::$seededIds, true));
        $slugs = array_column($ours, 'slug');

        $this->assertContains('test-acme-model', $slugs, 'Active tenant should appear');
        $this->assertNotContains('test-beta-model', $slugs, 'Archived tenant should NOT appear');
    }

    public function test_all_includes_archived_when_requested(): void
    {
        $tenants = Tenant::all(includeArchived: true);

        $ours = array_filter($tenants, fn($t) => in_array($t['id'], self::$seededIds, true));
        $slugs = array_column($ours, 'slug');

        $this->assertContains('test-acme-model', $slugs);
        $this->assertContains('test-beta-model', $slugs);
        $this->assertCount(2, $ours, 'Both seeded tenants should appear');
    }

    // ════════════════════════════════════════════════════════════════
    // Tenant::find()
    // ════════════════════════════════════════════════════════════════

    public function test_find_returns_tenant_by_id(): void
    {
        $tenant = Tenant::find('01TTEST0ACME00000000000A');

        $this->assertNotNull($tenant);
        $this->assertSame('test-acme-model', $tenant['slug']);
        $this->assertSame('Acme Corp Test', $tenant['name']);
    }

    public function test_find_returns_null_for_missing(): void
    {
        $tenant = Tenant::find('01NONEXISTENT00000000000');

        $this->assertNull($tenant);
    }

    // ════════════════════════════════════════════════════════════════
    // Tenant::create()
    // ════════════════════════════════════════════════════════════════

    public function test_create_inserts_tenant(): void
    {
        $id = Tenant::create([
            'name' => 'Created Biz Test',
            'slug' => 'test-created-biz-model',
            'email' => 'created@test-model.com',
            'booking_pattern' => 'timeslot',
        ]);
        self::$createdIds[] = $id;

        $this->assertNotEmpty($id);
        $this->assertSame(26, strlen($id), 'ID should be a 26-char ULID');

        $tenant = Tenant::find($id);
        $this->assertNotNull($tenant);
        $this->assertSame('Created Biz Test', $tenant['name']);
        $this->assertSame('test-created-biz-model', $tenant['slug']);
        $this->assertSame('active', $tenant['status']);
        $this->assertSame('timeslot', $tenant['booking_pattern']);
    }

    // ════════════════════════════════════════════════════════════════
    // Tenant::update()
    // ════════════════════════════════════════════════════════════════

    public function test_update_modifies_tenant(): void
    {
        Tenant::update('01TTEST0ACME00000000000A', ['name' => 'Acme Inc Test']);

        $tenant = Tenant::find('01TTEST0ACME00000000000A');
        $this->assertSame('Acme Inc Test', $tenant['name']);

        // Restore
        Tenant::update('01TTEST0ACME00000000000A', ['name' => 'Acme Corp Test']);
    }

    // ════════════════════════════════════════════════════════════════
    // Tenant::slugExists()
    // ════════════════════════════════════════════════════════════════

    public function test_slug_exists_checks_uniqueness(): void
    {
        $this->assertTrue(Tenant::slugExists('test-acme-model'));
        $this->assertFalse(Tenant::slugExists('test-nonexistent-slug-xyz'));
    }

    public function test_slug_exists_excludes_given_id(): void
    {
        // Own slug should not show as taken when excluding own ID
        $this->assertFalse(
            Tenant::slugExists('test-acme-model', excludeId: '01TTEST0ACME00000000000A')
        );

        // But should still be detected for a different ID
        $this->assertTrue(
            Tenant::slugExists('test-acme-model', excludeId: '01TTEST0BETA00000000000B')
        );
    }

    // ════════════════════════════════════════════════════════════════
    // Tenant::counts()
    // ════════════════════════════════════════════════════════════════

    public function test_counts_returns_totals(): void
    {
        $counts = Tenant::counts();

        // Minimum: our seeded tenants (other tenants may exist from other tests)
        $this->assertGreaterThanOrEqual(1, $counts['active']);
        $this->assertGreaterThanOrEqual(1, $counts['archived']);
        $this->assertGreaterThanOrEqual(2, $counts['total']);
        $this->assertSame($counts['active'] + $counts['archived'] + ($counts['paused'] ?? 0), $counts['total']);
    }

    // ════════════════════════════════════════════════════════════════
    // Tenant::bookingCount() / serviceCount()
    // ════════════════════════════════════════════════════════════════

    public function test_booking_count_returns_zero_for_empty_tenant(): void
    {
        $count = Tenant::bookingCount('01TTEST0ACME00000000000A');
        $this->assertSame(0, $count);
    }

    public function test_service_count_returns_zero_for_empty_tenant(): void
    {
        $count = Tenant::serviceCount('01TTEST0ACME00000000000A');
        $this->assertSame(0, $count);
    }

    // ════════════════════════════════════════════════════════════════
    // Seed helper
    // ════════════════════════════════════════════════════════════════

    private static function seedTenant(
        string $id,
        string $slug,
        string $name,
        string $status = 'active',
    ): void {
        Database::execute(
            "INSERT INTO `tenants` (`id`, `slug`, `name`, `email`, `booking_pattern`, `status`)
             VALUES (?, ?, ?, ?, 'timeslot', ?)",
            [$id, $slug, $name, "{$slug}@test-model.com", $status]
        );
        self::$seededIds[] = $id;
    }
}
