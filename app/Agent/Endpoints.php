<?php

declare(strict_types=1);

namespace App\Agent;

final class Endpoints
{
    public static function baseUrl(): string
    {
        $base = rtrim(
            $_ENV['AGENT_BASE_URL'] ?? ((defined('VOXELBOOKING_TEST_PROCESS') && VOXELBOOKING_TEST_PROCESS)
                ? ($_ENV['APP_TEST_URL'] ?? app_url())
                : app_url()),
            '/'
        );
        $parts = parse_url($base);
        // The base URL becomes the OAuth audience, so it must be a bare origin plus path.
        if (!is_array($parts)
            || !isset($parts['scheme'], $parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || !in_array($parts['scheme'], ['https', 'http'], true)) {
            throw new OperationException('endpoint_configuration_invalid', 503);
        }
        if ($parts['scheme'] !== 'https' && !self::allowsPlainHttp($parts['host'])) {
            throw new OperationException('https_required', 503, nextAction: 'configure_https');
        }
        return $base;
    }

    /**
     * Whether a host may serve agent traffic over plain http.
     *
     * Bearer tokens and OAuth codes cross these endpoints, so plain http is only
     * acceptable where no network sits between client and server: loopback, and
     * the `.test` and `.localhost` names RFC 2606 and RFC 6761 reserve for local
     * use (Laravel Herd, Valet, DDEV). Those names never resolve on the public
     * internet, so allowing them cannot weaken a production deployment.
     */
    public static function allowsPlainHttp(string $host): bool
    {
        $host = strtolower($host);
        if (in_array($host, ['localhost', '127.0.0.1', '[::1]'], true)) {
            return true;
        }
        if (!filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
            return false;
        }
        return str_ends_with($host, '.test') || str_ends_with($host, '.localhost');
    }

    public static function resource(string $transport = 'http'): string
    {
        return self::baseUrl() . ($transport === 'mcp' ? '/mcp' : '/api/agent/v2');
    }
}
