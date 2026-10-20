<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\Admin\DashboardController;
use App\Engine\Database;
use PHPUnit\Framework\TestCase;

final class AdminDashboardSetupTest extends TestCase
{
    private \ReflectionProperty $connection;
    private mixed $originalConnection;

    protected function setUp(): void
    {
        $this->connection = new \ReflectionProperty(Database::class, 'pdo');
        $this->originalConnection = $this->connection->getValue();
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $this->connection->setValue(null, $pdo);
        foreach (['services (id TEXT, tenant_id TEXT, is_active INT, requires_staff INT)',
            'staff (id TEXT, tenant_id TEXT, is_active INT)',
            'availability (tenant_id TEXT, is_available INT)',
            'resources (tenant_id TEXT, is_active INT)',
            'capacity_slots (tenant_id TEXT, is_active INT)',
            'events (tenant_id TEXT, is_active INT)'] as $table) {
            $pdo->exec('CREATE TABLE ' . $table);
        }
    }

    protected function tearDown(): void
    {
        $this->connection->setValue(null, $this->originalConnection);
    }

    public function test_staffless_services_do_not_generate_staff_setup_warning(): void
    {
        Database::execute("INSERT INTO services VALUES ('s', 'ours', 1, 0)");
        Database::execute("INSERT INTO availability VALUES ('ours', 1)");
        $this->assertSame([], $this->actions('timeslot'));
    }

    public function test_unenforced_staff_flag_does_not_generate_setup_warning(): void
    {
        Database::execute("INSERT INTO services VALUES ('s', 'ours', 1, 1)");
        Database::execute("INSERT INTO staff VALUES ('rival', 'theirs', 1)");
        Database::execute("INSERT INTO availability VALUES ('ours', 1)");
        $this->assertSame([], $this->actions('timeslot'), 'A schema default that does not restrict booking must not look like broken setup');
    }

    public function test_patterns_require_only_their_own_catalog(): void
    {
        $this->assertSame([['key' => 'setup_resources', 'path' => 'resources']], $this->actions('resource'));
        $this->assertSame([['key' => 'setup_capacity', 'path' => 'capacity-slots']], $this->actions('capacity'));
        $this->assertSame([['key' => 'setup_events', 'path' => 'events']], $this->actions('event'));
        $this->assertSame([['key' => 'setup_services', 'path' => 'services']], $this->actions('timeslot'));
    }

    public function test_missing_hours_link_to_availability_without_optional_staff_notice(): void
    {
        Database::execute("INSERT INTO services VALUES ('s', 'ours', 1, 1)");
        Database::execute("INSERT INTO availability VALUES ('theirs', 1)");
        $this->assertSame([['key' => 'setup_hours', 'path' => 'availability']], $this->actions('timeslot'));
    }

    public function test_active_catalog_and_hours_resolve_setup(): void
    {
        Database::execute("INSERT INTO services VALUES ('s', 'ours', 1, 1)");
        Database::execute("INSERT INTO staff VALUES ('staff', 'ours', 1)");
        Database::execute("INSERT INTO availability VALUES ('ours', 1)");
        Database::execute("INSERT INTO resources VALUES ('ours', 1)");
        Database::execute("INSERT INTO capacity_slots VALUES ('ours', 1)");
        Database::execute("INSERT INTO events VALUES ('ours', 1)");
        foreach (['timeslot', 'resource', 'capacity', 'event'] as $pattern) {
            $this->assertSame([], $this->actions($pattern));
        }
    }

    private function actions(string $pattern): array
    {
        $this->assertTrue(method_exists(DashboardController::class, 'loadSetupActions'), 'Dashboard must distinguish required setup from a quiet day');
        return (new \ReflectionMethod(DashboardController::class, 'loadSetupActions'))
            ->invoke(new DashboardController(), 'ours', $pattern);
    }
}
