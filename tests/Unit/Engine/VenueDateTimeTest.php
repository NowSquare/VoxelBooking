<?php

declare(strict_types=1);

namespace Tests\Unit\Engine;

use App\Engine\VenueDateTime;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Venue wall times are stored without an offset. Their canonical instant is
 * PHP's own interpretation, which stored bookings were published with and
 * must keep. PHP reads a repeated autumn wall time as its second occurrence
 * on and east of UTC and as its first occurrence west of UTC; these tests
 * pin that, and that a published range never collapses or inverts.
 */
final class VenueDateTimeTest extends TestCase
{
    public static function repeatedHours(): array
    {
        return [
            'Amsterdam, east of UTC: second pass' => ['Europe/Amsterdam', '2026-10-25 02:30:00', '2026-10-25T01:30:00Z'],
            'London, on UTC: second pass' => ['Europe/London', '2026-10-25 01:30:00', '2026-10-25T01:30:00Z'],
            'New York, west of UTC: first pass' => ['America/New_York', '2026-11-01 01:30:00', '2026-11-01T05:30:00Z'],
            'Sydney, southern autumn: second pass' => ['Australia/Sydney', '2027-04-04 02:30:00', '2027-04-03T16:30:00Z'],
            'Lord Howe, half-hour shift: second pass' => ['Australia/Lord_Howe', '2027-04-04 01:45:00', '2027-04-03T15:15:00Z'],
        ];
    }

    #[DataProvider('repeatedHours')]
    public function test_a_repeated_wall_time_keeps_phps_published_instant(string $zone, string $wall, string $expectedUtc): void
    {
        $instant = VenueDateTime::instant($wall, new \DateTimeZone($zone));

        $this->assertSame($expectedUtc, $instant->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'));
        $this->assertSame($wall, $instant->format('Y-m-d H:i:s'), 'The reading must still show the stored wall time');
    }

    public function test_a_stored_amsterdam_fall_back_booking_keeps_the_instant_it_was_published_with(): void
    {
        // Published before the range guard existed; stored bookings must not move.
        $range = VenueDateTime::utcRange('2026-10-25 02:30:00', '2026-10-25 03:00:00', 'Europe/Amsterdam');

        $this->assertSame('2026-10-25T01:30:00Z', $range['start_utc']);
        $this->assertSame('2026-10-25T02:00:00Z', $range['end_utc']);
    }

    public function test_unambiguous_wall_times_are_unchanged(): void
    {
        $zone = new \DateTimeZone('Europe/Amsterdam');

        $this->assertSame('2026-10-24T23:30:00Z', VenueDateTime::utcRange('2026-10-25 01:30:00', '2026-10-25 03:00:00', 'Europe/Amsterdam')['start_utc']);
        $this->assertSame('2026-10-25T02:00:00Z', VenueDateTime::utcRange('2026-10-25 01:30:00', '2026-10-25 03:00:00', 'Europe/Amsterdam')['end_utc']);
        $this->assertSame('2026-07-01 10:00:00', VenueDateTime::instant('2026-07-01 10:00:00', $zone)->format('Y-m-d H:i:s'));
    }

    public function test_a_range_into_the_repeated_hour_keeps_phps_instants(): void
    {
        // 01:30 is summer time; PHP reads 02:30 east of UTC as the second pass.
        $range = VenueDateTime::utcRange('2026-10-25 01:30:00', '2026-10-25 02:30:00', 'Europe/Amsterdam');

        $this->assertSame('2026-10-24T23:30:00Z', $range['start_utc']);
        $this->assertSame('2026-10-25T01:30:00Z', $range['end_utc']);
    }

    public static function rangesAcrossTheRepeatedHour(): array
    {
        return [
            'Amsterdam into the hour' => ['Europe/Amsterdam', '2026-10-25 01:30:00', '2026-10-25 02:30:00'],
            'Amsterdam inside the hour' => ['Europe/Amsterdam', '2026-10-25 02:00:00', '2026-10-25 02:15:00'],
            'Amsterdam through the hour' => ['Europe/Amsterdam', '2026-10-25 01:59:00', '2026-10-25 03:00:00'],
            'Amsterdam out of the hour' => ['Europe/Amsterdam', '2026-10-25 02:45:00', '2026-10-25 03:15:00'],
            'Amsterdam overnight event' => ['Europe/Amsterdam', '2026-10-24 23:30:00', '2026-10-25 02:30:00'],
            'Amsterdam whole repeated hour' => ['Europe/Amsterdam', '2026-10-25 02:00:00', '2026-10-25 02:59:00'],
            'New York into the hour' => ['America/New_York', '2026-11-01 00:30:00', '2026-11-01 01:30:00'],
            'New York out of the hour' => ['America/New_York', '2026-11-01 01:30:00', '2026-11-01 02:00:00'],
        ];
    }

    #[DataProvider('rangesAcrossTheRepeatedHour')]
    public function test_a_range_across_the_repeated_hour_always_ends_after_it_starts(string $zone, string $start, string $end): void
    {
        $range = VenueDateTime::utcRange($start, $end, $zone);

        $this->assertNotSame($range['start_utc'], $range['end_utc']);
        $this->assertGreaterThan(strtotime($range['start_utc']), strtotime($range['end_utc']));
    }

    public function test_a_range_that_would_collapse_in_the_spring_gap_keeps_its_wall_clock_length(): void
    {
        // PHP moves the nonexistent 02:30 to 03:30, the same instant as the 03:30 end.
        $range = VenueDateTime::utcRange('2026-03-29 02:30:00', '2026-03-29 03:30:00', 'Europe/Amsterdam');

        $this->assertSame('2026-03-29T01:30:00Z', $range['start_utc']);
        $this->assertSame('2026-03-29T02:30:00Z', $range['end_utc']);
    }

    public function test_a_range_whose_end_falls_in_the_spring_gap_still_ends_after_it_starts(): void
    {
        // 02:30 does not exist on this night. PHP moves it to 03:30, after the 03:00 end.
        $range = VenueDateTime::utcRange('2026-03-29 02:30:00', '2026-03-29 03:00:00', 'Europe/Amsterdam');

        $this->assertGreaterThan(strtotime($range['start_utc']), strtotime($range['end_utc']));
    }

    public function test_a_stay_is_a_pair_of_local_dates(): void
    {
        $stay = VenueDateTime::stayRange('2027-01-10 00:00:00', '2027-01-13 00:00:00');

        $this->assertSame(['check_in' => '2027-01-10', 'check_out' => '2027-01-13', 'nights' => 3], $stay);
    }

    public function test_stays_are_presented_as_dates_and_timed_bookings_as_instants(): void
    {
        $stay = VenueDateTime::presentRange('resource', '2027-01-10 00:00:00', '2027-01-12 00:00:00', 'Pacific/Auckland');
        $timed = VenueDateTime::presentRange('timeslot', '2027-01-10 09:00:00', '2027-01-10 10:00:00', 'Pacific/Auckland');

        $this->assertSame(['check_in' => '2027-01-10', 'check_out' => '2027-01-12', 'nights' => 2], $stay);
        $this->assertSame(['start_utc' => '2027-01-09T20:00:00Z', 'end_utc' => '2027-01-09T21:00:00Z'], $timed);
    }
}
