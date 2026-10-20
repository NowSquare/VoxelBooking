<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Template output tests for dashboard pages.
 *
 * Renders dashboard templates with mock data by extracting the $content
 * variable that they produce via ob_start()/ob_get_clean().
 *
 * The templates end with: $content = ob_get_clean(); include layout.php;
 * We intercept this by wrapping the include in output buffering and
 * discarding the layout output, then reading $content.
 *
 * No HTTP server required.
 */
final class DashboardOutputTest extends TestCase
{
    private string $templateDir;
    private static bool $helpersReady = false;
    private array $localeState = [];

    public static function setUpBeforeClass(): void
    {
        $root = dirname(__DIR__, 2);
        require_once $root . '/vendor/autoload.php';

        if (file_exists($root . '/.env')) {
            \App\Engine\EnvLoader::load($root . '/.env');
        }

        self::$helpersReady = function_exists('__') && function_exists('app_name');
    }

    protected function setUp(): void
    {
        if (!self::$helpersReady) {
            $this->markTestSkipped('Translation/app helpers not available');
        }

        $this->templateDir = dirname(__DIR__, 2) . '/templates';
        foreach ((new \ReflectionClass(\App\Engine\Locale::class))->getProperties(\ReflectionProperty::IS_STATIC) as $property) {
            $this->localeState[$property->getName()] = $property->getValue();
        }
        \App\Engine\Locale::reset();
        \App\Engine\Locale::init(dirname(__DIR__, 2));
        \App\Engine\Locale::setTenantOverrides(['time_format' => '24h']);
    }

    protected function tearDown(): void
    {
        foreach ($this->localeState as $name => $value) {
            (new \ReflectionProperty(\App\Engine\Locale::class, $name))->setValue(null, $value);
        }
    }

    // ════════════════════════════════════════════════════════════════
    // Operator dashboard
    // ════════════════════════════════════════════════════════════════

    public function test_operator_dashboard_renders_metric_delta_up(): void
    {
        $html = $this->renderOperatorDashboard([
            'deltaToday' => 5,
            'deltaWeek' => 3,
            'todayBookings' => 10,
            'weekBookings' => 25,
        ]);

        $this->assertStringContainsString('vb-metric-delta', $html);
        $this->assertStringContainsString('is-up', $html);
        $this->assertStringContainsString('trending-up', $html);
    }

    public function test_operator_dashboard_renders_metric_delta_down(): void
    {
        $html = $this->renderOperatorDashboard([
            'deltaToday' => -3,
            'deltaWeek' => -2,
            'todayBookings' => 5,
            'weekBookings' => 10,
        ]);

        $this->assertStringContainsString('is-down', $html);
        $this->assertStringContainsString('trending-down', $html);
    }

    public function test_operator_dashboard_hides_delta_when_zero(): void
    {
        $html = $this->renderOperatorDashboard([
            'deltaToday' => 0,
            'deltaWeek' => 0,
            'todayBookings' => 5,
            'weekBookings' => 10,
        ]);

        $this->assertStringNotContainsString('vb-metric-delta', $html);
    }

    public function test_operator_dashboard_renders_upcoming_list(): void
    {
        $html = $this->renderOperatorDashboard([
            'upcoming' => [
                [
                    'id' => 'test-booking-id',
                    'customer_id' => 'test-customer-id',
                    'tenant_id' => 'test-tenant-id',
                    'customer_name' => 'Jane Doe',
                    'customer_email' => 'jane@example.com',
                    'service_name' => 'Haircut',
                    'start_datetime' => date('Y-m-d 10:00:00', strtotime('+1 day')),
                    'end_datetime' => date('Y-m-d 10:30:00', strtotime('+1 day')),
                    'status' => 'confirmed',
                    'tenant_name' => 'Test Salon',
                ],
            ],
        ]);

        $this->assertStringContainsString('vb-status', $html);
        $this->assertStringContainsString('vb-status-confirmed', $html);
        $this->assertStringContainsString('Jane Doe', $html);
        $this->assertStringContainsString('Test Salon', $html);
        $this->assertStringContainsString('vb-cell-primary', $html);
        // Customer names use impersonation-starting forms (POST to /impersonate)
        // with redirect_to pointing to the customer page.
        $this->assertStringContainsString('/admin/tenants/test-tenant-id/impersonate', $html);
        $this->assertStringContainsString('redirect_to', $html);
        $this->assertStringContainsString('/admin/tenants/test-tenant-id/customers/test-customer-id', $html);
        $this->assertStringContainsString('vb-btn-reset', $html);
        // Booking detail link is present via eye-icon button
        $this->assertStringContainsString('/admin/bookings/test-booking-id', $html);
    }

    public function test_operator_dashboard_renders_metric_headers(): void
    {
        $html = $this->renderOperatorDashboard();

        $this->assertStringContainsString('vb-metric-header', $html);
        $this->assertStringContainsString('vb-metric-icon', $html);
        $this->assertStringContainsString('vb-metric-value-row', $html);
    }

    // ════════════════════════════════════════════════════════════════
    // Tenant dashboard
    // ════════════════════════════════════════════════════════════════

    public function test_tenant_dashboard_renders_schedule_strip(): void
    {
        $html = $this->renderTenantDashboard([
            'todaySchedule' => [
                [
                    'id' => 'test-id',
                    'start_datetime' => date('Y-m-d 09:00:00'),
                    'end_datetime' => date('Y-m-d 09:30:00'),
                    'status' => 'confirmed',
                    'booking_pattern' => 'timeslot',
                    'staff_id' => null,
                    'customer_name' => 'Test Customer',
                    'customer_email' => 'test@example.com',
                    'service_name' => 'Massage',
                    'service_color' => null,
                    'staff_name' => null,
                ],
            ],
        ]);

        $this->assertStringContainsString('vb-schedule-grid', $html);
        $this->assertStringContainsString('vb-schedule-pill', $html);
        $this->assertStringContainsString('Test Customer', $html);
    }

    public function test_tenant_dashboard_renders_service_color_pills(): void
    {
        $html = $this->renderTenantDashboard([
            'todaySchedule' => [
                [
                    'id' => 'test-id',
                    'start_datetime' => date('Y-m-d 10:00:00'),
                    'end_datetime' => date('Y-m-d 10:30:00'),
                    'status' => 'confirmed',
                    'booking_pattern' => 'timeslot',
                    'staff_id' => null,
                    'customer_name' => 'Color Test',
                    'customer_email' => 'color@test.com',
                    'service_name' => 'Styled Service',
                    'service_color' => '#3B82F6',
                    'staff_name' => null,
                ],
            ],
        ]);

        $this->assertStringContainsString('vb-schedule-service-dot', $html);
        $this->assertStringContainsString('#3B82F6', $html);
    }

    public function test_tenant_dashboard_renders_accent_fallback_pills(): void
    {
        $html = $this->renderTenantDashboard([
            'todaySchedule' => [
                [
                    'id' => 'test-id',
                    'start_datetime' => date('Y-m-d 11:00:00'),
                    'end_datetime' => date('Y-m-d 11:30:00'),
                    'status' => 'confirmed',
                    'booking_pattern' => 'timeslot',
                    'staff_id' => null,
                    'customer_name' => 'Fallback Test',
                    'customer_email' => 'fallback@test.com',
                    'service_name' => 'No Color Service',
                    'service_color' => null,
                    'staff_name' => null,
                ],
            ],
        ]);

        $this->assertStringContainsString('vb-schedule-service-dot', $html);
        $this->assertStringContainsString('var(--vb-accent)', $html);
    }

    public function test_tenant_dashboard_renders_schedule_empty_state(): void
    {
        $html = $this->renderTenantDashboard([
            'todaySchedule' => [],
        ]);

        $this->assertStringNotContainsString('vb-schedule-grid', $html);
        $this->assertStringContainsString('calendar-off', $html);
    }

    public function test_tenant_dashboard_renders_staff_name_on_pill(): void
    {
        $html = $this->renderTenantDashboard([
            'todaySchedule' => [
                [
                    'id' => 'test-id',
                    'start_datetime' => date('Y-m-d 14:00:00'),
                    'end_datetime' => date('Y-m-d 14:30:00'),
                    'status' => 'confirmed',
                    'booking_pattern' => 'timeslot',
                    'staff_id' => 'staff-1',
                    'customer_name' => 'Staff Test',
                    'customer_email' => 'staff@test.com',
                    'service_name' => 'Consultation',
                    'service_color' => null,
                    'staff_name' => 'Dr. Sarah Jones',
                ],
            ],
        ]);

        $this->assertStringContainsString('Dr. Sarah Jones', $html);
        $this->assertStringContainsString('vb-schedule-pill-staff', $html);
    }

    public function test_tenant_dashboard_renders_status_chips_in_next_up(): void
    {
        $html = $this->renderTenantDashboard([
            'upcoming' => [
                [
                    'id' => 'upcoming-test-id',
                    'customer_name' => 'Upcoming Jane',
                    'service_name' => 'Facial',
                    'start_datetime' => date('Y-m-d 15:00:00', strtotime('+1 day')),
                    'end_datetime' => date('Y-m-d 15:30:00', strtotime('+1 day')),
                    'status' => 'confirmed',
                ],
            ],
        ]);

        $this->assertStringContainsString('vb-status vb-status-confirmed', $html);
    }

    public function test_tenant_dashboard_renders_staff_working_today(): void
    {
        $html = $this->renderTenantDashboard([
            'staffWorkingToday' => [
                [
                    'id' => 'staff-1',
                    'name' => 'Alice Stylist',
                    'title' => 'Senior Stylist',
                    'windows' => [['start' => '09:00', 'end' => '17:00']],
                ],
            ],
        ]);

        $this->assertStringContainsString('vb-staff-today-list', $html);
        $this->assertStringContainsString('Alice Stylist', $html);
        $this->assertStringContainsString('Senior Stylist', $html);
        $this->assertStringContainsString('09:00', $html);
        $this->assertStringContainsString('17:00', $html);
        $this->assertStringContainsString('vb-staff-today-avatar', $html);
    }

    public function test_tenant_dashboard_does_not_require_optional_staff(): void
    {
        $html = $this->renderTenantDashboard([
            'staffWorkingToday' => null,
        ]);

        $this->assertStringNotContainsString('staff/create', $html);
        $this->assertStringNotContainsString('vb-dash-staff-today-wrap', $html);
    }

    public function test_tenant_dashboard_hides_staff_for_non_timeslot(): void
    {
        $html = $this->renderTenantDashboard([
            'tenant' => ['id' => 'test-tenant-id', 'name' => 'Test Hotel', 'slug' => 'test-hotel', 'booking_pattern' => 'resource'],
            'staffWorkingToday' => null,
        ]);

        $this->assertStringNotContainsString('vb-dash-staff-today-wrap', $html);
    }

    public function test_business_dashboard_leads_with_work_before_compact_metrics(): void
    {
        $html = $this->renderTenantDashboard(['upcoming' => [$this->booking()]]);
        $this->assertStringContainsString('Test Salon', $html);
        $this->assertStringContainsString('dashboard-date', $html);
        $this->assertLessThan(strpos($html, 'vb-dashboard-metrics'), strpos($html, 'next-booking'));
        $this->assertLessThan(strpos($html, 'vb-dashboard-metrics'), strpos($html, 'schedule-today'));
    }

    public function test_quiet_business_day_has_useful_actions_without_setup_warning(): void
    {
        $html = $this->renderTenantDashboard();
        $this->assertStringContainsString('/bookings/create', $html);
        $this->assertStringContainsString('data-copy-url=', $html);
        $this->assertStringNotContainsString('dashboard-setup', $html);
    }

    public function test_pending_approvals_have_a_filtered_booking_action(): void
    {
        $html = $this->renderTenantDashboard(['pendingApprovals' => 3]);
        $this->assertStringContainsString('/bookings?status=pending', $html);
        $this->assertStringContainsString('Awaiting approval', $html);
    }

    public function test_setup_configuration_actions_are_hidden_from_managers(): void
    {
        $html = $this->renderTenantDashboard([
            'user' => ['name' => 'Manager', 'type' => 'business_user', 'role' => 'manager'],
            'setupActions' => [['key' => 'setup_services', 'path' => 'services']],
        ]);
        $this->assertStringContainsString('dashboard-setup', $html);
        $this->assertStringContainsString('business owner', $html);
        $this->assertStringNotContainsString('/services', $html);
    }

    public function test_required_setup_has_specific_owner_action(): void
    {
        $html = $this->renderTenantDashboard([
            'setupActions' => [['key' => 'setup_resources', 'path' => 'resources']],
        ]);
        $this->assertStringContainsString('dashboard-setup', $html);
        $this->assertStringContainsString('/admin/tenants/test-tenant-id/resources', $html);
    }

    public function test_all_business_dashboard_times_honor_twelve_hour_locale_format(): void
    {
        \App\Engine\Locale::setTenantOverrides(['time_format' => '12h']);
        try {
            $html = $this->renderTenantDashboard([
                'todaySchedule' => [$this->booking()],
                'upcoming' => [$this->booking()],
                'staffWorkingToday' => [['id' => 's', 'name' => 'Staff', 'windows' => [['start' => '14:00', 'end' => '17:00']]]],
            ]);
            $this->assertStringContainsString('2:00 PM', $html);
            $this->assertStringContainsString('2:30 PM', $html);
            $this->assertStringContainsString('5:00 PM', $html);
            $this->assertStringNotContainsString('14:00', $html);
            $this->assertStringNotContainsString('17:00', $html);
        } finally {
            \App\Engine\Locale::setTenantOverrides(['time_format' => '24h']);
        }
    }

    public function test_operator_work_precedes_metrics_and_times_use_locale(): void
    {
        \App\Engine\Locale::setTenantOverrides(['time_format' => '12h']);
        try {
            $html = $this->renderOperatorDashboard(['upcoming' => [$this->booking()]]);
            $this->assertLessThan(strpos($html, 'vb-dashboard-metrics'), strpos($html, 'vb-table-container'));
            $this->assertStringContainsString('2:00 PM', $html);
        } finally {
            \App\Engine\Locale::setTenantOverrides(['time_format' => '24h']);
        }
    }

    public function test_resource_dashboards_show_arrival_and_departure_dates(): void
    {
        $booking = array_merge($this->booking(), ['booking_pattern' => 'resource', 'resource_name' => 'Hotel stay',
            'start_datetime' => '2026-10-22 00:00:00', 'end_datetime' => '2026-10-25 00:00:00']);
        foreach ([$this->renderOperatorDashboard(['upcoming' => [$booking]]),
            $this->renderTenantDashboard(['upcoming' => [$booking], 'todaySchedule' => [$booking]])] as $html) {
            $this->assertStringContainsString(\App\Engine\Locale::date(new \DateTimeImmutable('2026-10-25')), $html);
            $this->assertStringNotContainsString('00:00', $html);
            $this->assertStringNotContainsString('12:00 AM', $html);
        }
        $this->assertStringContainsString('vb-dashboard-schedule-row--stay', $this->renderTenantDashboard(['todaySchedule' => [$booking]]));
    }

    private function booking(): array
    {
        return [
            'id' => 'booking-1', 'tenant_id' => 'test-tenant-id', 'customer_name' => 'Next Customer',
            'service_name' => 'Consultation', 'status' => 'confirmed',
            'start_datetime' => date('Y-m-d') . ' 14:00:00', 'end_datetime' => date('Y-m-d') . ' 14:30:00',
        ];
    }

    // ════════════════════════════════════════════════════════════════
    // Helpers
    // ════════════════════════════════════════════════════════════════

    private function renderOperatorDashboard(array $overrides = []): string
    {
        $defaults = [
            'user' => ['name' => 'Test Operator', 'type' => 'operator'],
            'version' => '1.0.0-test',
            'activeTenants' => 5,
            'tenantCounts' => ['active' => 5, 'total' => 6, 'paused' => 1, 'archived' => 0],
            'todayBookings' => 12,
            'weekBookings' => 45,
            'upcoming24h' => 3,
            'deltaToday' => 0,
            'deltaWeek' => 0,
            'upcoming' => [],
        ];

        return $this->renderTemplate(
            $this->templateDir . '/admin/dashboard.php',
            array_merge($defaults, $overrides)
        );
    }

    private function renderTenantDashboard(array $overrides = []): string
    {
        $defaults = [
            'user' => ['name' => 'Test Owner', 'type' => 'business_user', 'role' => 'owner'],
            'version' => '1.0.0-test',
            'csrfToken' => 'test-csrf-token',
            'pageTitle' => 'Test Salon',
            'activePage' => 'dashboard',
            'tenant' => ['id' => 'test-tenant-id', 'name' => 'Test Salon', 'slug' => 'test-salon', 'booking_pattern' => 'timeslot'],
            'todayBookings' => 5,
            'weekBookings' => 20,
            'monthBookings' => 42,
            'totalCustomers' => 18,
            'upcoming' => [],
            'deltaToday' => 0,
            'deltaWeek' => 0,
            'todaySchedule' => [],
            'staffWorkingToday' => null,
            'setupActions' => [],
            'pendingApprovals' => 0,
        ];

        return $this->renderTemplate(
            $this->templateDir . '/admin/dashboard-business.php',
            array_merge($defaults, $overrides)
        );
    }

    /**
     * Render a dashboard template and return its $content variable.
     *
     * The template does: ob_start() → HTML → $content = ob_get_clean(); include layout.php;
     *
     * We wrap the entire include in an outer output buffer so the layout
     * output (which will error) is captured and discarded. The $content
     * variable is set by the template's own ob_get_clean() call.
     */
    private function renderTemplate(string $path, array $vars): string
    {
        if (!file_exists($path)) {
            $this->markTestSkipped("Template not found: {$path}");
        }

        // Track the OB level before we start
        $levelBefore = ob_get_level();

        // Extract template variables into local scope
        extract($vars);

        // Wrap everything — template + layout include — in an outer buffer
        ob_start();

        try {
            include $path;
        } catch (\Throwable) {
            // Layout include will fail (missing full app context).
            // That's fine — $content was already set.
        }

        // Discard any output (layout partial output, errors, etc.)
        while (ob_get_level() > $levelBefore) {
            ob_end_clean();
        }

        // $content was set by the template's internal ob_get_clean()
        return $content ?? '';
    }
}
