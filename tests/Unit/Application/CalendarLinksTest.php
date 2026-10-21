<?php

declare(strict_types=1);

namespace Tests\Unit\Application;

use App\Application\Bookings\CalendarLinks;
use PHPUnit\Framework\TestCase;

/** The calendar entry for one booking must not change between reads. */
final class CalendarLinksTest extends TestCase
{
    private const BOOKING = [
        'id' => '01M5EWBANS68QJN5022GE06KFA',
        'status' => 'confirmed',
        'booking_pattern' => 'timeslot',
        'title' => 'Haircut',
        'resource' => 'Demo Studio',
        'staff' => null,
        'start_utc' => '2026-11-10T13:30:00Z',
        'end_utc' => '2026-11-10T14:15:00Z',
        'check_in' => null,
        'check_out' => null,
        'nights' => null,
    ];

    private const TENANT = ['name' => 'Demo Studio', 'slug' => 'demo', 'timezone' => 'Europe/Amsterdam'];

    public function testTheEntryIsStampedWithTheBookingsCreationTimeFromItsUlid(): void
    {
        $links = CalendarLinks::build(self::BOOKING, self::TENANT);

        self::assertNotNull($links);
        self::assertStringContainsString("DTSTAMP:20261021T092317Z\r\n", $links['ics']);
    }

    public function testRepeatedReadsAreIdenticalWhateverTheClockSays(): void
    {
        $first = CalendarLinks::build(self::BOOKING, self::TENANT);
        sleep(1);

        self::assertSame($first, CalendarLinks::build(self::BOOKING, self::TENANT));
    }
}
