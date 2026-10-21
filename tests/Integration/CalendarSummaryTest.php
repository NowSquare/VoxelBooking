<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Agent\{OperationException, PrincipalResolver, SchemaValidator};
use App\Engine\{Database, Ulid};
use PHPUnit\Framework\TestCase;
use Tests\Support\AgentTransports;

/**
 * calendar_summary reports the numbers a person reads on the admin calendar.
 *
 * The same business and anchor date are rendered by the day, week and month
 * views over HTTP, then read through the Executor, the HTTP API and MCP. Window
 * boundaries, per-day counts and closures must agree cell for cell.
 */
final class CalendarSummaryTest extends TestCase
{
    private const ANCHOR = '2030-01-15';

    private AgentTransports $transports;
    private string $tenant;
    private string $cookieJar;

    protected function setUp(): void
    {
        \Tests\Support\TestEnvironment::boot();
        TestFixtures::provision();
        Database::execute("DELETE FROM rate_limits WHERE endpoint_group IN ('agent','login','admin')");
        Database::execute('DELETE FROM agent_rate_budgets');
        $this->transports = new AgentTransports();
        $this->cookieJar = (string) tempnam(sys_get_temp_dir(), 'vb-calendar-summary-');

        // A Sunday week start and an Amsterdam clock, so neither default can hide a boundary error.
        $this->tenant = Ulid::generate();
        Database::execute(
            "INSERT INTO tenants(id,name,slug,email,booking_pattern,timezone,currency,status,week_start) VALUES(?,?,?,'calendar@example.test','timeslot','Europe/Amsterdam','EUR','active',0)",
            [$this->tenant, 'Calendar summary', 'calendar-summary-' . strtolower($this->tenant)]
        );
        $customer = Ulid::generate();
        Database::execute(
            "INSERT INTO customers(id,tenant_id,name,email) VALUES(?,?,'Calendar guest','calendar-guest@example.test')",
            [$customer, $this->tenant]
        );
        foreach ([0, 1, 2, 3, 4] as $weekday) {
            Database::execute(
                "INSERT INTO availability(id,tenant_id,day_of_week,start_time,end_time,is_available) VALUES(?,?,?,'09:00','17:00',1)",
                [Ulid::generate(), $this->tenant, $weekday]
            );
        }
        Database::execute(
            "INSERT INTO blocked_dates(id,tenant_id,start_date,end_date,reason) VALUES(?,?,'2030-01-16','2030-01-16','Closed for stocktaking')",
            [Ulid::generate(), $this->tenant]
        );
        foreach ([
            ['timeslot', '2030-01-15 10:00:00', '2030-01-15 10:30:00', 'confirmed'],
            ['timeslot', '2030-01-15 11:00:00', '2030-01-15 12:00:00', 'pending'],
            ['timeslot', '2030-01-15 13:00:00', '2030-01-15 13:30:00', 'cancelled'],
            ['resource', '2030-01-18 15:00:00', '2030-01-21 11:00:00', 'confirmed'],
            ['resource', '2030-01-22 15:00:00', '2030-01-24 00:00:00', 'confirmed'],
            ['timeslot', '2029-12-31 09:00:00', '2029-12-31 09:30:00', 'confirmed'],
            ['timeslot', '2030-02-09 09:00:00', '2030-02-09 09:30:00', 'confirmed'],
            ['timeslot', '2030-02-10 09:00:00', '2030-02-10 09:30:00', 'confirmed'],
        ] as [$pattern, $start, $end, $status]) {
            Database::execute(
                "INSERT INTO bookings(id,tenant_id,customer_id,booking_pattern,start_datetime,end_datetime,status,source,customer_name_snapshot) VALUES(?,?,?,?,?,?,?,'admin','Calendar guest')",
                [Ulid::generate(), $this->tenant, $customer, $pattern, $start, $end, $status]
            );
        }
    }

    protected function tearDown(): void
    {
        Database::execute('UPDATE agent_connections SET revoked_at=UTC_TIMESTAMP() WHERE principal_id=? AND revoked_at IS NULL', [TestFixtures::OPERATOR_ID]);
        Database::execute('DELETE FROM tenants WHERE id=?', [$this->tenant]);
        @unlink($this->cookieJar);
    }

    private function client(): array
    {
        $session = (new PrincipalResolver())->resolve(
            'operator',
            TestFixtures::OPERATOR_ID,
            [$this->tenant],
            ['bookings:read'],
            authMode: 'session'
        );

        return $this->transports->connect($session, ['bookings:read']);
    }

    public function testMonthWeekAndDayTotalsEqualTheRenderedCalendarOnEveryTransport(): void
    {
        $client = $this->client();
        $this->login();
        foreach (['month', 'week', 'day'] as $view) {
            $input = ['business_id' => $this->tenant, 'view' => $view, 'date' => self::ANCHOR];
            $result = $this->transports->executor($client, 'calendar_summary', $input);
            (new SchemaValidator())->validate($this->transports->registry->get('calendar_summary')->resultSchema(), $result, true);
            $summary = $result['data'];

            $http = $this->transports->http($client, 'calendar_summary', $input);
            self::assertSame(200, $http['status'], json_encode($http['body']));
            $native = $this->transports->mcp($client, 'calendar_summary', $input);
            self::assertFalse($native['body']['result']['isError'] ?? true, json_encode($native['body']));
            foreach ([$http['body'], $native['body']['result']['structuredContent']] as $transported) {
                self::assertSame($summary, $transported['data'], $view);
            }

            $rendered = $this->rendered($view);
            self::assertSame(array_keys($rendered), array_column($summary['days'], 'date'), $view . ' window');
            foreach ($summary['days'] as $day) {
                self::assertSame($rendered[$day['date']], $this->visible($view, $day), $view . ' ' . $day['date']);
            }
        }
    }

    public function testWindowsFollowTheBusinessWeekStartAndTheSixWeekGrid(): void
    {
        $client = $this->client();
        $month = $this->transports->executor($client, 'calendar_summary', ['business_id' => $this->tenant, 'view' => 'month', 'date' => self::ANCHOR])['data'];
        self::assertSame([0, '2029-12-30', '2030-02-09', 42], [$month['week_start'], $month['from'], $month['to'], count($month['days'])]);
        $byDate = array_column($month['days'], null, 'date');
        // Cancelled rows never occupy a day; a stay occupies each night it covers and not a midnight departure.
        self::assertSame(2, $byDate['2030-01-15']['bookings']);
        self::assertSame([1, 1, 1, 1], array_map(static fn ($date) => $byDate[$date]['bookings'], ['2030-01-18', '2030-01-19', '2030-01-20', '2030-01-21']));
        self::assertSame([1, 1, 0], array_map(static fn ($date) => $byDate[$date]['bookings'], ['2030-01-22', '2030-01-23', '2030-01-24']));
        self::assertSame([false, true], [$byDate['2029-12-31']['in_month'], $byDate['2030-01-31']['in_month']]);
        self::assertTrue($byDate['2030-01-16']['blocked']);
        self::assertSame([true, false], [$byDate['2030-01-14']['open'], $byDate['2030-01-19']['open']]);
        // Six distinct bookings occupy the grid; the one on 10 February falls outside it.
        self::assertSame(6, $month['total']);

        Database::execute('UPDATE tenants SET week_start=1 WHERE id=?', [$this->tenant]);
        $week = $this->transports->executor($client, 'calendar_summary', ['business_id' => $this->tenant, 'view' => 'week', 'date' => self::ANCHOR])['data'];
        self::assertSame([1, '2030-01-14', '2030-01-20'], [$week['week_start'], $week['from'], $week['to']]);
        self::assertSame(3, $week['total']);

        try {
            $this->transports->executor($client, 'calendar_summary', ['business_id' => $this->tenant, 'view' => 'year']);
            self::fail('An unknown view must be refused.');
        } catch (OperationException $error) {
            self::assertSame('validation_failed', $error->problemCode);
        }
    }

    /** What a person sees for one day: the badge count where the view shows one, and the closure state. */
    private function visible(string $view, array $day): array
    {
        $shown = $view === 'month' && !$day['in_month'] ? 0 : $day['bookings'];
        $closed = $view === 'day' ? null : (!$day['open'] && !$day['blocked'] && ($view === 'week' || $day['bookings'] === 0));

        return ['count' => $shown, 'blocked' => $view === 'day' ? null : $day['blocked'], 'unavailable' => $closed];
    }

    /** @return array<string, array{count: int, blocked: ?bool, unavailable: ?bool}> */
    private function rendered(string $view): array
    {
        $base = '/admin/tenants/' . $this->tenant . '/calendar';
        $path = match ($view) {
            'month' => $base,
            'week' => $base . '/week',
            'day' => $base . '/day',
        };
        $html = $this->page($path . '?date=' . self::ANCHOR);
        if ($view === 'day') {
            return [self::ANCHOR => [
                'count' => preg_match_all('/class="vb-booking-block /', $html),
                'blocked' => null,
                'unavailable' => null,
            ]];
        }

        $cell = $view === 'month' ? 'vb-calendar-month-cell' : 'vb-week-col-header';
        $badge = $view === 'month' ? '/<span class="vb-calendar-month-count">(\d+)<\/span>/' : '/<span class="vb-week-col-badge">(\d+)<\/span>/';
        preg_match_all(
            '/<a href="[^"]*\/calendar\/day\?date=(\d{4}-\d{2}-\d{2})"\s+class="' . $cell . '([^"]*)">(.*?)<\/a>/s',
            $html,
            $matches,
            PREG_SET_ORDER
        );
        $days = [];
        foreach ($matches as [, $date, $classes, $content]) {
            $days[$date] = [
                'count' => preg_match($badge, $content, $count) === 1 ? (int) $count[1] : 0,
                'blocked' => str_contains($classes, 'is-blocked'),
                'unavailable' => str_contains($classes, 'is-unavailable'),
            ];
        }

        return $days;
    }

    private function login(): void
    {
        $form = $this->browser('GET', '/admin/login');
        preg_match('/name="_csrf_token" value="([^"]+)"/', $form['body'], $token);
        $this->browser('POST', '/admin/login', [
            'email' => TestFixtures::OPERATOR_EMAIL,
            'password' => TestFixtures::OPERATOR_PASSWORD,
            '_csrf_token' => $token[1] ?? '',
        ]);
    }

    private function page(string $path): string
    {
        $response = $this->browser('GET', $path);
        self::assertSame(200, $response['status'], $path);

        return $response['body'];
    }

    /** @return array{status: int, body: string} */
    private function browser(string $method, string $path, ?array $form = null): array
    {
        $curl = curl_init(rtrim((string) $_ENV['APP_TEST_URL'], '/') . $path);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIEJAR => $this->cookieJar,
            CURLOPT_COOKIEFILE => $this->cookieJar,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 15,
        ]);
        if ($method === 'POST') {
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($form ?? []));
        }
        $body = (string) curl_exec($curl);

        return ['status' => curl_getinfo($curl, CURLINFO_HTTP_CODE), 'body' => $body];
    }
}
