<?php

declare(strict_types=1);

namespace App\Engine;

/**
 * HTTP request abstraction.
 *
 * Wraps $_GET, $_POST, $_SERVER, $_FILES. Provides typed accessors.
 * Holds the resolved tenant and authenticated user for the current request.
 */
final class Request
{
    private array $query;
    private array $body;
    private array $server;
    private array $files;
    private array $attributes = [];
    private ?string $rawBody = null;

    public function __construct()
    {
        $this->query = $_GET;
        $this->body = $_POST;
        $this->server = $_SERVER;
        $this->files = $_FILES;
    }

    public function method(): string
    {
        return strtoupper($this->server['REQUEST_METHOD'] ?? 'GET');
    }

    public function path(): string
    {
        $uri = $this->server['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';

        return rawurldecode($path);
    }

    public function string(string $key, string $default = ''): string
    {
        return trim((string) ($this->body[$key] ?? $this->query[$key] ?? $default));
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->body[$key] ?? $this->query[$key] ?? $default;

        return (int) $value;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->body[$key] ?? $this->query[$key] ?? null;

        if ($value === null) {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    public function all(): array
    {
        return array_merge($this->query, $this->body);
    }

    public function query(string $key, string $default = ''): string
    {
        return trim((string) ($this->query[$key] ?? $default));
    }

    public function has(string $key): bool
    {
        return isset($this->body[$key]) || isset($this->query[$key]);
    }

    public function file(string $key): ?array
    {
        return $this->files[$key] ?? null;
    }

    public function header(string $name): ?string
    {
        if (strcasecmp($name, 'Content-Type') === 0) {
            return $this->server['CONTENT_TYPE'] ?? $this->server['HTTP_CONTENT_TYPE'] ?? null;
        }
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));

        return $this->server[$key] ?? null;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        $headers = [];
        foreach ($this->server as $key => $value) {
            if (str_starts_with($key, 'HTTP_') && is_string($value)) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = $value;
            }
        }
        if ($type = $this->header('Content-Type')) { $headers['content-type'] = $type; }
        return $headers;
    }

    public function rawBody(int $maximumBytes = 1048577): string
    {
        if ($this->rawBody === null) {
            $this->rawBody = file_get_contents('php://input', false, null, 0, $maximumBytes) ?: '';
        }
        return $this->rawBody;
    }

    public function jsonObject(): array
    {
        $body = $this->rawBody();
        if (strlen($body) > 1048576
            || strtolower(trim(explode(';', $this->header('Content-Type') ?? '')[0])) !== 'application/json') {
            throw new \UnexpectedValueException('A bounded JSON object is required.');
        }
        $decoded = json_decode($body, false, 64, JSON_THROW_ON_ERROR);
        if (!$decoded instanceof \stdClass) {
            throw new \UnexpectedValueException('A JSON object is required.');
        }
        return json_decode($body, true, 64, JSON_THROW_ON_ERROR);
    }

    public function ip(): string
    {
        return $this->server['REMOTE_ADDR'] ?? '127.0.0.1';
    }

    public function isJson(): bool
    {
        $accept = $this->header('Accept') ?? '';
        $xhr = $this->header('X-Requested-With') ?? '';

        return str_contains($accept, 'application/json') || $xhr === 'XMLHttpRequest';
    }

    public function isSecure(): bool
    {
        return ($this->server['HTTPS'] ?? '') === 'on'
            || ($this->server['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    }

    public function setAttribute(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    public function getAttribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    public function json(): array
    {
        $input = file_get_contents('php://input');

        if ($input === false || $input === '') {
            return [];
        }

        $decoded = json_decode($input, true);

        return is_array($decoded) ? $decoded : [];
    }
}
