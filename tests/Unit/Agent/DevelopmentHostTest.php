<?php

declare(strict_types=1);

namespace Tests\Unit\Agent;

use App\Agent\CorsPolicy;
use App\Agent\Endpoints;
use App\Agent\OperationException;
use PHPUnit\Framework\TestCase;

/** Plain http is a local development convenience, never a production option. */
final class DevelopmentHostTest extends TestCase
{
    protected function setUp(): void
    {
        $basePath = dirname(__DIR__, 3);
        \App\Engine\Locale::init($basePath);
        if (!function_exists('__')) {
            require_once $basePath . '/app/helpers.php';
        }
    }

    public function testLoopbackAndReservedDevelopmentNamesAllowPlainHttp(): void
    {
        foreach (['localhost', '127.0.0.1', '[::1]', 'voxelbooking.test', 'app.voxelbooking.test', 'booking.localhost', 'VoxelBooking.TEST'] as $host) {
            self::assertTrue(Endpoints::allowsPlainHttp($host), $host);
        }
    }

    public function testPublicAndLookalikeHostsRequireHttps(): void
    {
        foreach (['booking.example', 'test', 'test.example', 'voxelbooking.test.example', 'localhost.example', 'mytest', 'example.testing', '10.0.0.5', '192.168.1.20', '127.0.0.2', ''] as $host) {
            self::assertFalse(Endpoints::allowsPlainHttp($host), $host);
        }
    }

    public function testHerdDevelopmentUrlServesAgentPaths(): void
    {
        $this->withBaseUrl('http://voxelbooking.test', static function (): void {
            self::assertSame('http://voxelbooking.test', Endpoints::baseUrl());
            $policy = CorsPolicy::configured();
            self::assertTrue($policy->permits('http://voxelbooking.test'));
        });
    }

    public function testPlainHttpOnAPublicHostExplainsHowToFixIt(): void
    {
        $this->withBaseUrl('http://booking.example', static function (): void {
            try {
                CorsPolicy::configured();
                self::fail('Plain http on a public host was accepted.');
            } catch (OperationException $error) {
                self::assertSame('https_required', $error->problemCode);
                self::assertSame(503, $error->status);
                $problem = $error->problem();
                self::assertSame('configure_https', $problem['next_action']);
                self::assertNotSame('agent.errors.https_required', $problem['title']);
            }
        });
    }

    public function testDevelopmentHostsMayBeListedAsBrowserOrigins(): void
    {
        $policy = new CorsPolicy('https://booking.example', 'http://client.test, http://editor.localhost:5173');
        self::assertTrue($policy->permits('http://client.test'));
        self::assertTrue($policy->permits('http://editor.localhost:5173'));
    }

    private function withBaseUrl(string $url, callable $assertions): void
    {
        $environment = $_ENV;
        try {
            $_ENV['AGENT_BASE_URL'] = $url;
            unset($_ENV['AGENT_ALLOWED_ORIGINS']);
            $assertions();
        } finally {
            $_ENV = $environment;
        }
    }
}
