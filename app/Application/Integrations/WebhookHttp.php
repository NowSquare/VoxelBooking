<?php

declare(strict_types=1);
namespace App\Application\Integrations;

/** Resolve on every attempt, reject mixed public/private answers, pin the verified address. */
final class WebhookHttp
{
    public function __construct(private readonly ?\Closure $resolver = null, private readonly ?\Closure $sender = null) {}

    public static function signature(string $body, int $timestamp, string $secret, ?string $previous = null): string
    {
        $value = 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);
        return $previous === null ? $value : $value.',v1='.hash_hmac('sha256', $timestamp.'.'.$body, $previous);
    }

    public function destination(string $url): array
    {
        $host = self::validateUrl($url);
        $addresses = $this->resolver ? ($this->resolver)($host) : self::resolve($host);
        if ($addresses === []) { throw new \InvalidArgumentException('webhook_dns_unavailable'); }
        foreach ($addresses as $address) {
            if (!is_string($address) || !self::isPublic($address)) { throw new \InvalidArgumentException('unsafe_webhook_destination'); }
        }
        return ['url'=>$url, 'host'=>$host, 'address'=>$addresses[0]];
    }

    public static function validateUrl(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
            || isset($parts['user'], $parts['pass']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['fragment']) || isset($parts['query']) || ($parts['port'] ?? 443) !== 443
            || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
            throw new \InvalidArgumentException('invalid_webhook_destination');
        }
        $host = strtolower($parts['host']);
        // Domain names only. No alternate numeric IP notation, local names, trailing dots, or IDN ambiguity.
        if (!preg_match('/\A(?=.{1,253}\z)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}\z/D', $host)) {
            throw new \InvalidArgumentException('invalid_webhook_destination');
        }
        return $host;
    }

    /** Return status only. Never retain the receiver's body, URL query, or provider errors. */
    public function post(string $url, string $body, array $headers): int
    {
        $target = $this->destination($url);
        if ($this->sender !== null) { return ($this->sender)($target, $body, $headers); }
        $curl = curl_init($target['url']);
        if ($curl === false) { throw new \RuntimeException('webhook_transport_unavailable'); }
        $received = 0;
        $address = str_contains($target['address'], ':') ? '['.$target['address'].']' : $target['address'];
        try {
            curl_setopt_array($curl, [
                CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', ...$headers],
                CURLOPT_USERAGENT => 'VoxelBooking-Webhooks/1', CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_MAXREDIRS => 0, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_PROXY => '',
                CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_TIMEOUT => 5,
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_RESOLVE => [$target['host'].':443:'.$address],
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$received): int {
                    $received += strlen($chunk);
                    return $received > 32768 ? 0 : strlen($chunk);
                },
            ]);
            if (curl_exec($curl) === false) { throw new \RuntimeException('webhook_transport_failed'); }
            return (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        } finally { unset($curl); }
    }

    private static function resolve(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if ($records === false) { return []; }
        $addresses = [];
        foreach ($records as $record) {
            if (isset($record['ip'])) { $addresses[] = $record['ip']; }
            if (isset($record['ipv6'])) { $addresses[] = $record['ipv6']; }
        }
        return array_values(array_unique($addresses));
    }

    private static function isPublic(string $address): bool
    {
        if (!filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) { return false; }
        $packed = inet_pton($address);
        if ($packed === false) { return false; }
        $networks = strlen($packed) === 4
            ? ['0.0.0.0/8','100.64.0.0/10','192.0.0.0/24','192.0.2.0/24','192.88.99.0/24','198.18.0.0/15','198.51.100.0/24','203.0.113.0/24','224.0.0.0/4','240.0.0.0/4']
            : ['2001::/23','2001:db8::/32','2002::/16','3fff::/20'];
        // IPv6 global unicast only: also excludes mapped, NAT64, local and multicast encodings.
        if (strlen($packed) === 16 && (ord($packed[0]) & 0xe0) !== 0x20) { return false; }
        foreach ($networks as $network) {
            [$base, $bits] = explode('/', $network); $bits = (int) $bits; $base = inet_pton($base);
            $bytes = intdiv($bits, 8); $remainder = $bits % 8;
            if (substr($packed, 0, $bytes) === substr($base, 0, $bytes)
                && ($remainder === 0 || (ord($packed[$bytes]) >> (8-$remainder)) === (ord($base[$bytes]) >> (8-$remainder)))) { return false; }
        }
        return true;
    }
}
