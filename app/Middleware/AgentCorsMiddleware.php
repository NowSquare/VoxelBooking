<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Agent\CorsPolicy;
use App\Agent\OperationException;
use App\Engine\Request;
use App\Engine\Response;

final class AgentCorsMiddleware
{
    public static function applies(string $path): bool
    {
        return $path === '/mcp' || $path === '/mcp/public' || str_starts_with($path, '/mcp/public/')
            || str_starts_with($path, '/api/agent/v2/')
            || in_array($path, [
                '/oauth/token', '/oauth/register', '/oauth/revoke',
                '/.well-known/oauth-authorization-server',
                '/.well-known/oauth-protected-resource',
                '/.well-known/oauth-protected-resource/mcp',
                '/.well-known/oauth-protected-resource/api/agent/v2',
            ], true);
    }

    public function handle(Request $request, callable $next): Response
    {
        if (!self::applies($request->path())) {
            return $next($request);
        }
        $origin = $request->header('Origin');
        $headers = [];
        try {
            $policy = CorsPolicy::configured();
            if (!$policy->permits($origin)) {
                throw new OperationException('origin_not_allowed', 403);
            }
            $headers = $policy->responseHeaders($origin);
            if ($request->method() === 'OPTIONS') {
                $methods = str_starts_with($request->path(), '/mcp') ? ['POST'] : $request->getAttribute('_route_allowed_methods', []);
                $headers = $policy->preflight($origin, $request->header('Access-Control-Request-Method') ?? '', $request->header('Access-Control-Request-Headers') ?? '', $methods);
                $response = Response::empty();
            } else {
                $response = $next($request);
            }
        } catch (OperationException $error) {
            $response = Response::json($error->problem((string) $request->getAttribute('request_id', '')), $error->status)
                ->header('Content-Type', 'application/problem+json');
        }
        foreach ($headers as $name => $value) {
            $response->header($name, $value);
        }
        $vary = $response->getHeaders()['Vary'] ?? '';
        $varyFields = array_filter(array_map('trim', explode(',', $vary)));
        $varyFields[] = 'Origin';
        if ($request->method() === 'OPTIONS') {
            $varyFields[] = 'Access-Control-Request-Method';
            $varyFields[] = 'Access-Control-Request-Headers';
        }
        return $response->header('Vary', implode(', ', array_unique($varyFields)));
    }

    /** The middleware handles every valid preflight before dispatch reaches this method. */
    public function preflight(Request $request): Response
    {
        return Response::empty(405);
    }
}
