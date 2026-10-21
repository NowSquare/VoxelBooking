<?php

declare(strict_types=1);

namespace App\Application\Bookings;

use App\Engine\BookingService;
use App\Engine\Database;
use App\Engine\Locale;
use App\Engine\Mailer;
use App\Engine\Outbox;
use App\Engine\Ulid;

/** One independently retried recipient per outbox row. */
final class BookingNotifications
{
    public static function queue(array $tenant, string $bookingId, string $kind, ?string $originalId = null): void
    {
        // A new transition gets a new event; a replay does not execute this command again.
        $eventId = Ulid::generate();

        // Snapshot the status now so delivery can drop the mail if a later transition overtakes it.
        $expectedStatus = Database::query(
            'SELECT status FROM bookings WHERE id=? AND tenant_id=?',
            [$bookingId, $tenant['id']]
        )[0]['status'] ?? null;

        foreach (['customer', 'staff'] as $audience) {
            if (
                $audience === 'staff'
                && !(bool) ($tenant[$kind === 'cancellation' ? 'notify_on_cancellation' : 'notify_on_booking'] ?? false)
            ) continue;

            Outbox::enqueue(
                'booking.notification',
                [
                    'booking_id' => $bookingId,
                    'tenant_id' => $tenant['id'],
                    'kind' => $kind,
                    'audience' => $audience,
                    'original_booking_id' => $originalId,
                    'expected_status' => $expectedStatus,
                    'demo_suppressed' => \App\Engine\DemoMode::isActive()
                ],
                $tenant['id'],
                $eventId . ':' . $audience
            );
        }
    }

    public static function deliver(array $payload): array
    {
        // Suppressed rows report success so the outbox stops retrying them.
        if (!empty($payload['demo_suppressed']) || \App\Engine\DemoMode::isActive()) return ['sent' => true, 'suppressed' => true];

        $tenantId = (string) ($payload['tenant_id'] ?? '');
        $bookingId = (string) ($payload['booking_id'] ?? '');
        $tenant = Database::query("SELECT * FROM tenants WHERE id = ? AND status != 'archived'", [$tenantId])[0] ?? null;
        $booking = BookingService::findByIdWithDetails($bookingId, $tenantId);
        if ($tenant === null || $booking === null) return ['sent' => true, 'suppressed' => true];

        $customer = Database::query(
            'SELECT is_anonymized FROM customers WHERE id = ? AND tenant_id = ?',
            [$booking['customer_id'], $tenantId]
        )[0] ?? null;
        if ($customer === null || (bool) $customer['is_anonymized']) return ['sent' => true, 'suppressed' => true];

        $kind = $payload['kind'] ?? '';
        $status = $booking['status'];
        if (isset($payload['expected_status']) && $payload['expected_status'] !== $status) return ['sent' => true, 'suppressed' => true];
        if (
            ($kind === 'cancellation' && $status !== 'cancelled')
            || ($kind !== 'cancellation' && !in_array($status, ['confirmed', 'pending', 'waitlisted'], true))
        ) return ['sent' => true, 'suppressed' => true];

        Locale::resolveForBooking($tenant, null);
        $tz = new \DateTimeZone($tenant['timezone']);
        $start = new \DateTimeImmutable($booking['start_datetime'], $tz);
        $end = new \DateTimeImmutable($booking['end_datetime'], $tz);
        $data = [
            'date' => $start->format('Y-m-d'),
            'formatted_date' => Locale::dateLong($start),
            'time' => $start->format('H:i'),
            'end_time' => $end->format('H:i'),
            'duration' => (int) (($end->getTimestamp() - $start->getTimestamp()) / 60)
        ];
        $service = $booking['service_name'] ?? $booking['resource_name'] ?? $booking['event_name'] ?? null;
        $staff = $booking['staff_name'] ?? null;
        $name = $booking['customer_name'];
        $email = $booking['customer_email'];
        $brand = $tenant['brand_color'] ?? '#2563EB';

        if (($payload['audience'] ?? 'customer') === 'staff') {
            // Re-check the preference at delivery time; the owner may have opted out since queueing.
            if (
                !(bool) ($tenant[$kind === 'cancellation' ? 'notify_on_cancellation' : 'notify_on_booking'] ?? false)
            ) return ['sent' => true, 'suppressed' => true];
            if ($kind === 'cancellation') return Mailer::sendStaffCancellationNotification(
                $data,
                $service,
                $staff,
                $name,
                $tenant['name'],
                $tenantId,
                $bookingId,
                $brand
            );

            return Mailer::sendStaffBookingNotification($data, $service, $staff, $name, $tenant['name'], $tenantId, $bookingId, $brand);
        }

        $args = [$email, $name, $data, $service, $staff, $tenant['name'], $tenantId, $bookingId, $brand, $tenant['slug']];
        if ($kind === 'cancellation') return Mailer::sendCancellationConfirmation(...$args);
        if ($kind === 'rescheduled') {
            $original = BookingService::findByIdWithDetails((string) ($payload['original_booking_id'] ?? ''), $tenantId);
            if ($original === null || $original['rescheduled_to_id'] !== $bookingId) return ['sent' => true, 'suppressed' => true];

            return Mailer::sendRescheduleConfirmation(...$args);
        }
        if ($kind === 'approval_confirmed') return Mailer::sendApprovalConfirmed(...$args);

        // Non-timeslot patterns carry their own detail rows; timeslot mails use the default layout.
        $details = null;
        if ($booking['booking_pattern'] === 'resource') {
            $details = [
                __('email.common.room') => $service,
                __('email.common.check_in') => Locale::dateLong($start),
                __('email.common.check_out') => Locale::dateLong($end),
                __('email.common.guests') => (string) $booking['party_size']
            ];
        } elseif ($booking['booking_pattern'] === 'capacity') {
            $details = [
                __('email.common.date') => $data['formatted_date'],
                __('email.common.time') => $data['time'] . '–' . $data['end_time'],
                __('email.common.guests') => (string) $booking['party_size']
            ];
        } elseif ($booking['booking_pattern'] === 'event') {
            $details = [
                __('email.common.date') => $data['formatted_date'],
                __('email.common.time') => $data['time'] . '–' . $data['end_time'],
                __('email.common.service') => $service,
                __('email.common.guests') => (string) $booking['party_size']
            ];
        }
        if ($details !== null && $booking['price_total'] !== null) $details[__('email.common.total')] = __c(
            (float) $booking['price_total'],
            $booking['price_currency'] ?? $tenant['currency']
        );

        $args = [$email, $name, $data, $service, $staff, $tenant['name'], $tenantId, $bookingId, $brand, $details, $tenant['slug']];
        if ($status === 'pending') return Mailer::sendApprovalRequest(...$args);
        if ($status === 'waitlisted') return Mailer::sendWaitlistConfirmation(
            $email,
            $name,
            $data,
            $booking['event_name'] ?? '',
            $tenant['name'],
            $tenantId,
            $bookingId,
            $tenant['brand_color'] ?? '#2563EB',
            $tenant['slug']
        );

        return Mailer::sendBookingConfirmation(...$args);
    }
}
