<?php

declare(strict_types=1);

namespace App\Agent\Mcp;

final class Documentation
{
    public static function page(string $page): string
    {
        $page = preg_replace('#^/?(?:docs/)?agent-api/#', '', ltrim($page, '/'));
        return [
            'bookings' => 'booking-tools',
            'booking-creation' => 'book-an-appointment',
            'booking-status' => 'booking-tools',
            'cancellation' => 'manage-your-booking',
            'rescheduling' => 'manage-your-booking',
            'customer-booking' => 'book-an-appointment',
            'customer-privacy' => 'customers-and-privacy',
            'availability' => 'availability-and-timezones',
        ][$page] ?? $page;
    }
}
