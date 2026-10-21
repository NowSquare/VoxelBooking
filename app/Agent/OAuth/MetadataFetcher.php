<?php

declare(strict_types=1);

namespace App\Agent\OAuth;

use Closure;
use InvalidArgumentException;

/** Public HTTPS only, no proxy/redirects, DNS-pinned connection and a bounded body. */
final class MetadataFetcher
{
    public function __construct(
        private readonly ?Closure $resolve = null,
        private readonly ?Closure $request = null
    ) {}

    public function fetch(string $url): array
    {
        $parts = parse_url($url);
        if (strlen($url) > 512 || !preg_match('/^[\x21-\x7E]+$/D', $url) || !is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https' || empty($parts['host']) || empty($parts['path'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || (isset($parts['port']) && $parts['port'] !== 443)) {
            throw new InvalidArgumentException('invalid_client_id');
        }
        $host = $parts['host'];
        if (!preg_match('/^[a-zA-Z0-9.-]+$/D', $host) || str_ends_with($host, '.')) {
            throw new InvalidArgumentException('invalid_client_id');
        }
        $addresses = $this->resolve !== null ? ($this->resolve)($host) : $this->addresses($host);
        if ($addresses === []) {
            throw new InvalidArgumentException('client_metadata_unavailable');
        }
        foreach ($addresses as $address) {
            // Every resolved address must be public; IPv4-mapped IPv6 could otherwise smuggle a private target.
            if (!is_string($address)
                || !filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)
                || str_starts_with(strtolower($address), '::ffff:')) {
                throw new InvalidArgumentException('invalid_client_id');
            }
        }
        $response = $this->request !== null
            ? ($this->request)($url, $host, $addresses[0])
            : $this->download($url, $host, $addresses[0]);
        if (($response['status'] ?? null) !== 200
            || !is_string($response['body'] ?? null)
            || strlen($response['body']) > 65536) {
            throw new InvalidArgumentException('client_metadata_unavailable');
        }
        try {
            $document = json_decode($response['body'], true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new InvalidArgumentException('invalid_client_metadata');
        }
        if (!is_array($document) || array_is_list($document)) {
            throw new InvalidArgumentException('invalid_client_metadata');
        }
        $metadata = ClientMetadata::validate($document, $url);
        $cache = strtolower($response['cache_control'] ?? '');
        $ttl = preg_match('/(?:^|,)\s*max-age=(\d+)/', $cache, $matches) ? min(3600, (int) $matches[1]) : 300;
        if (str_contains($cache, 'no-store') || str_contains($cache, 'no-cache')) {
            $ttl = 0;
        }
        return ['metadata' => $metadata, 'ttl' => $ttl];
    }

    private function addresses(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }
        $records = dns_get_record($host, DNS_A | DNS_AAAA);
        return $records === false ? [] : array_values(array_unique(array_filter(array_map(
            static fn (array $record): ?string => $record['ip'] ?? $record['ipv6'] ?? null,
            $records,
        ))));
    }

    private function download(string $url, string $host, string $address): array
    {
        $handle = curl_init($url);
        if ($handle === false) {
            throw new InvalidArgumentException('client_metadata_unavailable');
        }
        $body = '';
        $cache = '';
        curl_setopt_array($handle, [
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROXY => '',
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            // Pin the connection to the address validated above so DNS cannot be rebound mid-request.
            CURLOPT_RESOLVE => [$host . ':443:' . (str_contains($address, ':') ? '[' . $address . ']' : $address)],
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
                if (strlen($body) + strlen($chunk) > 65536) {
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
            CURLOPT_HEADERFUNCTION => static function ($handle, string $header) use (&$cache): int {
                if (str_starts_with(strtolower($header), 'cache-control:')) {
                    $cache = trim(substr($header, 14));
                }
                return strlen($header);
            },
        ]);
        $success = curl_exec($handle);
        $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        unset($handle);
        if ($success === false) {
            throw new InvalidArgumentException('client_metadata_unavailable');
        }
        return ['status' => $status, 'body' => $body, 'cache_control' => $cache];
    }
}
