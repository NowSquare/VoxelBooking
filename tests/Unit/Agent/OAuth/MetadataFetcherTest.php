<?php

declare(strict_types=1);

namespace Tests\Unit\Agent\OAuth;

use App\Agent\OAuth\MetadataFetcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MetadataFetcherTest extends TestCase
{
    private const URL = 'https://client.example/client.json';

    #[DataProvider('nonPublicAddresses')]
    public function testNonPublicAddressesNeverReachTheNetwork(string $address): void
    {
        $fetcher = new MetadataFetcher(
            static fn (): array => [$address],
            static function (): array {
                throw new \LogicException('Network must not run');
            },
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid_client_id');
        $fetcher->fetch(self::URL);
    }

    /** Ranges PHP's private/reserved filter accepts, yet which never name a public internet host. */
    public static function nonPublicAddresses(): array
    {
        return [
            'carrier-grade NAT 100.64.0.0/10, first' => ['100.64.0.1'],
            'carrier-grade NAT 100.64.0.0/10, last' => ['100.127.255.254'],
            'benchmarking 198.18.0.0/15, first' => ['198.18.0.1'],
            'benchmarking 198.18.0.0/15, last' => ['198.19.255.254'],
            'IETF protocol assignments 192.0.0.0/24' => ['192.0.0.8'],
            'NAT64 64:ff9b::/96 to loopback' => ['64:ff9b::7f00:1'],
            'NAT64 64:ff9b::/96 to metadata service' => ['64:ff9b::a9fe:a9fe'],
            '6to4 2002::/16 to loopback' => ['2002:7f00:1::1'],
            '6to4 2002::/16 to private' => ['2002:a00:1::1'],
            'IETF protocol assignments 2001::/23, Teredo' => ['2001:0:4136:e378:8000:63bf:3fff:fdd2'],
            'IETF protocol assignments 2001::/23, last' => ['2001:1ff:ffff::1'],
            'IPv4-mapped loopback' => ['::ffff:127.0.0.1'],
            'loopback' => ['127.0.0.1'],
        ];
    }

    #[DataProvider('publicAddresses')]
    public function testPublicAddressIsPinnedForTheRequest(string $address): void
    {
        $pinned = null;
        $fetcher = new MetadataFetcher(
            static fn (): array => [$address],
            static function (string $url, string $host, string $ip) use (&$pinned): array {
                $pinned = $ip;
                return [
                    'status' => 200,
                    'body' => json_encode([
                        'client_id' => self::URL,
                        'client_name' => 'Example client',
                        'redirect_uris' => ['https://client.example/callback'],
                    ]),
                    'cache_control' => '',
                ];
            },
        );

        $fetched = $fetcher->fetch(self::URL);

        self::assertSame($address, $pinned);
        self::assertSame('Example client', $fetched['metadata']['client_name']);
    }

    public static function publicAddresses(): array
    {
        return [
            'IPv4' => ['1.1.1.1'],
            'IPv6 global unicast' => ['2606:4700:4700::1111'],
        ];
    }
}
