<?php

declare(strict_types=1);

namespace App\Application\Catalog;

use App\Agent\OperationException;

/** The fields implemented by existing catalog forms, shared with API schemas. */
final class CatalogFields
{
    public const TYPES = [
        'services' => ['scope' => 'services', 'entity' => 'service', 'patterns' => ['timeslot'], 'capability' => 'C12'],
        'staff' => ['scope' => 'staff', 'entity' => 'staff', 'patterns' => ['timeslot'], 'capability' => 'C13'],
        'resources' => ['scope' => 'resources', 'entity' => 'resource', 'patterns' => ['resource'], 'capability' => 'C16'],
        'capacity_slots' => ['scope' => 'capacity', 'entity' => 'capacity_slot', 'patterns' => ['capacity'], 'capability' => 'C17'],
        'events' => ['scope' => 'events', 'entity' => 'event', 'patterns' => ['event'], 'capability' => 'C18'],
        'blocked_dates' => ['scope' => 'availability', 'entity' => 'blocked_date', 'patterns' => ['timeslot', 'resource', 'capacity'], 'capability' => 'C15'],
    ];

    public static function definition(string $kind): array
    {
        return self::TYPES[$kind] ?? throw new \InvalidArgumentException('Unknown catalog kind.');
    }

    public static function properties(string $kind): array
    {
        $text = static fn (int $max, bool $nullable = false): array => ['type' => $nullable ? ['string', 'null'] : 'string', 'maxLength' => $max];
        $integer = static fn (int $min, int $max): array => ['type' => 'integer', 'minimum' => $min, 'maximum' => $max];
        $money = ['type' => ['string', 'null'], 'pattern' => '^[0-9]{1,8}(\\.[0-9]{1,2})?$', 'description' => 'Decimal amount in the business currency. Null means no listed price.'];
        $ids = ['type' => 'array', 'maxItems' => 1000, 'uniqueItems' => true, 'items' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 26]];
        $date = ['type' => 'string', 'format' => 'date'];
        $time = ['type' => 'string', 'pattern' => '^([01][0-9]|2[0-3]):[0-5][0-9](:00)?$'];
        return match ($kind) {
            'services' => [
                'name' => $text(255), 'description' => $text(20000, true), 'duration_minutes' => $integer(1, 1440),
                'price' => $money, 'price_label' => $text(100, true), 'category' => $text(100, true),
                'color' => ['type' => ['string', 'null'], 'pattern' => '^#[0-9a-fA-F]{6}$'], 'staff_ids' => $ids,
            ],
            'staff' => [
                'name' => $text(255), 'email' => [...$text(255), 'format' => 'email'],
                'phone' => $text(50, true), 'title' => $text(255, true), 'bio' => $text(20000, true), 'service_ids' => $ids,
            ],
            'resources' => [
                'name' => $text(255), 'description' => $text(20000, true), 'capacity' => $integer(1, 10000),
                'price_per_night' => $money, 'min_stay_nights' => $integer(1, 3650), 'max_stay_nights' => $integer(1, 3650),
                'amenities' => ['type' => 'array', 'maxItems' => 100, 'items' => $text(100)],
                'check_in_days' => ['type' => ['array', 'null'], 'uniqueItems' => true, 'maxItems' => 7, 'items' => $integer(0, 6), 'description' => '0 is Sunday; null allows every day.'],
                'check_out_days' => ['type' => ['array', 'null'], 'uniqueItems' => true, 'maxItems' => 7, 'items' => $integer(0, 6), 'description' => '0 is Sunday; null allows every day.'],
                'seasonal_pricing' => ['type' => 'array', 'maxItems' => 100, 'items' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['start_date', 'end_date', 'price_per_night'], 'properties' => ['start_date' => $date, 'end_date' => $date, 'price_per_night' => $money, 'label' => $text(100, true)]]],
            ],
            'capacity_slots' => [
                'day_of_week' => [...$integer(0, 6), 'description' => '0 is Monday; 6 is Sunday.'],
                'start_time' => $time, 'end_time' => $time, 'max_capacity' => $integer(1, 10000),
                'min_party_size' => $integer(1, 1000), 'max_party_size' => $integer(1, 1000), 'label' => $text(100, true),
            ],
            'events' => [
                'name' => $text(200), 'description' => $text(20000, true), 'location' => $text(255, true), 'price' => $money,
                'start_datetime' => ['type' => 'string', 'format' => 'date-time'], 'end_datetime' => ['type' => 'string', 'format' => 'date-time'],
                'max_participants' => $integer(1, 10000), 'min_spot_count' => $integer(1, 1000),
                'max_spot_count' => ['type' => ['integer', 'null'], 'minimum' => 1, 'maximum' => 10000],
                'recurrence' => ['type' => ['object', 'null'], 'additionalProperties' => false, 'required' => ['frequency', 'count'], 'properties' => ['frequency' => ['type' => 'string', 'enum' => ['DAILY', 'WEEKLY', 'MONTHLY']], 'count' => $integer(1, 3660)]],
                'exception_dates' => ['type' => 'array', 'uniqueItems' => true, 'maxItems' => 1000, 'items' => $date],
                'allow_waitlist' => ['type' => 'boolean'], 'waitlist_max' => $integer(0, 10000),
            ],
            'blocked_dates' => [
                'start_date' => $date, 'end_date' => $date, 'reason' => $text(255, true),
                'staff_id' => ['type' => ['string', 'null'], 'maxLength' => 26], 'resource_id' => ['type' => ['string', 'null'], 'maxLength' => 26],
            ],
        };
    }

    public static function required(string $kind): array
    {
        return match ($kind) {
            'services' => ['name', 'duration_minutes'],
            'staff' => ['name', 'email'],
            'resources' => ['name'],
            'capacity_slots' => ['day_of_week', 'start_time', 'end_time', 'max_capacity', 'max_party_size'],
            'events' => ['name', 'start_datetime', 'end_datetime', 'max_participants'],
            'blocked_dates' => ['start_date', 'end_date'],
        };
    }

    public static function defaults(string $kind): array
    {
        return match ($kind) {
            'services' => ['description' => null, 'price' => null, 'price_label' => null, 'category' => null, 'color' => null, 'staff_ids' => []],
            'staff' => ['phone' => null, 'title' => null, 'bio' => null, 'service_ids' => []],
            'resources' => ['description' => null, 'capacity' => 1, 'price_per_night' => null, 'min_stay_nights' => 1, 'max_stay_nights' => 30, 'amenities' => [], 'check_in_days' => null, 'check_out_days' => null, 'seasonal_pricing' => []],
            'capacity_slots' => ['min_party_size' => 1, 'label' => null],
            'events' => ['description' => null, 'location' => null, 'price' => null, 'min_spot_count' => 1, 'max_spot_count' => null, 'recurrence' => null, 'exception_dates' => [], 'allow_waitlist' => false, 'waitlist_max' => 5],
            'blocked_dates' => ['reason' => null, 'staff_id' => null, 'resource_id' => null],
        };
    }

    /** Validate even for in-process UI callers that bypass the transport schema. */
    public static function validate(string $kind, array $data, array $tenant): array
    {
        $properties = self::properties($kind);
        $result = [];
        foreach (self::required($kind) as $key) {
            if (!array_key_exists($key, $data)) {
                self::invalid($key, 'required');
            }
        }
        foreach ($properties as $key => $schema) {
            if (!array_key_exists($key, $data)) {
                continue;
            }
            $value = $data[$key];
            if (is_string($value)) {
                $value = trim($value);
            }
            self::validateValue($key, $value, $schema);
            if (in_array($key, ['name', 'email'], true) && $value === '') {
                self::invalid($key, 'required');
            }
            if (in_array($key, ['start_time', 'end_time'], true)) {
                $value = substr($value, 0, 5) . ':00';
            }
            if (in_array($key, ['price', 'price_per_night'], true) && $value !== null) {
                $value = number_format((float) $value, 2, '.', '');
            }
            $result[$key] = $value;
        }
        if ($kind === 'resources') {
            if ($result['min_stay_nights'] > $result['max_stay_nights']) {
                self::invalid('max_stay_nights', 'min_exceeds_max');
            }
            $seasons = $result['seasonal_pricing'];
            usort($seasons, static fn (array $a, array $b): int => strcmp($a['start_date'], $b['start_date']));
            $lastEnd = null;
            foreach ($seasons as $season) {
                if ($season['end_date'] < $season['start_date'] || $season['price_per_night'] === null
                    || ($lastEnd !== null && $season['start_date'] <= $lastEnd)) {
                    self::invalid('seasonal_pricing', 'invalid_or_overlapping_range');
                }
                $lastEnd = $season['end_date'];
            }
            $result['seasonal_pricing'] = $seasons;
            foreach (['check_in_days', 'check_out_days'] as $field) {
                if ($result[$field] !== null) {
                    sort($result[$field]);
                    if (count($result[$field]) === 0 || count($result[$field]) === 7) {
                        $result[$field] = null;
                    }
                }
            }
        }
        if ($kind === 'capacity_slots') {
            if ($result['start_time'] >= $result['end_time']) {
                self::invalid('end_time', 'end_before_start');
            }
            if ($result['min_party_size'] > $result['max_party_size'] || $result['max_party_size'] > $result['max_capacity']) {
                self::invalid('max_party_size', 'party_exceeds_capacity');
            }
        }
        if ($kind === 'events') {
            $zone = new \DateTimeZone($tenant['timezone']);
            foreach (['start_datetime', 'end_datetime'] as $field) {
                $instant = new \DateTimeImmutable($result[$field]);
                $result[$field] = $instant->setTimezone($zone)->format('Y-m-d H:i:s');
            }
            if ($result['end_datetime'] <= $result['start_datetime']) {
                self::invalid('end_datetime', 'end_before_start');
            }
            if ($result['min_spot_count'] > ($result['max_spot_count'] ?? $result['max_participants'])
                || ($result['max_spot_count'] ?? 1) > $result['max_participants']) {
                self::invalid('max_spot_count', 'party_exceeds_capacity');
            }
            $result['is_recurring'] = $result['recurrence'] !== null ? 1 : 0;
            $result['rrule'] = $result['recurrence'] === null ? null : 'FREQ=' . $result['recurrence']['frequency'] . ';COUNT=' . $result['recurrence']['count'];
            unset($result['recurrence']);
        }
        if ($kind === 'blocked_dates') {
            $today = (new \DateTimeImmutable('now', new \DateTimeZone($tenant['timezone'])))->format('Y-m-d');
            if ($result['end_date'] < $result['start_date'] || $result['start_date'] < $today) {
                self::invalid('start_date', 'invalid_or_past_range');
            }
            if (($result['staff_id'] !== null && $tenant['booking_pattern'] !== 'timeslot')
                || ($result['resource_id'] !== null && $tenant['booking_pattern'] !== 'resource')
                || ($result['staff_id'] !== null && $result['resource_id'] !== null)) {
                self::invalid('staff_id', 'unsupported_target');
            }
        }
        return $result;
    }

    private static function validateValue(string $key, mixed $value, array $schema): void
    {
        $types = (array) $schema['type'];
        $type = match (true) {
            $value === null => 'null', is_bool($value) => 'boolean', is_int($value) => 'integer',
            is_string($value) => 'string', is_array($value) && array_is_list($value) => 'array', is_array($value) => 'object',
            default => 'unknown',
        };
        if (!in_array($type, $types, true) && !($value === [] && in_array('object', $types, true))) {
            self::invalid($key, 'invalid_type');
        }
        if ($value === null) {
            return;
        }
        if (isset($schema['enum']) && !in_array($value, $schema['enum'], true)) {
            self::invalid($key, 'invalid_value');
        }
        if (is_string($value)) {
            if (mb_strlen($value) > ($schema['maxLength'] ?? PHP_INT_MAX) || mb_strlen($value) < ($schema['minLength'] ?? 0)
                || (isset($schema['pattern']) && preg_match('~' . $schema['pattern'] . '~D', $value) !== 1)) {
                self::invalid($key, 'invalid_value');
            }
            if (($schema['format'] ?? null) === 'email' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
                self::invalid($key, 'invalid_email');
            }
            if (($schema['format'] ?? null) === 'date') {
                $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
                if (!$date || $date->format('Y-m-d') !== $value) {
                    self::invalid($key, 'invalid_date');
                }
            }
            if (($schema['format'] ?? null) === 'date-time') {
                if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/D', $value)) {
                    self::invalid($key, 'offset_required');
                }
                try {
                    $date = new \DateTimeImmutable($value);
                    if ($date->format('Y-m-d\TH:i:s') !== substr($value, 0, 19)) {
                        self::invalid($key, 'invalid_datetime');
                    }
                } catch (\Exception) {
                    self::invalid($key, 'invalid_datetime');
                }
            }
        }
        if (is_int($value) && ($value < ($schema['minimum'] ?? PHP_INT_MIN) || $value > ($schema['maximum'] ?? PHP_INT_MAX))) {
            self::invalid($key, 'out_of_range');
        }
        if ($type === 'array') {
            if (count($value) > ($schema['maxItems'] ?? PHP_INT_MAX)
                || (($schema['uniqueItems'] ?? false) && count(array_unique(array_map('serialize', $value))) !== count($value))) {
                self::invalid($key, 'invalid_list');
            }
            foreach ($value as $item) {
                self::validateValue($key, $item, $schema['items']);
            }
        }
        if ($type === 'object') {
            foreach ($schema['required'] ?? [] as $required) {
                if (!array_key_exists($required, $value)) {
                    self::invalid($key, 'required');
                }
            }
            foreach ($value as $field => $item) {
                if (!isset($schema['properties'][$field])) {
                    self::invalid($key, 'unknown_field');
                }
                self::validateValue($key . '.' . $field, $item, $schema['properties'][$field]);
            }
        }
    }

    public static function invalid(string $field, string $reason): never
    {
        throw new OperationException('validation_failed', 422, [$field => $reason]);
    }
}
