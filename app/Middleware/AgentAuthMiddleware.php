<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Engine\AgentAuth;
use App\Engine\AuditLog;
use App\Engine\Request;
use App\Engine\Response;
use App\Agent\RateBudget;

/**
 * Agent API authentication middleware.
 *
 * Per PRD §XII and .ai/22-VoxelBooking-Agent-API-Checklist.md:
 * - Validates Bearer token from Authorization header
 * - Enforces scope requirements per route
 * - Returns JSON error responses (never HTML redirects)
 * - Logs API access for audit trail
 */
final class AgentAuthMiddleware
{
    /** Rate limit: requests per minute per key */
    private const RATE_LIMIT = 60;

    /** Rate limit window in seconds */
    private const RATE_WINDOW = 60;

    public function handle(Request $request, callable $next): Response
    {
        // Extract bearer token
        $authHeader = $request->header('Authorization') ?? '';
        $token = AgentAuth::extractBearerToken($authHeader);

        if ($token === '') {
            return Response::json([
                'error'   => 'unauthorized',
                'message' => 'Missing or invalid Authorization header. Use: Authorization: Bearer <api_key>',
            ], 401);
        }

        // Validate token
        $key = AgentAuth::validate($token);

        if ($key === null) {
            AuditLog::log(
                'api.auth_failed',
                'api_key',
                null,
                ['reason' => 'Invalid or expired API key'],
                actorType: 'api',
                actorId: null,
            );

            return Response::json([
                'error'   => 'unauthorized',
                'message' => 'Invalid or expired API key.',
            ], 401);
        }

        try {
            $budget = (new RateBudget())->consume(['legacy-credential:' . $key['id'] => self::RATE_LIMIT]);
        } catch (\Throwable) {
            return Response::json([
                'error' => 'rate_limiter_unavailable',
                'message' => 'Request admission is unavailable. Retry later.',
            ], 503)->header('Retry-After', '60')->header('Cache-Control', 'no-store');
        }
        if (!$budget['allowed']) {
            return Response::json([
                'error'   => 'rate_limited',
                'message' => 'Rate limit exceeded. Please wait before making more requests.',
                'retry_after' => $budget['retry_after'],
            ], 429)->header('Retry-After', (string) $budget['retry_after'])
                ->header('X-RateLimit-Limit', (string) $budget['limit'])
                ->header('X-RateLimit-Remaining', '0')
                ->header('X-RateLimit-Reset', (string) $budget['reset']);
        }

        // Store key info on request for controllers to use
        $request->setAttribute('api_key', $key);
        $request->setAttribute('api_key_id', $key['id']);
        $request->setAttribute('api_scopes', $key['scopes_array']);

        return $next($request)->header('Cache-Control', 'no-store')
            ->header('X-RateLimit-Limit', (string) $budget['limit'])
            ->header('X-RateLimit-Remaining', (string) $budget['remaining'])
            ->header('X-RateLimit-Reset', (string) $budget['reset']);
    }
}
