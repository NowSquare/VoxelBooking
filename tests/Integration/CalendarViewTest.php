<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Engine\Database;
use App\Engine\Ulid;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for the tenant calendar views.
 *
 * Covers:
 * - Month view is the default entry surface (/calendar → month)
 * - Month view renders the grid with correct structure
 * - Day view ALWAYS renders the full timeline (never empty-state card)
 * - Day view separates action bar from navigation
 * - Week view is accessible at /calendar/week
 * - View switcher links use the correct URLs
 * - Day cells in month view link to /calendar/day
 * - Booking pills render when bookings exist
 * - Unauthenticated access redirects to login
 */
final class CalendarViewTest extends TestCase
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
        $this->cookieJar = tempnam(sys_get_temp_dir(), 'vb_cal_test_') ?: '/tmp/vb_cal_test_cookies';
    }

    protected function tearDown(): void
    {
        if (isset($this->cookieJar) && file_exists($this->cookieJar)) {
            unlink($this->cookieJar);
        }
    }

    // ════════════════════════════════════════════════════════════════
    // Month is the default entry surface
    // ════════════════════════════════════════════════════════════════

    public function test_calendar_root_renders_month_view(): void
    {
        $this->doLoginOperator();
        $tid = TestFixtures::BUSINESS_TENANT_ID;

        $r = $this->get("/admin/tenants/{$tid}/calendar");

        $this->assertSame(200, $r['code']);
        $this->assertStringContainsString(
            'vb-calendar-month',
            $r['body'],
            '/calendar should render the month grid (default surface)'
        );
        $this->assertStringContainsString(
            'vb-calendar-month-grid',
            $r['body'],
            'Month grid container should be present'
        );
    }

    public function test_month_view_renders_day_name_headers(): void
    {
        $this->doLoginOperator();
        $tid = TestFixtures::BUSINESS_TENANT_ID;

        $r = $this->get("/admin/tenants/{$tid}/calendar");

        $this->assertSame(200, $r['code']);
        $this->assertStringContainsString(
            'vb-calendar-month-dayname',
            $r['body'],
            'Month view should include day name headers'
        );
        $this->assertStringContainsString(
            'vb-calendar-month-header',
            $r['body'],
            'Month header row should be present'
        );
    }

    public function test_month_view_renders_42_cells(): void
    {
        $this->doLoginOperator();
        $tid = TestFixtures::BUSINESS_TENANT_ID;

        $r = $this->get("/admin/tenants/{$tid}/calendar");

        $this->assertSame(200, $r['code']);
        // 6 rows × 7 columns = 42 cells
        // Count only <a> tags with the cell class (not headers or other elements)
        preg_match_all('/<a\s[^>]*class="vb-calendar-month-cell[^"]*"/', $r['body'], $matches);
        $this->assertSame(42, count($matches[0]), 'Month grid should have exactly 42 cells (6 rows × 7 days)');
    }

    public function test_month_view_marks_today(): void
    {
        $this->doLoginOperator();
        $tid = TestFixtures::BUSINESS_TENANT_ID;

        $r = $this->get("/admin/tenants/{$tid}/calendar");

        $this->assertSame(200, $r['code']);
        $this->assertStringContainsString(
            'is-today',
            $r['body'],
            'Current month should mark today\'s cell'
        );
    }

    public function test_month_view_has_outside_month_cells(): void
    {
        $this->doLoginOperator();
        $tid = TestFixtures::BUSINESS_TENANT_ID;

        $r = $this->get("/admin/tenants/{$tid}/calendar");

        $this->assertSame(200, $r['code']);
        $this->assertStringContainsString(
            'is-outside',
            $r['body'],
            'Month grid should have outside-month cells for padding'
        );
    }

    // ════════════════════════════════════════════════════════════════
    // View switcher uses .vb-segmented control
    // ════════════════════════════════════════════════════════════════

    public function test_month_view_uses_segmented_control(): void
    {
        $this->doLoginOperator();
        $tid = TestFixtures::BUSINESS_TENANT_ID;

        $r = $this->get("/admin/tenants/{$tid}/calendar");

        $this->assertSame(200, $r['code']);
        $this->assertStringContainsString(
            'vb-segmented',
            $r['body'],
            'View switcher should use .vb-segmented premium control'
        );
        $this->assertStringContainsString(
            'vb-segmented-item active',
            $r['body'],
            'Active view should be marked with .vb-segmented-item.active'
        );
    }

    // ════════════════════════════════════════════════════════════════
    // Day view accessible at /calendar/day
    // ════════════════════════════════════════════════════════════════

    public function test_day_view_always_renders_timeline(): void
    {
        $this->doLoginOperator();
        $tid = TestFixtures::BUSINESS_TENANT_ID;

        // Use a date with no bookings to prove the timeline still renders
        $emptyDate = (new \DateTimeImmutable('+30 days'))->format('Y-m-d');
        $r = $this->get("/admin/tenants/{$tid}/calendar/day?date={$emptyDate}");

        $this->assertSame(200, $r['code']);
        // Timeline MUST always be present — never an empty-state card
        $this->assertStringContainsString(
            'vb-calendar-timeline',
            $r['body'],
            'Day view must ALWAYS render the full timeline with clickable hour rails, even with zero bookings'
        );
        $this->assertStringNotContainsString(
            'vb-empty-state',
            $r['body'],
            'Day view must NOT use the empty-state card pattern — the timeline IS the empty state'
        );
        // Hour rails must be clickable links to create booking
        $this->assertStringContainsString(
            'vb-calendar-hour-link',
            $r['body'],
            'Hour rails must be clickable links for booking creation'
        );
    }

    public function test_day_view_has_new_booking_cta(): void
    {
        $this->doLoginOperator();
        $tid = TestFixtures::BUSINESS_TENANT_ID;

        $r = $this->get("/admin/tenants/{$tid}/calendar/day");

        $this->assertSame(200, $r['code']);
        // Calendar header with CTA and tabs
        $this->assertStringContainsString(
            'vb-calendar-header',
            $r['body'],
            'Day view must have a calendar header'
        );
        $this->assertStringContainsString(
            'btn-calendar-new-booking',
            $r['body'],
            'New Booking button must exist in the header'
        );
    }

    public function test_day_view_uses_segmented_control(): void
    {
        $this->doLoginOperator();
        $tid = TestFixtures::BUSINESS_TENANT_ID;

        $r = $this->get("/admin/tenants/{$tid}/calendar/day");

        $this->assertSame(200, $r['code']);
        $this->assertStringContainsString(
            'vb-segmented',
            $r['body'],
            'Day view should use .vb-segmented control'
        );
    }

    // ════════════════════════════════════════════════════════════════
    // Week view accessible at /calendar/week
    // ════════════════════════════════════════════════════════════════

    public function test_week_view_accessible_at_calendar_week(): void
    {
        $this->doLoginOperator();
        $tid = TestFixtures::BUSINESS_TENANT_ID;

        $r = $this->get("/admin/tenants/{$tid}/calendar/week");

        $this->assertSame(200, $r['code']);
        $this->assertStringContainsString(
            'vb-week',
            $r['body'],
            '/calendar/week should render the week schedule'
        );
    }

    public function test_week_view_uses_segmented_control(): void
    {
        $this->doLoginOperator();
        $tid = TestFixtures::BUSINESS_TENANT_ID;

        $r = $this->get("/admin/tenants/{$tid}/calendar/week");

        $this->assertSame(200, $r['code']);
        $this->assertStringContainsString(
            'vb-segmented',
            $r['body'],
            'Week view should use .vb-segmented control'
        );
    }

    public function test_week_view_uses_iso_availability_day_mapping(): void
    {
        $this->doLoginOperator();
        $tid = TestFixtures::BUSINESS_TENANT_ID;
        $originalRows = Database::query(
            'SELECT `day_of_week`, `start_time`, `end_time`
             FROM `availability`
             WHERE `tenant_id` = ? AND `staff_id` IS NULL
             ORDER BY `day_of_week` ASC, `start_time` ASC',
            [$tid]
        );

        try {
            Database::execute(
                'DELETE FROM `availability` WHERE `tenant_id` = ? AND `staff_id` IS NULL',
                [$tid]
            );

            foreach ([0, 1, 2, 3, 4] as $isoDay) {
                Database::execute(
                    'INSERT INTO `availability`
                     (`id`, `tenant_id`, `staff_id`, `day_of_week`, `start_time`, `end_time`, `is_available`)
                     VALUES (?, ?, NULL, ?, ?, ?, 1)',
                    [Ulid::generate(), $tid, $isoDay, '09:00:00', '17:00:00']
                );
            }

            $r = $this->get("/admin/tenants/{$tid}/calendar/week?date=2026-04-05");

            $this->assertSame(200, $r['code']);

            $headers = $this->extractWeekHeaderColumns($r['body']);

            $this->assertStringNotContainsString(
                'is-unavailable',
                $headers['FRIDAY'] ?? '',
                'Friday should stay open when availability exists for ISO weekday 4'
            );
            $this->assertStringContainsString(
                'is-unavailable',
                $headers['SATURDAY'] ?? '',
                'Saturday should be closed when it has no availability'
            );
            $this->assertStringContainsString(
                'is-unavailable',
                $headers['SUNDAY'] ?? '',
                'Sunday should be closed when it has no availability'
            );
        } finally {
            Database::execute(
                'DELETE FROM `availability` WHERE `tenant_id` = ? AND `staff_id` IS NULL',
                [$tid]
            );

            foreach ($originalRows as $row) {
                Database::execute(
                    'INSERT INTO `availability`
                     (`id`, `tenant_id`, `staff_id`, `day_of_week`, `start_time`, `end_time`, `is_available`)
                     VALUES (?, ?, NULL, ?, ?, ?, 1)',
                    [
                        Ulid::generate(),
                        $tid,
                        (int) $row['day_of_week'],
                        $row['start_time'],
                        $row['end_time'],
                    ]
                );
            }
        }
    }

    // ════════════════════════════════════════════════════════════════
    // Day cells link to /calendar/day (not /calendar)
    // ════════════════════════════════════════════════════════════════

    public function test_month_day_cells_link_to_day_view(): void
    {
        $this->doLoginOperator();
        $tid = TestFixtures::BUSINESS_TENANT_ID;

        $r = $this->get("/admin/tenants/{$tid}/calendar");

        $this->assertSame(200, $r['code']);
        $this->assertStringContainsString(
            '/calendar/day?date=',
            $r['body'],
            'Month cells should link to /calendar/day?date=...'
        );
    }

    // ════════════════════════════════════════════════════════════════
    // Calendar navigation
    // ════════════════════════════════════════════════════════════════

    public function test_month_view_has_navigation_controls(): void
    {
        $this->doLoginOperator();
        $tid = TestFixtures::BUSINESS_TENANT_ID;

        $r = $this->get("/admin/tenants/{$tid}/calendar");

        $this->assertSame(200, $r['code']);
        $this->assertStringContainsString(
            'vb-calendar-nav',
            $r['body'],
            'Calendar navigation should be present'
        );
        $this->assertStringContainsString(
            'vb-calendar-date-title',
            $r['body'],
            'Date title (month name + year) should be visible'
        );
    }

    public function test_month_view_date_navigation_stays_on_month(): void
    {
        $this->doLoginOperator();
        $tid = TestFixtures::BUSINESS_TENANT_ID;

        $r = $this->get("/admin/tenants/{$tid}/calendar");

        $this->assertSame(200, $r['code']);
        // Prev/next month links should stay within /calendar?date=...
        $this->assertMatchesRegularExpression(
            '#/calendar\?date=\d{4}-\d{2}-\d{2}#',
            $r['body'],
            'Month navigation arrows should link to /calendar?date=...'
        );
    }

    // ════════════════════════════════════════════════════════════════
    // Booking pills & overflow
    // ════════════════════════════════════════════════════════════════

    public function test_month_view_renders_booking_pills_when_bookings_exist(): void
    {
        $this->doLoginOperator();
        $tid = TestFixtures::BUSINESS_TENANT_ID;

        // Other workflows may cancel the shared fixture. Own the confirmed
        // booking this assertion needs instead of depending on leaked rows.
        $bookingId = Ulid::generate();
        $customerId = Ulid::generate();
        $tomorrow = (new \DateTimeImmutable('+1 day', new \DateTimeZone('UTC')))->format('Y-m-d');
        Database::execute(
            "INSERT INTO `customers` (`id`, `tenant_id`, `name`, `email`) VALUES (?, ?, 'Calendar Pill Customer', 'calendar-pill@example.test')",
            [$customerId, $tid]
        );

        try {
            Database::execute(
                "INSERT INTO `bookings` (`id`, `tenant_id`, `booking_pattern`, `customer_id`, `start_datetime`, `end_datetime`, `status`, `source`)
                 VALUES (?, ?, 'timeslot', ?, ?, ?, 'confirmed', 'web')",
                [$bookingId, $tid, $customerId, $tomorrow . ' 09:00:00', $tomorrow . ' 09:30:00']
            );
            $r = $this->get("/admin/tenants/{$tid}/calendar?date={$tomorrow}");

            $this->assertSame(200, $r['code']);
            $this->assertStringContainsString('vb-calendar-month-pill', $r['body'],
                'Month view should render booking pills when bookings exist');
            $this->assertStringContainsString('Calendar Pill Customer', $r['body'],
                'The calendar must render the confirmed booking created by this test');
        } finally {
            Database::execute('DELETE FROM `bookings` WHERE `id` = ?', [$bookingId]);
            Database::execute('DELETE FROM `customers` WHERE `id` = ?', [$customerId]);
        }
    }

    public function test_month_view_shows_closed_state_on_outside_month_cells(): void
    {
        $this->doLoginOperator();
        $tid = TestFixtures::BUSINESS_TENANT_ID;
        $originalRows = Database::query(
            'SELECT `day_of_week`, `start_time`, `end_time`
             FROM `availability`
             WHERE `tenant_id` = ? AND `staff_id` IS NULL
             ORDER BY `day_of_week` ASC, `start_time` ASC',
            [$tid]
        );

        try {
            Database::execute(
                'DELETE FROM `availability` WHERE `tenant_id` = ? AND `staff_id` IS NULL',
                [$tid]
            );

            foreach ([0, 1, 2, 3, 4] as $isoDay) {
                Database::execute(
                    'INSERT INTO `availability`
                     (`id`, `tenant_id`, `staff_id`, `day_of_week`, `start_time`, `end_time`, `is_available`)
                     VALUES (?, ?, NULL, ?, ?, ?, 1)',
                    [Ulid::generate(), $tid, $isoDay, '09:00:00', '17:00:00']
                );
            }

            $r = $this->get("/admin/tenants/{$tid}/calendar?date=2026-04-05");

            $this->assertSame(200, $r['code']);

            $outsideSunday = $this->extractMonthCellByDate($r['body'], '2026-05-03');

            $this->assertStringContainsString(
                'is-outside',
                $outsideSunday,
                'Trailing next-month cells should keep outside-month styling'
            );
            $this->assertStringContainsString(
                'is-unavailable',
                $outsideSunday,
                'Closed state should also render for outside-month weekend cells'
            );
            $this->assertStringContainsString(
                'Closed',
                $outsideSunday,
                'Outside-month closed days should still show the closed badge'
            );
        } finally {
            Database::execute(
                'DELETE FROM `availability` WHERE `tenant_id` = ? AND `staff_id` IS NULL',
                [$tid]
            );

            foreach ($originalRows as $row) {
                Database::execute(
                    'INSERT INTO `availability`
                     (`id`, `tenant_id`, `staff_id`, `day_of_week`, `start_time`, `end_time`, `is_available`)
                     VALUES (?, ?, NULL, ?, ?, ?, 1)',
                    [
                        Ulid::generate(),
                        $tid,
                        (int) $row['day_of_week'],
                        $row['start_time'],
                        $row['end_time'],
                    ]
                );
            }
        }
    }

    // ════════════════════════════════════════════════════════════════
    // Auth guard
    // ════════════════════════════════════════════════════════════════

    public function test_unauthenticated_calendar_redirects_to_login(): void
    {
        // Fresh cookie jar — no session
        $tid = TestFixtures::BUSINESS_TENANT_ID;
        $r = $this->get("/admin/tenants/{$tid}/calendar");

        $this->assertSame(302, $r['code']);
        $this->assertStringContainsString('/admin/login', $r['location']);
    }

    // ── Helpers ──

    public function testBookingTableDetailAndCsvUseTheSharedProjection():void
    {
        $this->doLoginOperator();$tenant=TestFixtures::BUSINESS_TENANT_ID;$customer=Ulid::generate();$booking=Ulid::generate();
        Database::execute("INSERT INTO customers(id,tenant_id,name,email) VALUES(?,?,'Profile name','read-equivalence@example.test')",[$customer,$tenant]);
        Database::execute("INSERT INTO bookings(id,tenant_id,customer_id,booking_pattern,start_datetime,end_datetime,status,source,customer_name_snapshot,customer_email_snapshot) VALUES(?,?,?,'timeslot','2030-02-01 09:00:00','2030-02-01 09:30:00','confirmed','admin','=Snapshot customer','read-equivalence@example.test')",[$booking,$tenant,$customer]);
        try{
            $actor=new \App\Agent\ActorContext('operator',TestFixtures::OPERATOR_ID,'operator',[$tenant],['bookings:read','bookings:export']);
            $list=$this->get('/admin/bookings?search='.$booking);self::assertSame(200,$list['code']);self::assertStringContainsString('=Snapshot customer',$list['body']);
            $detail=$this->get('/admin/tenants/'.$tenant.'/bookings/'.$booking);self::assertSame(200,$detail['code']);self::assertStringContainsString('=Snapshot customer',$detail['body']);
            $expected=\App\Application\Bookings\BookingQueries::export($actor,['business_id'=>$tenant,'search'=>$booking]);
            $csv=$this->get('/admin/tenants/'.$tenant.'/bookings/export?search='.$booking);self::assertSame(200,$csv['code']);self::assertSame($expected['content'],$csv['body']);self::assertStringContainsString("'=Snapshot customer",$csv['body']);
        }finally{Database::execute('DELETE FROM bookings WHERE id=?',[$booking]);Database::execute('DELETE FROM customers WHERE id=?',[$customer]);}
    }
    public function testResourceStayRendersOnEveryOccupiedCalendarDay():void
    {
        $this->doLoginOperator();$tenant=TestFixtures::BUSINESS_TENANT_ID;$customer=Ulid::generate();$booking=Ulid::generate();
        Database::execute("INSERT INTO customers(id,tenant_id,name,email) VALUES(?,?,'Occupied multi-day stay','calendar-stay@example.test')",[$customer,$tenant]);
        Database::execute("INSERT INTO bookings(id,tenant_id,customer_id,booking_pattern,start_datetime,end_datetime,status,source) VALUES(?,?,?,'resource','2030-02-01 15:00:00','2030-02-05 11:00:00','confirmed','admin')",[$booking,$tenant,$customer]);
        try{
            foreach(['day','week',''] as $view){
                $response=$this->get('/admin/tenants/'.$tenant.'/calendar'.($view!==''?'/'.$view:'').'?date=2030-02-03');
                self::assertSame(200,$response['code']);self::assertStringContainsString('Occupied multi-day stay',$response['body']);
                if($view==='day')self::assertStringContainsString('top: 0%; height: 100%',$response['body']);
                if($view==='')self::assertStringContainsString('Occupied multi-day stay',$this->extractMonthCellByDate($response['body'],'2030-02-03'));
            }
        }finally{Database::execute('DELETE FROM bookings WHERE id=?',[$booking]);Database::execute('DELETE FROM customers WHERE id=?',[$customer]);}
    }

    private function doLoginOperator(): void
    {
        $r = $this->get('/admin/login');
        preg_match('/name="_csrf_token" value="([^"]+)"/', $r['body'], $m);
        $csrf = $m[1] ?? '';

        $this->post('/admin/login', [
            'email' => TestFixtures::OPERATOR_EMAIL,
            'password' => TestFixtures::OPERATOR_PASSWORD,
            '_csrf_token' => $csrf,
        ]);
    }

    /**
     * @return array{code: int, body: string, location: string}
     */
    private function get(string $path): array
    {
        $ch = curl_init($this->baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIEJAR => $this->cookieJar,
            CURLOPT_COOKIEFILE => $this->cookieJar,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADER => true,
        ]);
        $response = (string) curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);


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
        $ch = curl_init($this->baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($data),
            CURLOPT_COOKIEJAR => $this->cookieJar,
            CURLOPT_COOKIEFILE => $this->cookieJar,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADER => true,
        ]);
        $response = (string) curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);


        $body = substr($response, $headerSize);
        $headers = substr($response, 0, $headerSize);
        $location = '';
        if (preg_match('/^Location:\s*(.+)$/mi', $headers, $m)) {
            $location = trim($m[1]);
        }

        return compact('code', 'body', 'location');
    }

    /**
     * @return array<string, string>
     */
    private function extractWeekHeaderColumns(string $html): array
    {
        preg_match_all('/<a[^>]*class="vb-week-col-header[^"]*"[^>]*>.*?<\/a>/s', $html, $matches);

        $columns = [];
        foreach ($matches[0] as $columnHtml) {
            if (preg_match('/vb-week-col-dayname">\s*([^<]+)\s*</', $columnHtml, $dayMatch)) {
                $columns[strtoupper(trim($dayMatch[1]))] = $columnHtml;
            }
        }

        return $columns;
    }

    private function extractMonthCellByDate(string $html, string $date): string
    {
        $pattern = '#<a\s+href="/admin/tenants/[^"]+/calendar/day\?date=' . preg_quote($date, '#') . '"[^>]*class="[^"]*vb-calendar-month-cell[^"]*"[^>]*>.*?</a>#s';
        preg_match($pattern, $html, $matches);

        return $matches[0] ?? '';
    }
}
