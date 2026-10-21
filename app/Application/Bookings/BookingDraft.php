<?php

declare(strict_types=1);

namespace App\Application\Bookings;

use App\Agent\OperationException;
use App\Engine\CapacityCalculator;
use App\Engine\Database;
use App\Engine\EventCalculator;
use App\Engine\ResourceCalculator;
use App\Engine\TimeSlotCalculator;
use App\Engine\VenueDateTime;

/** Validate offering references and calculate one concrete, currently available booking. */
final class BookingDraft
{
    public static function prepare(array $tenant, array $input): array
    {
        $pattern = $tenant['booking_pattern'];
        $tz = new \DateTimeZone($tenant['timezone']);
        $data = ['tenant_id' => $tenant['id'], 'booking_pattern' => $pattern, 'party_size' => 1];
        $display = [];

        switch ($pattern) {
            case 'timeslot':
                $serviceId = BookingCommands::text($input, 'service_id', 26);
                $service = self::offering('services', $serviceId, $tenant['id']);
                $staffId = BookingCommands::text($input, 'staff_id', 26) ?: null;
                if ($staffId !== null) {
                    self::offering('staff', $staffId, $tenant['id']);
                    if (!TimeSlotCalculator::staffCanPerform($tenant['id'], $serviceId, $staffId)) {
                        throw new OperationException('staff_invalid', 422);
                    }
                }

                $date = $input['date'] ?? null;
                $time = $input['time'] ?? null;
                if (isset($input['start_datetime'])) {
                    $raw = BookingCommands::text($input, 'start_datetime', 40);
                    if (!preg_match(
                        '/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(?::\d{2})?(?:Z|[+-]\d{2}:\d{2})?$/D',
                        $raw
                    )) throw new OperationException('invalid_date', 422);
                    $start = (new \DateTimeImmutable($raw, $tz))->setTimezone($tz);
                    $date = $start->format('Y-m-d');
                    $time = $start->format('H:i');
                    // Without an offset the input is venue wall time; a DST gap would silently shift it.
                    if (
                        !preg_match('/(?:Z|[+-]\d{2}:\d{2})$/', $raw)
                        && substr(str_replace('T', ' ', $raw), 0, 16) !== "$date $time"
                    ) throw new OperationException('invalid_date', 422);
                }

                $date = self::date($date);
                if (
                    !is_string($time)
                    || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/D', $time)
                    || !VenueDateTime::wallTimeExists($date, $time, $tz)
                ) throw new OperationException('invalid_time', 422);

                $slots = TimeSlotCalculator::getAvailableSlots($tenant, $date, $serviceId, $staffId)['slots'];
                $slot = null;
                foreach ($slots as $candidate) if ($candidate['time'] === $time) {
                    $slot = $candidate;
                    break;
                }
                if ($slot === null) throw new OperationException('slot_unavailable', 409);

                $start = new \DateTimeImmutable("$date $time", $tz);
                $end = $start->modify('+' . (int) $service['duration_minutes'] . ' minutes');
                $data += ['service_id' => $serviceId, 'staff_id' => $slot['staff_id']];
                $staffName = $slot['staff_id'] ? self::offering('staff', $slot['staff_id'], $tenant['id'])['name'] : null;
                $display = [
                    'service' => $service['name'],
                    'staff' => $staffName,
                    'duration' => (int) $service['duration_minutes'],
                    'total' => $service['price'] === null ? null : (string) $service['price']
                ];
                break;

            case 'resource':
                $id = BookingCommands::text($input, 'resource_id', 26);
                $checkIn = self::date($input['check_in'] ?? null);
                $checkOut = self::date($input['check_out'] ?? null);
                $size = self::count($input['guest_count'] ?? $input['party_size'] ?? 1);
                $availability = ResourceCalculator::checkAvailability($tenant, $id, $checkIn, $checkOut, $size);
                self::available($availability);
                $data += ['resource_id' => $id];
                $data['party_size'] = $size;
                $start = new \DateTimeImmutable($checkIn, $tz);
                $end = new \DateTimeImmutable($checkOut, $tz);
                $display = [
                    'resource' => $availability['resource']['name'],
                    'check_in' => $checkIn,
                    'check_out' => $checkOut,
                    'nights' => $availability['nights'],
                    'guest_count' => $size,
                    'total' => sprintf('%.2f', $availability['total'])
                ];
                break;

            case 'capacity':
                $id = BookingCommands::text($input, 'slot_id', 26);
                $date = self::date($input['date'] ?? null);
                $size = self::count($input['party_size'] ?? 1);
                $slot = self::offering('capacity_slots', $id, $tenant['id']);
                self::available(CapacityCalculator::checkSlotAvailability($tenant, $id, $date, $size));
                $start = new \DateTimeImmutable($date . ' ' . $slot['start_time'], $tz);
                $end = new \DateTimeImmutable($date . ' ' . $slot['end_time'], $tz);
                if ($end <= $start) throw new OperationException('invalid_time', 422);
                $data['capacity_slot_id'] = $id;
                $data['party_size'] = $size;
                $display = ['label' => $slot['label'], 'party_size' => $size];
                break;

            case 'event':
                $id = BookingCommands::text($input, 'event_id', 26);
                $event = self::offering('events', $id, $tenant['id']);
                $date = self::date(($input['date'] ?? '') ?: substr($event['start_datetime'], 0, 10));
                $size = self::count($input['spot_count'] ?? $input['party_size'] ?? 1);
                $availability = EventCalculator::checkAvailability($tenant, $id, $date, $size);
                self::available($availability);
                $range = VenueDateTime::eventRange($event['start_datetime'], $event['end_datetime'], $date, $tz);
                $start = $range['start'];
                $end = $range['end'];
                if ($end <= $start) throw new OperationException('invalid_time', 422);
                $data += ['event_id' => $id, 'status' => $availability['waitlisted'] ? 'waitlisted' : 'confirmed'];
                $data['party_size'] = $size;
                $display = [
                    'event_name' => $event['name'],
                    'spot_count' => $size,
                    'waitlisted' => $availability['waitlisted'],
                    'total' => $event['price'] === null ? null : self::multiplyMoney((string) $event['price'], $size)
                ];
                break;

            default:
                throw new OperationException('pattern_not_supported', 422);
        }

        // Stays are booked by calendar day, so only past days are refused; other patterns honour the lead time.
        $now = new \DateTimeImmutable('now', $tz);
        if (
            ($pattern === 'resource' && $start < $now->setTime(0, 0))
            || ($pattern !== 'resource' && ($start <= $now || $start < $now->modify('+' . (int) $tenant['min_advance_hours'] . ' hours')))
        ) throw new OperationException('too_soon', 409);
        if (
            $start > $now->modify('+' . (int) $tenant['max_advance_days'] . ' days')->setTime(23, 59, 59)
        ) throw new OperationException('too_far', 409);

        $data['price_total'] = $display['total'] ?? null;
        $data['price_currency'] = $tenant['currency'];
        $data += ['start_datetime' => $start->format('Y-m-d H:i:s'), 'end_datetime' => $end->format('Y-m-d H:i:s')];

        return [
            'data' => $data,
            'display' => $display + ['date' => $start->format('Y-m-d'), 'time' => $start->format('H:i'), 'end_time' => $end->format('H:i')]
        ];
    }

    public static function multiplyMoney(string $amount, int $quantity): string
    {
        // Integer cents avoid float rounding on money.
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '00');
        $cents = ((int) $whole * 100 + (int) str_pad(substr($fraction, 0, 2), 2, '0')) * $quantity;

        return intdiv($cents, 100) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function date(mixed $value): string
    {
        if (
            !is_string($value)
            || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $parts)
            || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])
        ) throw new OperationException('invalid_date', 422);

        return $value;
    }

    private static function count(mixed $value): int
    {
        if (
            filter_var($value, FILTER_VALIDATE_INT) === false
            || (int) $value < 1
            || (int) $value > 10000
        ) throw new OperationException('invalid_party_size', 422);

        return (int) $value;
    }

    private static function offering(string $table, string $id, string $tenantId): array
    {
        return Database::query(
            "SELECT * FROM `$table` WHERE id = ? AND tenant_id = ? AND is_active = 1",
            [$id, $tenantId]
        )[0] ?? throw new OperationException('not_found', 404);
    }

    private static function available(array $result): void
    {
        if (!$result['available']) throw new OperationException($result['error'] ?: 'slot_unavailable', 409);
    }
}
