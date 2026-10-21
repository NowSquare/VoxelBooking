<?php

declare(strict_types=1);

namespace App\Agent;

final class CanonicalJson
{
    public static function encode(mixed $value): string
    {
        return json_encode(
            self::normalize($value),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
        );
    }

    public static function hash(mixed $value): string
    {
        return hash('sha256', self::encode($value));
    }

    private static function normalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        // Sort map keys so equal payloads hash identically regardless of input key order.
        if (!array_is_list($value)) {
            ksort($value);
        }
        return array_map(self::normalize(...), $value);
    }
}
