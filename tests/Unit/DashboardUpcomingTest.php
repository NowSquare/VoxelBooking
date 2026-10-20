<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Engine\Database;
use App\Engine\Locale;
use App\Engine\Request;
use App\Engine\View;
use App\Controllers\Admin\DashboardController;
use App\Models\Booking;
use PHPUnit\Framework\TestCase;

final class DashboardUpcomingTest extends TestCase
{
    private \ReflectionProperty $connection;
    private mixed $previousConnection;
    private string $previousTimezone;
    private array $localeState = [];
    private array $sessionState;
    private string $viewPath;

    protected function setUp(): void
    {
        $this->previousTimezone = date_default_timezone_get();
        date_default_timezone_set('UTC');
        $this->sessionState = $_SESSION ?? [];
        $_SESSION = ['auth_type' => 'operator', 'auth_id' => 'operator'];
        foreach ((new \ReflectionClass(Locale::class))->getProperties(\ReflectionProperty::IS_STATIC) as $property) {
            $this->localeState[$property->getName()] = $property->getValue();
        }
        Locale::reset();
        Locale::init(dirname(__DIR__, 2));
        Locale::setSystemDefaults([]);
        $this->viewPath = (new \ReflectionProperty(View::class, 'basePath'))->getValue();
        View::init(dirname(__DIR__, 2) . '/templates');
        $this->connection = new \ReflectionProperty(Database::class, 'pdo');
        $this->previousConnection = $this->connection->getValue();
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $this->connection->setValue(null, $pdo);
        $pdo->exec("CREATE TABLE tenants (id TEXT, name TEXT, slug TEXT, timezone TEXT, status TEXT DEFAULT 'active', booking_pattern TEXT DEFAULT 'resource')");
        $pdo->exec('CREATE TABLE bookings (id TEXT, tenant_id TEXT, customer_id TEXT, service_id TEXT, resource_id TEXT, event_id TEXT, staff_id TEXT, booking_pattern TEXT, status TEXT, start_datetime TEXT, end_datetime TEXT)');
        foreach (['customers (id TEXT, tenant_id TEXT, name TEXT, email TEXT, is_anonymized INT DEFAULT 0)', 'services (id TEXT, name TEXT, color TEXT)', 'resources (id TEXT, name TEXT, tenant_id TEXT, is_active INT)', 'events (id TEXT, name TEXT)', 'staff (id TEXT, name TEXT)', 'settings (`key` TEXT, value TEXT)'] as $table) {
            $pdo->exec('CREATE TABLE ' . $table);
        }
        foreach (['ams' => 'Europe/Amsterdam', 'ny' => 'America/New_York', 'utc' => 'UTC', 'tokyo' => 'Asia/Tokyo'] as $id => $timezone) {
            Database::execute('INSERT INTO tenants (id, name, slug, timezone) VALUES (?, ?, ?, ?)', [$id, $id, $id, $timezone]);
        }
    }

    protected function tearDown(): void
    {
        $this->connection->setValue(null, $this->previousConnection);
        date_default_timezone_set($this->previousTimezone);
        $_SESSION = $this->sessionState;
        foreach ($this->localeState as $name => $value) {
            (new \ReflectionProperty(Locale::class, $name))->setValue(null, $value);
        }
        (new \ReflectionProperty(View::class, 'basePath'))->setValue(null, $this->viewPath);
    }

    public function test_operator_upcoming_excludes_a_past_venue_booking_even_when_its_wall_time_is_after_utc_now(): void
    {
        $this->booking('past', 'ams', '2026-10-20 14:00:00'); // 12:00 UTC
        $this->booking('future', 'ny', '2026-10-20 10:30:00'); // 14:30 UTC
        $rows = Booking::allUpcoming('2026-10-20 13:54:00', 5);
        $this->assertSame(['future'], array_column($rows, 'id'));
    }

    public function test_operator_upcoming_sorts_instants_before_applying_the_final_limit(): void
    {
        $this->booking('later-ams', 'ams', '2026-10-20 16:45:00');
        $this->booking('second-ny', 'ny', '2026-10-20 10:30:00');
        $this->booking('first-tokyo', 'tokyo', '2026-10-20 23:20:00');
        $this->booking('third-utc', 'utc', '2026-10-20 14:40:00');
        $this->booking('pending', 'utc', '2026-10-20 14:00:00', 'pending');
        $rows = Booking::allUpcoming('2026-10-20 13:54:00', 2);
        $this->assertSame(['first-tokyo', 'second-ny'], array_column($rows, 'id'));
    }

    public function test_operator_upcoming_has_a_stable_tiebreak_and_a_flexible_bound(): void
    {
        $this->booking('b', 'ams', '2026-10-20 16:30:00');
        $this->booking('a', 'ny', '2026-10-20 10:30:00');
        for ($i = 0; $i < 8; $i++) $this->booking('later-' . $i, 'utc', '2026-10-21 10:00:00');
        $this->assertSame(['a', 'b'], array_column(Booking::allUpcoming('2026-10-20 13:54:00', 2), 'id'));
        $this->assertCount(7, Booking::allUpcoming('2026-10-20 13:54:00', 7));
    }

    public function test_upcoming_24h_counts_confirmed_venue_instants_including_dst_change(): void
    {
        $this->assertTrue(method_exists(Booking::class, 'countUpcomingBetween'));
        $this->booking('inside', 'ams', '2026-10-25 12:30:00'); // 11:30 UTC, winter time
        $this->booking('outside', 'ams', '2026-10-25 13:30:00'); // 12:30 UTC
        $this->booking('past', 'ams', '2026-10-24 13:30:00'); // 11:30 UTC, summer time
        $this->booking('pending', 'ams', '2026-10-25 12:00:00', 'pending');
        $this->assertSame(1, Booking::countUpcomingBetween('2026-10-24 12:00:00', '2026-10-25 12:00:00'));
    }

    public function test_operator_dashboard_preview_is_bounded_to_five_rows(): void
    {
        $tomorrow = (new \DateTimeImmutable('tomorrow', new \DateTimeZone('UTC')))->format('Y-m-d');
        for ($i = 0; $i < 8; $i++) $this->booking('booking-' . $i, 'utc', $tomorrow . ' 10:00:00');
        $html = (new DashboardController())->index(new Request())->getBody();
        $dom = new \DOMDocument();
        @$dom->loadHTML($html);
        $this->assertSame(5, (new \DOMXPath($dom))->query('//table/tbody/tr')->length);
        $this->assertStringContainsString('href="/admin/bookings"', $html);
    }

    public function test_tenant_dashboard_uses_venue_now_for_the_nearest_booking(): void
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('Asia/Tokyo'));
        $this->booking('past-local', 'tokyo', $now->modify('-1 hour')->format('Y-m-d H:i:s'));
        $this->booking('future-local', 'tokyo', $now->modify('+1 hour')->format('Y-m-d H:i:s'));
        $request = new Request();
        $request->setAttribute('tenant_id', 'tokyo');
        $html = (new DashboardController())->tenantDashboard($request)->getBody();
        $dom = new \DOMDocument();
        @$dom->loadHTML($html);
        $next = (new \DOMXPath($dom))->query('//a[contains(@class,"vb-dashboard-next-name")]')->item(0);
        $this->assertSame('/admin/tenants/tokyo/bookings/future-local', $next?->getAttribute('href'));
        $this->assertStringContainsString(Locale::date($now), $html);
    }

    private function booking(string $id, string $tenantId, string $start, string $status = 'confirmed'): void
    {
        Database::execute('INSERT INTO bookings (id, tenant_id, status, start_datetime, end_datetime) VALUES (?, ?, ?, ?, ?)', [$id, $tenantId, $status, $start, $start]);
    }
}
