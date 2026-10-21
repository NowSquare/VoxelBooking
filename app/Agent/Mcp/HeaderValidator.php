<?php

declare(strict_types=1);

namespace App\Agent\Mcp;

final class HeaderValidator
{
    public static function parameters(array $headers, array $schema, array $arguments): void
    {
        foreach ($schema['properties'] ?? [] as $name => $property) {
            if (isset($property['x-mcp-header'])) {
                $headerName = 'Mcp-Param-' . $property['x-mcp-header'];
                $value = $arguments[$name] ?? null;
                if ($value === null) {
                    if (array_key_exists(strtolower($headerName), $headers)) {
                        throw new ProtocolError(-32020, 'Header mismatch', data: ['header' => $headerName]);
                    }
                } elseif (is_string($value) || is_int($value) || is_bool($value)) {
                    self::matching($headers, $headerName, is_bool($value) ? ($value ? 'true' : 'false') : (string) $value, true);
                } else {
                    throw new ProtocolError(-32020, 'Header mismatch', data: ['header' => $headerName]);
                }
            }
            if (isset($property['properties'])) {
                self::parameters($headers, $property, is_array($arguments[$name] ?? null) ? $arguments[$name] : []);
            }
        }
    }

    public static function matching(array $headers, string $name, string $expected, bool $encoded = false): void
    {
        $value = $headers[strtolower($name)] ?? null;
        if (!is_string($value) || !preg_match('/^[\x20-\x7E\t]+$/D', $value) || trim($value) !== $value) {
            throw new ProtocolError(-32020, 'Header mismatch', data: ['header' => $name]);
        }
        if ($encoded && str_starts_with($value, '=?base64?') && str_ends_with($value, '?=')) {
            $decoded = base64_decode(substr($value, 9, -2), true);
            if ($decoded === false || !preg_match('//u', $decoded)) {
                throw new ProtocolError(-32020, 'Header mismatch', data: ['header' => $name]);
            }
            $value = $decoded;
        }
        if ($value !== $expected) {
            throw new ProtocolError(-32020, 'Header mismatch', data: ['header' => $name]);
        }
    }
}
