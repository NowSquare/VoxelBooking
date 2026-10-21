<?php

declare(strict_types=1);

namespace App\Application\Bookings;

use App\Agent\OperationException;

/**
 * Validate answers to a business's booking questions.
 *
 * Customers must answer required questions. Staff may leave them empty,
 * because a booking taken by phone often lacks the answer; any answer staff
 * give must still fit the question's type. Unknown questions are rejected
 * for everyone.
 */
final class BookingFields
{
    public static function answers(array $tenant, mixed $answers, bool $requireAnswers = true): array
    {
        $definitions = json_decode($tenant['custom_fields'] ?? '[]', true) ?: [];
        $answers ??= [];

        // Cap the encoded payload so a hostile client cannot store arbitrarily large blobs per booking.
        if (
            !is_array($answers)
            || strlen(json_encode($answers, JSON_THROW_ON_ERROR)) > 20000
        ) {
            throw new OperationException('validation_failed', 422, ['custom_fields' => 'invalid']);
        }

        $allowed = [];
        $errors = [];

        foreach ($definitions as $field) {
            $key = $field['name'] ?? $field['key'] ?? '';
            if ($key === '') {
                continue;
            }

            $value = $answers[$key] ?? null;
            $type = $field['type'] ?? 'text';

            // A required checkbox means "must be ticked", so false counts as missing.
            $missing = $value === null || $value === '' || ($type === 'checkbox' && $value !== true);
            if ($requireAnswers && ($field['required'] ?? false) && $missing) {
                $errors['custom_fields.' . $key] = 'required';
                continue;
            }
            if ($value === null || $value === '') {
                continue;
            }

            $valid = match ($type) {
                'checkbox' => is_bool($value),
                'number' => is_numeric($value)
                    && (!isset($field['min']) || (float) $value >= (float) $field['min'])
                    && (!isset($field['max']) || (float) $value <= (float) $field['max']),
                'email' => is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
                'date' => is_string($value)
                    && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)
                    && date('Y-m-d', strtotime($value)) === $value,
                'select' => is_string($value) && in_array(
                    $value,
                    array_map(
                        static fn ($option) => (string) (is_array($option) ? $option['value'] : $option),
                        $field['options'] ?? []
                    ),
                    true
                ),
                default => is_string($value) && mb_strlen($value) <= 2000,
            };

            if (!$valid) {
                $errors['custom_fields.' . $key] = 'invalid';
            } else {
                $allowed[$key] = $value;
            }
        }

        $keys = array_map(static fn ($field) => $field['name'] ?? $field['key'] ?? '', $definitions);
        foreach (array_diff(array_keys($answers), $keys) as $key) {
            $errors['custom_fields.' . $key] = 'unsupported';
        }

        if ($errors) {
            throw new OperationException('validation_failed', 422, $errors);
        }

        return $allowed;
    }
}
