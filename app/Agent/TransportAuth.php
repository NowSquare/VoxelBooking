<?php

declare(strict_types=1);

namespace App\Agent;

use App\Engine\{AgentAuth, Request, Response, AuditLog};

final class TransportAuth
{
    public static function actor(Request $request, string $transport = 'http'): ActorContext
    {
        $token = AgentAuth::extractBearerToken($request->header('Authorization') ?? '');
        if ($token === '') {
            // Distinct from a rejected token: nothing was presented, often because a proxy dropped the header.
            throw new OperationException('authorization_required', 401, nextAction: 'connect_client');
        }
        $actor = (new ConnectionAuthenticator())->authenticate($token, Endpoints::resource($transport));
        // Budgets stack: one per credential, one per principal, and one per principal and business.
        $budgets = [
            'credential:' . $actor->credentialId => 60,
            'principal:' . $actor->principalType . ':' . $actor->principalId => 120
        ];
        foreach ($actor->businessIds as $id) {
            $budgets['principal:' . $actor->principalType . ':' . $actor->principalId . ':business:' . $id] = 120;
        }
        try {
            $budget = (new RateBudget())->consume($budgets);
        } catch (\Throwable) {
            throw new OperationException('rate_limiter_unavailable', 503, nextAction: 'retry_later', retryable: true);
        }
        $request->setAttribute('agent_budget', $budget);
        if (!$budget['allowed']) {
            throw new OperationException('rate_limited', 429, nextAction: 'wait_and_retry', retryable: true);
        }
        return $actor;
    }

    public static function problem(OperationException $error, Request $request, string $transport = 'http'): Response
    {
        $response = Response::json($error->problem(AuditLog::getRequestId()), $error->status)
            ->header('Content-Type', 'application/problem+json')
            ->header('Cache-Control', 'no-store')
            ->header('X-Robots-Tag', 'noindex, nofollow');
        if ($error->status === 401) {
            // RFC 9728: point the client at the protected-resource metadata so it can start OAuth.
            $response->header(
                'WWW-Authenticate',
                'Bearer resource_metadata="' . Endpoints::baseUrl() . '/.well-known/oauth-protected-resource'
                    . ($transport === 'mcp' ? '/mcp' : '/api/agent/v2') . '"'
            );
        }
        if (in_array($error->status, [429, 503], true)) {
            $response->header('Retry-After', (string) ($request->getAttribute('agent_budget')['retry_after'] ?? 60));
        }
        return self::headers($response, $request);
    }

    public static function headers(Response $response, Request $request): Response
    {
        $response->header('Cache-Control', 'no-store')->header('X-Robots-Tag', 'noindex, nofollow');
        if ($budget = $request->getAttribute('agent_budget')) {
            $response->header('X-RateLimit-Limit', (string) $budget['limit'])
                ->header('X-RateLimit-Remaining', (string) $budget['remaining'])
                ->header('X-RateLimit-Reset', (string) $budget['reset']);
        }
        return $response;
    }
}
