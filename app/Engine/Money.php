<?php

declare(strict_types=1);

namespace App\Engine;

/**
 * Exact arithmetic on two-decimal money amounts.
 *
 * Prices are DECIMAL(10,2) columns and leave the system as fixed two-decimal
 * strings. Adding them as floats drifts away from the cent over enough terms,
 * so sums and products run on integer minor units (cents) instead. Display
 * formatting is not done here; that belongs to the Locale engine.
 */
final class Money
{
    /** A stored decimal amount in cents. Digits past the second decimal are not money and are dropped. */
    public static function toCents(string|int|float $amount): int
    {
        $text = is_float($amount) ? sprintf('%.2f', $amount) : (string) $amount;
        if (!preg_match('/^(-?)(\d+)(?:\.(\d*))?$/D', trim($text), $parts)) {
            throw new \InvalidArgumentException('Not a decimal amount.');
        }
        $cents = (int) $parts[2] * 100 + (int) str_pad(substr($parts[3] ?? '', 0, 2), 2, '0');

        return $parts[1] === '-' ? -$cents : $cents;
    }

    /** Cents as the fixed two-decimal string the API publishes, e.g. 19095 -> "190.95". */
    public static function fromCents(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);

        return $sign . intdiv($cents, 100) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }
}
