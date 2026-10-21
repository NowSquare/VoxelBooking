<?php

declare(strict_types=1);

namespace App\Controllers\AgentApi;

use App\Agent\{Endpoints, OperationException, TransportAuth};
use App\Engine\{Request, Response};

/**
 * Answers a mistyped agent address with a machine-readable problem.
 *
 * Without this, a client that adds a path segment to the MCP address or calls
 * an operation path that does not exist meets the site's HTML 404 page and
 * reports a generic failure. The problem names the cause and the address where
 * the client can rediscover the current contract.
 */
final class UnknownEndpointController
{
    public function handle(Request $request): Response
    {
        $transport = str_starts_with($request->path(), '/mcp') ? 'mcp' : 'http';
        try {
            // MCP clients discover tools on the MCP endpoint itself; HTTP clients read the OpenAPI document.
            $discovery = $transport === 'mcp' ? Endpoints::resource('mcp') : Endpoints::resource('http') . '/schema';
            $error = new OperationException(
                'unknown_endpoint',
                404,
                nextAction: 'discover_endpoints',
                extensions: ['discovery_url' => $discovery],
            );
        } catch (OperationException $configuration) {
            $error = $configuration;
        }

        return TransportAuth::problem($error, $request, $transport);
    }
}
