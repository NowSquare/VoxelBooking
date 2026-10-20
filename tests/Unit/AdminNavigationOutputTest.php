<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Engine\Database;
use App\Engine\Locale;
use PHPUnit\Framework\TestCase;

final class AdminNavigationOutputTest extends TestCase
{
    private \ReflectionProperty $connection;
    private mixed $originalConnection;
    private array $originalSession;
    private array $localeState = [];

    protected function setUp(): void
    {
        foreach ((new \ReflectionClass(Locale::class))->getProperties(\ReflectionProperty::IS_STATIC) as $property) {
            $this->localeState[$property->getName()] = $property->getValue();
        }
        Locale::reset();
        Locale::init(dirname(__DIR__, 2));
        $this->originalSession = $_SESSION ?? [];
        $_SESSION = ['auth_type' => 'operator', 'auth_id' => 'operator'];
        $this->connection = new \ReflectionProperty(Database::class, 'pdo');
        $this->originalConnection = $this->connection->getValue();
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $pdo->exec('CREATE TABLE tenants (id TEXT, name TEXT, slug TEXT, booking_pattern TEXT)');
        $pdo->exec("INSERT INTO tenants VALUES ('ours', 'Our Salon', 'our-salon', 'timeslot')");
        $pdo->exec('CREATE TABLE settings (`key` TEXT, value TEXT)');
        $this->connection->setValue(null, $pdo);
    }

    protected function tearDown(): void
    {
        foreach ($this->localeState as $name => $value) {
            (new \ReflectionProperty(Locale::class, $name))->setValue(null, $value);
        }
        $this->connection->setValue(null, $this->originalConnection);
        $_SESSION = $this->originalSession;
    }

    public function test_direct_operator_business_dashboard_retains_business_context(): void
    {
        $html = $this->render('layout.php', ['tenant' => ['id' => 'ours', 'name' => 'Our Salon', 'slug' => 'our-salon', 'booking_pattern' => 'timeslot']]);
        $this->assertStringContainsString('href="/admin/tenants/ours/bookings"', $html);
        $this->assertStringContainsString('href="/admin/tenants/ours/services"', $html);
        $this->assertStringContainsString('vb-sidebar-context', $html);
        $this->assertStringContainsString('aria-controls="admin-sidebar"', $html);
        $this->assertStringNotContainsString('name="redirect_to"', $html);
    }

    public function test_manager_navigation_does_not_expose_configuration(): void
    {
        $_SESSION = ['auth_type' => 'business_user', 'auth_id' => 'manager', 'auth_tenant_id' => 'ours', 'auth_role' => 'manager'];
        $html = $this->render('layout.php', ['tenant' => ['id' => 'ours', 'name' => 'Our Salon', 'booking_pattern' => 'timeslot']]);
        $this->assertStringContainsString('href="/admin/tenants/ours/bookings"', $html);
        $this->assertStringNotContainsString('href="/admin/tenants/ours/services"', $html);
        $this->assertStringNotContainsString('href="/admin/tenants/ours/settings"', $html);
        $this->assertStringNotContainsString('href="/admin/settings"', $html);
    }

    public function test_partial_tenant_data_does_not_remove_pattern_specific_navigation(): void
    {
        $html = $this->render('layout.php', ['tenantId' => 'ours', 'activePage' => 'calendar',
            'tenant' => ['id' => 'ours', 'name' => 'Our Salon']]);
        foreach (['services', 'staff', 'availability', 'blocked-dates'] as $path) {
            $this->assertStringContainsString('href="/admin/tenants/ours/' . $path . '"', $html);
        }
        $this->assertStringContainsString('href="/book/our-salon"', $html);
    }

    public function test_business_name_is_management_entry_and_secondary_actions_have_labels(): void
    {
        $html = $this->render('tenants/index.php', [
            'tenants' => [['id' => 'ours', 'name' => 'Our Salon', 'slug' => 'our-salon', 'status' => 'active', 'booking_pattern' => 'timeslot', 'booking_count' => 2, 'service_count' => 1]],
            'counts' => ['total' => 1], 'filters' => [], 'flash' => null,
        ]);
        $dom = new \DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new \DOMXPath($dom);
        $this->assertSame(1, $xpath->query('//a[@href="/admin/tenants/ours" and contains(., "Our Salon")]')->length);
        $this->assertSame(1, $xpath->query('//a[@href="/book/our-salon" and contains(., "View booking page")]')->length);
        $this->assertSame(1, $xpath->query('//details/summary[contains(., "Our Salon")]')->length);
        $this->assertSame(1, $xpath->query('//details[@data-row-actions]/summary[@aria-haspopup="menu" and @aria-expanded="false"]')->length);
        $this->assertSame(1, $xpath->query('//details//div[@role="menu" and @hidden]')->length);
        $this->assertSame(1, $xpath->query('//th[contains(@class,"vb-text-right") and contains(.,"Actions")]')->length);
        $this->assertSame(1, $xpath->query('//details//form[@action="/admin/tenants/ours/impersonate"]//button[contains(., "Manage as business")]')->length);
        $this->assertSame(1, $xpath->query('//details//form[@action="/admin/tenants/ours/archive" and @data-confirm]')->length);
        $this->assertStringContainsString('name="_csrf_token" value="test-csrf"', $html);
        $this->assertStringNotContainsString('vb-sidebar-context', $html, 'An operator list loop must not become a selected business');
    }

    private function render(string $template, array $data): string
    {
        extract(array_merge(['user' => ['name' => 'Operator', 'type' => 'operator'], 'version' => 'test', 'csrfToken' => 'test-csrf'], $data));
        ob_start();
        include dirname(__DIR__, 2) . '/templates/admin/' . $template;
        return ob_get_clean();
    }
}
