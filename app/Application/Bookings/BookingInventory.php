<?php

declare(strict_types=1);

namespace App\Application\Bookings;

use App\Agent\OperationException;
use App\Engine\Database;

/** Caller holds the tenant lock; transitions must reacquire current inventory. */
final class BookingInventory
{
    public static function assertAvailable(array $tenant, array $booking): void
    {
        // A rescheduled row keeps its slot only until its replacement exists.
        $occupied = "status IN ('confirmed','rescheduled') AND (status != 'rescheduled' OR NULLIF(rescheduled_to_id,'') IS NULL)";
        $bindings = [$tenant['id'], $booking['id']];
        $where = "tenant_id = ? AND id <> ? AND $occupied";

        switch ($booking['booking_pattern']) {
            case 'timeslot':
                $duration = (int) ((strtotime($booking['end_datetime']) - strtotime($booking['start_datetime'])) / 60);
                $slots = \App\Engine\TimeSlotCalculator::getAvailableSlots(
                    $tenant,
                    substr($booking['start_datetime'], 0, 10),
                    $booking['service_id'] ?? null,
                    $booking['staff_id'] ?? null,
                    $booking['id'],
                    $duration,
                    true
                )['slots'];

                // The calculator is the authority on bookable times; the SQL below only catches races.
                // A booking without staff needs one free eligible staff member, or free
                // opening hours when the business has no staff, just as a new booking would.
                $staffId = $booking['staff_id'] ?? null;
                $eligible = false;
                foreach ($slots as $slot) {
                    if (
                        $slot['time'] === substr($booking['start_datetime'], 11, 5)
                        && $slot['end_time'] === substr($booking['end_datetime'], 11, 5)
                        && ($staffId === null || $slot['staff_id'] === $staffId)
                    ) {
                        $eligible = true;
                        break;
                    }
                }
                if (!$eligible) throw new OperationException('inventory_conflict', 409);

                $buffer = max(0, (int) ($tenant['buffer_minutes'] ?? 0));
                $conflict = Database::query(
                    // Null-safe equality: staffed and staff-less bookings occupy different schedules.
                    "SELECT id FROM bookings WHERE $where AND staff_id <=> ? AND start_datetime < DATE_ADD(?, INTERVAL ? MINUTE) AND DATE_ADD(end_datetime, INTERVAL ? MINUTE) > ? LIMIT 1",
                    [
                        ...$bindings,
                        $staffId,
                        $booking['end_datetime'],
                        $buffer,
                        $buffer,
                        $booking['start_datetime']
                    ]
                );
                if ($conflict) throw new OperationException('inventory_conflict', 409);
                break;

            case 'resource':
                $availability = \App\Engine\ResourceCalculator::checkAvailability(
                    $tenant,
                    $booking['resource_id'],
                    substr($booking['start_datetime'], 0, 10),
                    substr($booking['end_datetime'], 0, 10),
                    (int) $booking['party_size'],
                    $booking['id'],
                    true
                );
                if (!$availability['available']) throw new OperationException('inventory_conflict', 409);

                if (Database::query(
                    "SELECT id FROM bookings WHERE $where AND resource_id = ? AND start_datetime < ? AND end_datetime > ? LIMIT 1",
                    [...$bindings, $booking['resource_id'], $booking['end_datetime'], $booking['start_datetime']]
                )) throw new OperationException('inventory_conflict', 409);
                break;

            case 'capacity':
                $date = new \DateTimeImmutable($booking['start_datetime'], new \DateTimeZone($tenant['timezone']));
                $slot = Database::query(
                    'SELECT max_capacity,min_party_size,max_party_size FROM capacity_slots WHERE tenant_id = ? AND day_of_week = ? AND start_time = ? AND end_time = ? AND is_active = 1 AND (? IS NULL OR id=?)',
                    [
                        $tenant['id'],
                        (int) $date->format('N') - 1,
                        substr($booking['start_datetime'], 11),
                        substr($booking['end_datetime'], 11),
                        $booking['capacity_slot_id'] ?? null,
                        $booking['capacity_slot_id'] ?? null
                    ]
                )[0] ?? null;
                $used = Database::query(
                    "SELECT COALESCE(SUM(party_size),0) AS used FROM bookings WHERE $where AND booking_pattern = 'capacity' AND start_datetime = ?",
                    [...$bindings, $booking['start_datetime']]
                )[0]['used'];

                // A business-wide blocked date closes every capacity slot on that day.
                if (
                    $slot === null
                    || (int) $booking['party_size'] < (int) $slot['min_party_size']
                    || (int) $booking['party_size'] > (int) $slot['max_party_size']
                    || Database::query(
                        'SELECT 1 FROM blocked_dates WHERE tenant_id=? AND staff_id IS NULL AND resource_id IS NULL AND start_date<=? AND end_date>=?',
                        [$tenant['id'], substr($booking['start_datetime'], 0, 10), substr($booking['start_datetime'], 0, 10)]
                    )
                    || (int) $used + (int) $booking['party_size'] > (int) $slot['max_capacity']
                ) throw new OperationException('inventory_conflict', 409);
                break;

            case 'event':
                $occurrence = \App\Engine\EventCalculator::getEventDetail(
                    $tenant,
                    $booking['event_id'],
                    substr($booking['start_datetime'], 0, 10)
                )['event'];
                if (
                    $occurrence === null
                    || $occurrence['start_datetime'] !== $booking['start_datetime']
                    || $occurrence['end_datetime'] !== $booking['end_datetime']
                ) throw new OperationException('inventory_conflict', 409);

                $availability = \App\Engine\EventCalculator::checkAvailability(
                    $tenant,
                    $booking['event_id'],
                    substr($booking['start_datetime'], 0, 10),
                    (int) $booking['party_size'],
                    $booking['id']
                );
                if (!$availability['available'] || $availability['waitlisted']) throw new OperationException('inventory_conflict', 409);

                $event = Database::query(
                    'SELECT max_participants FROM events WHERE id = ? AND tenant_id = ? AND is_active = 1',
                    [$booking['event_id'], $tenant['id']]
                )[0] ?? null;
                $used = Database::query(
                    "SELECT COALESCE(SUM(party_size),0) AS used FROM bookings WHERE $where AND event_id = ? AND DATE(start_datetime) = ?",
                    [...$bindings, $booking['event_id'], substr($booking['start_datetime'], 0, 10)]
                )[0]['used'];
                if (
                    $event === null
                    || (int) $used + (int) $booking['party_size'] > (int) $event['max_participants']
                ) throw new OperationException('inventory_conflict', 409);
                break;

            default:
                throw new OperationException('pattern_not_supported', 422);
        }
    }
}
