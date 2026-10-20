<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Engine\Database;
use App\Engine\Locale;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AdminComponentsOutputTest extends TestCase
{
    private array $localeState = [];
    private array $sessionState;
    private \ReflectionProperty $connection;
    private mixed $previousConnection;

    protected function setUp(): void
    {
        foreach ((new \ReflectionClass(Locale::class))->getProperties(\ReflectionProperty::IS_STATIC) as $property) {
            $this->localeState[$property->getName()] = $property->getValue();
        }
        Locale::reset();
        Locale::init(dirname(__DIR__, 2));
        Locale::setSystemDefaults([]);
        Locale::setTenantOverrides(['time_format' => '12h']);
        $this->sessionState = $_SESSION ?? [];
        $_SESSION = ['auth_type' => 'operator', 'auth_id' => 'operator'];
        $this->connection = new \ReflectionProperty(Database::class, 'pdo');
        $this->previousConnection = $this->connection->getValue();
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $pdo->exec('CREATE TABLE settings (`key` TEXT, value TEXT)');
        $this->connection->setValue(null, $pdo);
    }

    protected function tearDown(): void
    {
        foreach ($this->localeState as $name => $value) {
            (new \ReflectionProperty(Locale::class, $name))->setValue(null, $value);
        }
        $this->connection->setValue(null, $this->previousConnection);
        $_SESSION = $this->sessionState;
    }

    public function test_shared_header_escapes_business_metadata_and_groups_actions(): void
    {
        $path = dirname(__DIR__, 2) . '/templates/admin/partials/page-header.php';
        $this->assertFileExists($path, 'Admin pages need one header contract for their title, back navigation and actions');
        $pageHeader = [
            'title' => 'Edit <resource>', 'subtitle' => 'Rooms & stays',
            'backUrl' => '/admin/tenants/ours/resources?name="test"', 'backLabel' => 'Resources',
            'actions' => '<button type="button">Archive</button>',
        ];
        ob_start();
        include $path;
        $html = ob_get_clean();
        $this->assertStringContainsString('Edit &lt;resource&gt;', $html);
        $this->assertStringContainsString('Rooms &amp; stays', $html);
        $this->assertStringContainsString('name=&quot;test&quot;', $html);
        $xpath = $this->xpath($html);
        $this->assertSame(1, $xpath->query('//div[contains(@class,"vb-page-actions")]/button')->length);
        $this->assertSame(1, $xpath->query('//a[contains(@class,"vb-back-link")]')->length);
    }

    #[DataProvider('catalogForms')]
    public function test_catalog_forms_use_the_same_back_navigation(string $path, string $catalog): void
    {
        $xpath = $this->xpath($this->render($path));
        $this->assertSame(1, $xpath->query('//div[contains(@class,"vb-page-header")]//a[contains(@class,"vb-back-link") and @href="/admin/tenants/ours/' . $catalog . '"]')->length);
        $this->assertSame(0, $xpath->query('//h2[contains(@class,"vb-page-title")]//i')->length, 'Page identity uses text; action controls carry contextual icons');
    }

    public static function catalogForms(): array
    {
        return [
            ['tenants/services/create.php', 'services'], ['tenants/services/edit.php', 'services'],
            ['tenants/resources/create.php', 'resources'], ['tenants/resources/edit.php', 'resources'],
            ['tenants/events/create.php', 'events'], ['tenants/events/edit.php', 'events'],
            ['tenants/staff/create.php', 'staff'], ['tenants/staff/edit.php', 'staff'],
        ];
    }

    public function test_event_list_groups_its_primary_action_and_localizes_display_time(): void
    {
        $html = $this->render('tenants/events/index.php', ['events' => [[
            'id' => 'event', 'name' => 'Workshop', 'location' => '', 'is_recurring' => 0,
            'start_datetime' => '2026-10-21 14:00:00', 'end_datetime' => '2026-10-21 15:00:00',
            'booked_count' => 0, 'max_participants' => 10, 'waitlist_count' => 0, 'is_active' => 1,
        ]]]);
        $this->assertSame(1, $this->xpath($html)->query('//div[contains(@class,"vb-page-actions")]/a[@id="btn-new-event"]')->length);
        $this->assertStringContainsString('2:00 PM', $html);
        $this->assertStringNotContainsString('14:00', $html);
    }

    public function test_operator_tenant_settings_has_one_current_navigation_item(): void
    {
        $html = $this->render('layout.php', ['activePage' => 'settings', 'pageTitle' => 'Settings']);
        $xpath = $this->xpath($html);
        $this->assertSame(1, $xpath->query('//aside//a[@aria-current="page" and @href="/admin/tenants/ours/settings"]')->length);
        $this->assertSame(1, $xpath->query('//aside//a[@aria-current="page"]')->length);
    }

    public function test_operator_tenant_navigation_distinguishes_system_and_business_settings(): void
    {
        $xpath = $this->xpath($this->render('layout.php', ['activePage' => 'settings']));
        $this->assertSame('Settings', trim($xpath->query('//aside//a[@href="/admin/tenants/ours/settings"]')->item(0)->textContent));
        $this->assertSame('System settings', trim($xpath->query('//aside//a[@href="/admin/settings"]')->item(0)->textContent));
        $this->assertSame(1, $xpath->query('//aside//div[@class="vb-sidebar-section-label" and normalize-space()="Workspace"]')->length);
    }

    public function test_resource_date_cells_show_the_stay_dates_instead_of_midnight_times(): void
    {
        $path = dirname(__DIR__, 2) . '/templates/admin/partials/booking-datetime.php';
        $this->assertFileExists($path);
        $bookingDateTime = ['booking_pattern' => 'resource', 'start_datetime' => '2026-10-22 00:00:00', 'end_datetime' => '2026-10-25 00:00:00'];
        ob_start();
        include $path;
        $html = ob_get_clean();
        $this->assertStringContainsString(\App\Engine\Locale::date(new \DateTimeImmutable('2026-10-22')), $html);
        $this->assertStringContainsString(\App\Engine\Locale::date(new \DateTimeImmutable('2026-10-25')), $html);
        $this->assertStringNotContainsString('12:00 AM', $html);
        $this->assertStringContainsString('Check-in', $html);
        $this->assertStringContainsString('Check-out', $html);
    }

    public function test_booking_card_descriptions_belong_to_their_title_row(): void
    {
        $xpath = $this->xpath($this->render('tenants/bookings/create.php'));
        $this->assertSame(1, $xpath->query('(//div[@class="vb-card-header"])[1]/div[@class="vb-card-title-row"]//div[@class="vb-card-desc"]')->length);
        $this->assertSame(0, $xpath->query('//div[@class="vb-card-header"]/div[@class="vb-card-desc"]')->length);

        $event = $this->xpath($this->render('tenants/bookings/create-event.php'));
        $this->assertSame(1, $event->query('(//div[@class="vb-card-header"])[1]/div[@class="vb-card-title-row"]/div[@class="vb-card-title"]')->length);
        $this->assertSame(0, $event->query('(//div[@class="vb-card-header"])[1]//div[@class="vb-card-desc"]')->length, 'Title-only cards do not need an empty subtitle spacer');
    }

    public function test_profile_uses_a_role_label_and_escapes_the_person_name_once(): void
    {
        $_SESSION = ['auth_type' => 'business_user', 'auth_id' => 'manager', 'auth_tenant_id' => 'ours', 'auth_role' => 'manager'];
        $html = $this->render('layout.php', ['user' => ['type' => 'business_user', 'role' => 'manager', 'name' => 'Anne & Co']]);
        $xpath = $this->xpath($html);
        $this->assertSame('Manager', trim($xpath->query('//div[@class="vb-profile-dropdown-role"]')->item(0)->textContent));
        $this->assertSame('Anne & Co', trim($xpath->query('//div[@class="vb-profile-dropdown-name"]')->item(0)->textContent));
        $this->assertSame(0, $xpath->query('//aside//a[@href="/admin/settings"]')->length);
    }

    public function test_common_dashboard_and_creation_labels_use_sentence_case(): void
    {
        foreach (['admin.dashboard.bookings_today' => 'Bookings today', 'admin.bookings.new_booking' => 'New booking',
            'admin.services.new' => 'New service', 'admin.resources.new' => 'New resource',
            'admin.events.new_event' => 'New event'] as $key => $expected) {
            $this->assertSame($expected, __($key));
        }
    }

    private function render(string $path, array $overrides = []): string
    {
        extract(array_merge([
            'user' => ['name' => 'Operator', 'type' => 'operator'], 'csrfToken' => 'test', 'version' => 'test',
            'tenantId' => 'ours', 'tenant' => ['id' => 'ours', 'name' => 'Our business', 'slug' => 'our-business', 'booking_pattern' => 'event'],
            'service' => ['id' => 'service', 'name' => 'Consultation'], 'resource' => ['id' => 'resource', 'name' => 'Room'],
            'event' => ['id' => 'event', 'name' => 'Workshop', 'is_recurring' => 0, 'exception_dates' => null, 'max_participants' => 10, 'allow_waitlist' => 0, 'waitlist_max' => 0, 'start_datetime' => '2026-10-21 14:00:00', 'end_datetime' => '2026-10-21 15:00:00'],
            'member' => ['id' => 'staff', 'name' => 'Staff', 'is_active' => 1],
            'services' => [], 'resources' => [], 'staff' => [], 'events' => [], 'linkedServiceIds' => [],
            'linkedStaffIds' => [], 'seasonalPricing' => [], 'flash' => null, 'old' => [],
        ], $overrides));
        $level = ob_get_level();
        ob_start();
        try {
            include dirname(__DIR__, 2) . '/templates/admin/' . $path;
            $output = ob_get_clean();
        } finally {
            while (ob_get_level() > $level) ob_end_clean();
        }
        return $content ?? $output;
    }

    private function xpath(string $html): \DOMXPath
    {
        $dom = new \DOMDocument();
        @$dom->loadHTML($html);
        return new \DOMXPath($dom);
    }
}
