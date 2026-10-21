<?php

declare(strict_types=1);

namespace App\Controllers\Mcp;

use App\Agent\{Endpoints, Executor, Operations, OperationException, TransportAuth, CanonicalJson};
use App\Agent\Mcp\{ContractGenerator, HttpTransport, Server, ToolFailure};
use App\Controllers\AgentApi\V2\PublicSessionController;
use App\Engine\{Request, Response, Version, AuditLog};

final class McpController
{
    public function handle(Request $request): Response
    {
        try {
            $public = str_starts_with($request->path(), '/mcp/public');
            $actor = $public ? \App\Agent\PublicTransport::actor($request) : TransportAuth::actor($request, 'mcp');
            $registry = Operations::registry();
            $executor = new Executor($registry);
            // Discovery describes public actions before a continuation is established.
            $discoveryActor = $public
                ? new \App\Agent\ActorContext(
                    'public',
                    $actor->principalId,
                    'prospective_customer',
                    $actor->businessIds,
                    ['public:read', 'public:book'],
                    authMode: 'public',
                )
                : $actor;
            $server = new Server(
                static function () use ($registry, $discoveryActor, $public): array {
                    $definitions = $registry->visible($discoveryActor);
                    if ($public) {
                        // A platform-scoped public session lists only tools that take no business_id.
                        $definitions = array_values(array_filter(
                            $definitions,
                            static fn($definition) => $definition->actorPolicy === 'public'
                                && (in_array('platform', $discoveryActor->businessIds, true)
                                    ? !isset($definition->inputSchema['properties']['business_id'])
                                    : true),
                        ));
                    } else {
                        $definitions = array_values(array_filter(
                            $definitions,
                            static fn($definition) => !str_starts_with($definition->httpPath, '/public/'),
                        ));
                    }
                    $tools = (new ContractGenerator())->tools($definitions);
                    if (!$public) {
                        return $tools;
                    }
                    foreach ($tools as &$tool) {
                        $tool['inputSchema']['properties']['continuation'] = [
                            'type' => 'string',
                            'maxLength' => 2048,
                            'description' => __('agent.public.continuation_description'),
                        ];
                        [$baseName] = self::resolveTool($registry, $tool['name']);
                        if (!$registry->get($baseName)->readOnly || str_starts_with($baseName, 'public_operation_')) {
                            $tool['inputSchema']['required'][] = 'continuation';
                        }
                    }
                    unset($tool);
                    $tools[] = self::startTool();
                    usort($tools, static fn($left, $right) => strcmp($left['name'], $right['name']));
                    return $tools;
                },
                static function (
                    string $name,
                    array $input,
                    array $context,
                ) use ($executor, $actor, $public, $request, $registry): array {
                    try {
                        if ($public && $name === 'public_session_start') {
                            if ($input !== []) {
                                throw new OperationException('validation_failed', 422);
                            }
                            $session = \App\Agent\PublicTransport::start($request);
                            (new \App\Agent\SchemaValidator())->validate(PublicSessionController::resultSchema(), $session, true);
                            return $session;
                        }
                        [$baseName, $isPreview] = self::resolveTool($registry, $name);
                        $definition = $registry->get($baseName) ?? throw new OperationException('operation_not_found', 404);
                        $callingActor = $actor;
                        if ($public) {
                            if ($definition->actorPolicy !== 'public') {
                                throw new OperationException('operation_not_found', 404);
                            }
                            $callingActor = \App\Agent\PublicTransport::actor(
                                $request,
                                $input['continuation'] ?? null,
                                !$definition->readOnly || str_starts_with($baseName, 'public_operation_'),
                            );
                            unset($input['continuation']);
                        }
                        if ($isPreview) {
                            return $executor->preview($callingActor, $baseName, $input);
                        }
                        // connection_test reports the negotiated revision so a client can check its own setup.
                        return \App\Agent\TransportContext::during(
                            $context['protocol_version'] ?? null,
                            static fn () => $executor->execute($callingActor, $name, $input, $context['idempotency_key'] ?? null),
                        );
                    } catch (OperationException $error) {
                        throw new ToolFailure($error->problem((string) ($context['request_id'] ?? AuditLog::getRequestId())));
                    }
                },
                ['name' => 'VoxelBooking', 'version' => Version::get()],
                // Cursor keys derive from the caller's identity and grants, so a cursor only works for its own caller.
                cursorSecret: (new \App\Agent\PayloadCipher())->deriveKey(CanonicalJson::encode([
                    $public ? 'public' : $actor->principalId,
                    $actor->connectionId,
                    $actor->businessIds,
                    $actor->scopes,
                    $actor->role,
                ])),
                unlistedTool: $public ? null : static function (string $name) use ($registry, $actor): ?array {
                    $baseName = str_ends_with($name, '_preview') ? substr($name, 0, -8) : $name;
                    $definition = $registry->get($baseName);
                    // The published OpenAPI document already names every operation, so saying why
                    // a known one is hidden from this caller discloses nothing new.
                    if ($definition === null
                        || str_starts_with($definition->httpPath, '/public/')
                        || ($baseName !== $name && ($definition->readOnly || $definition->previewHandler === null))) {
                        return null;
                    }
                    try {
                        (new \App\Agent\AuthorizationPolicy())->authorize($actor, $definition);
                    } catch (OperationException $refused) {
                        return $refused->problem(AuditLog::getRequestId());
                    }
                    return null;
                },
            );
            $transport = new HttpTransport($server, \App\Agent\CorsPolicy::configured()->origins());
            $result = $transport->handle($request->method(), $request->headers(), $request->rawBody(1048577));
            $response = (new Response())->status($result->status)->body($result->json());
            foreach ($result->headers as $name => $value) {
                $response->header($name, $value);
            }
            return TransportAuth::headers($response, $request);
        } catch (OperationException $error) {
            return TransportAuth::problem($error, $request, 'mcp');
        }
    }

    private static function startTool(): array
    {
        return [
            'name' => 'public_session_start',
            'title' => __('agent.public.start_title'),
            'description' => __('agent.public.start_description'),
            'inputSchema' => ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false],
            // The same declaration the HTTP session route publishes in OpenAPI.
            'outputSchema' => PublicSessionController::resultSchema(),
            'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false],
        ];
    }

    /**
     * Map a tool name to its operation and say whether it is a preview twin.
     *
     * An operation's own name wins: email_template_preview is a read-only
     * operation, not the preview of an "email_template" command.
     *
     * @return array{0: string, 1: bool}
     */
    private static function resolveTool(\App\Agent\OperationRegistry $registry, string $name): array
    {
        if ($registry->get($name) !== null || !str_ends_with($name, '_preview')) {
            return [$name, false];
        }

        return [substr($name, 0, -strlen('_preview')), true];
    }
}
