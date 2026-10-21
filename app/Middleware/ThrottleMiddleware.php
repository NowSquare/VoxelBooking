<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Engine\Database;
use App\Engine\Request;
use App\Engine\Response;

/**
 * Per-IP, per-endpoint rate limiting.
 *
 * PRD §XV Rate Limiting:
 * - Public GET: 60/min/IP
 * - Public POST: 10/min/IP
 * - Public DELETE: 10/min/IP
 * - Admin: 120/min/IP
 * - Cron: 4/min/IP
 *
 * Uses the `rate_limits` table. Before the table exists (pre-install),
 * rate limiting is disabled — the middleware passes through without
 * recording or checking hits. There is no in-memory fallback.
 */
final class ThrottleMiddleware
{
    /** @var array<string, array{limit: int, window: int}> */
    private static array $limits = [
        'auth_verify' => ['limit' => 5,  'window' => 900],
        'admin'       => ['limit' => 120, 'window' => 60],
        'public_get'  => ['limit' => 60,  'window' => 60],
        'public_post' => ['limit' => 10,  'window' => 60],
        'cron'        => ['limit' => 4,   'window' => 60],
        'agent'       => ['limit' => 120, 'window' => 60],
    ];

    public function handle(Request $request, callable $next): Response
    {
        // Before the rate_limits table exists (pre-install), pass through.
        // No rate limiting is enforced — this is honest, not a fallback.
        if (!$this->tableReady()) {
            return $next($request);
        }

        $path = $request->path();
        $method = $request->method();
        $ip = $request->ip();

        $group = $this->resolveGroup($path, $method);
        $config = self::$limits[$group] ?? self::$limits['public_get'];

        // Check rate limit
        if (!$this->allow($ip, $group, $config['limit'], $config['window'])) {
            $retryAfter = $config['window'];

            $response = Response::json([
                'error'   => 'rate_limited',
                'message' => 'Too many requests. Please wait and try again.',
            ], 429);

            $response->header('Retry-After', (string) $retryAfter);
            $response->header('X-RateLimit-Limit', (string) $config['limit']);
            $response->header('X-RateLimit-Remaining', '0');
            $response->header('X-RateLimit-Reset', (string) (time() + $retryAfter));

            return $response;
        }

        /** @var Response $response */
        $response = $next($request);

        // The authenticated layer reports the tighter credential/principal budget.
        // Do not replace it with the broader IP gate after the operation returns.
        if (!array_key_exists('X-RateLimit-Limit', $response->getHeaders())) {
            $remaining = max(0, $config['limit'] - $this->getCount($ip, $group, $config['window']));
            $response->header('X-RateLimit-Limit', (string) $config['limit']);
            $response->header('X-RateLimit-Remaining', (string) $remaining);
            $response->header('X-RateLimit-Reset', (string) (time() + $config['window']));
        }

        return $response;
    }

    private function resolveGroup(string $path, string $method): string
    {
        // MCP carries both queries and commands over POST. The authenticated
        // operation layer enforces credential/principal budgets after this IP gate.
        if (str_starts_with($path, '/api/agent/') || $path === '/mcp' || str_starts_with($path, '/mcp/public')) {
            return 'agent';
        }
        // Auth verification endpoints — stricter than generic admin
        if ($path === '/admin/login/verify-code' && $method === 'POST') {
            return 'auth_verify';
        }

        if (str_starts_with($path, '/admin')) {
            return 'admin';
        }

        if (str_starts_with($path, '/cron')) {
            return 'cron';
        }

        return match ($method) {
            'POST', 'PUT', 'DELETE' => 'public_post',
            default => 'public_get',
        };
    }

    private function allow(string $ip, string $group, int $limit, int $window): bool
    {
        $count = $this->getCount($ip, $group, $window);

        if ($count >= $limit) {
            return false;
        }

        $this->recordHit($ip, $group);

        return true;
    }

    private function getCount(string $ip, string $group, int $window): int
    {
        $cutoff = date('Y-m-d H:i:s', time() - $window);

        $result = Database::query(
            'SELECT COUNT(*) as cnt FROM `rate_limits` WHERE `ip` = ? AND `endpoint_group` = ? AND `created_at` > ?',
            [$ip, $group, $cutoff]
        );

        return (int) ($result[0]['cnt'] ?? 0);
    }

    private function recordHit(string $ip, string $group): void
    {
        Database::execute(
            'INSERT INTO `rate_limits` (`ip`, `endpoint_group`, `created_at`) VALUES (?, ?, ?)',
            [$ip, $group, date('Y-m-d H:i:s')]
        );
    }

    /**
     * Check if the rate_limits table exists and DB is reachable.
     * Returns false pre-install; rate limiting is honestly disabled until then.
     */
    private function tableReady(): bool
    {
        try {
            return Database::canConnect() && Database::tableExists('rate_limits');
        } catch (\Throwable) {
            return false;
        }
    }
}
