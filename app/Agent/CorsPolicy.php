<?php

declare(strict_types=1);

namespace App\Agent;

/** Browser access is an explicit deployment choice, separate from connection permissions. */
final class CorsPolicy
{
    private const HEADERS = [
        'accept',
        'authorization',
        'content-type',
        'mcp-protocol-version',
        'mcp-method',
        'mcp-name',
        'idempotency-key',
        'x-booking-continuation',
    ];

    private array $allowedOrigins;

    public function __construct(string $canonicalBase, string $additionalOrigins = '')
    {
        $base = parse_url($canonicalBase);
        if (!is_array($base) || !isset($base['scheme'], $base['host']) || isset($base['user']) || isset($base['pass'])) {
            throw new OperationException('origin_configuration_invalid', 503);
        }
        // Name the real problem: an http APP_URL on a public host is a deployment fix, not a bad origin list.
        if ($base['scheme'] === 'http' && !Endpoints::allowsPlainHttp($base['host'])) {
            throw new OperationException('https_required', 503, nextAction: 'configure_https');
        }
        $origin = $base['scheme'] . '://' . $base['host'] . (isset($base['port']) ? ':' . $base['port'] : '');
        $configured = $additionalOrigins === '' ? [] : explode(',', $additionalOrigins);
        if (count($configured) > 20 || strlen($additionalOrigins) > 4096) {
            throw new OperationException('origin_configuration_invalid', 503);
        }
        $this->allowedOrigins = [$origin];
        foreach (array_merge([$origin], $configured) as $value) {
            // Do not trim control characters: they must make configuration fail closed.
            $value = trim($value, ' ');
            if (!self::validOrigin($value)) {
                throw new OperationException('origin_configuration_invalid', 503);
            }
            $this->allowedOrigins[] = $value;
        }
        $this->allowedOrigins = array_values(array_unique($this->allowedOrigins));
    }

    public static function configured(): self
    {
        return new self(Endpoints::baseUrl(), (string) ($_ENV['AGENT_ALLOWED_ORIGINS'] ?? ''));
    }

    public function origins(): array
    {
        return $this->allowedOrigins;
    }

    public function permits(?string $origin): bool
    {
        return $origin === null || in_array($origin, $this->allowedOrigins, true);
    }

    public function responseHeaders(?string $origin): array
    {
        if ($origin === null || !$this->permits($origin)) {
            return [];
        }
        return [
            'Access-Control-Allow-Origin' => $origin,
            'Access-Control-Expose-Headers' => 'WWW-Authenticate, Retry-After, X-RateLimit-Limit, X-RateLimit-Remaining, X-RateLimit-Reset, X-Request-Id, MCP-Protocol-Version',
        ];
    }

    public function preflight(?string $origin, string $method, string $requestedHeaders, array $allowedMethods): array
    {
        if ($origin === null
            || !$this->permits($origin)
            || !in_array($method, $allowedMethods, true)
            || strlen($requestedHeaders) > 2048) {
            throw new OperationException('cors_preflight_denied', 403);
        }
        $headers = $requestedHeaders === '' ? [] : explode(',', strtolower($requestedHeaders));
        if (count($headers) > 32) {
            throw new OperationException('cors_preflight_denied', 403);
        }
        foreach ($headers as &$header) {
            $header = trim($header, ' ');
            if (!in_array($header, self::HEADERS, true)) {
                throw new OperationException('cors_preflight_denied', 403);
            }
        }
        unset($header);
        $result = $this->responseHeaders($origin) + [
            'Access-Control-Allow-Methods' => $method,
            'Access-Control-Max-Age' => '600',
        ];
        if ($headers !== []) {
            $result['Access-Control-Allow-Headers'] = implode(', ', array_unique($headers));
        }
        return $result;
    }

    private static function validOrigin(string $origin): bool
    {
        if (strlen($origin) > 256 || preg_match('/[\x00-\x20\x7f]/', $origin)) {
            return false;
        }
        $parts = parse_url($origin);
        // An origin is scheme, host and optional port only; any path, query or credentials disqualify it.
        if (!is_array($parts)
            || !isset($parts['scheme'], $parts['host'])
            || array_diff(array_keys($parts), ['scheme', 'host', 'port'])) {
            return false;
        }
        $host = $parts['host'];
        $plainHttpAllowed = Endpoints::allowsPlainHttp($host);
        if ($parts['scheme'] !== 'https' && !($parts['scheme'] === 'http' && $plainHttpAllowed)) {
            return false;
        }
        $loopback = in_array($host, ['localhost', '127.0.0.1', '[::1]'], true);
        if (!$loopback && !filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
            return false;
        }
        return !isset($parts['port']) || ($parts['port'] >= 1 && $parts['port'] <= 65535);
    }
}
