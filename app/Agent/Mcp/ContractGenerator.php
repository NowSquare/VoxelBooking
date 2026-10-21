<?php

declare(strict_types=1);

namespace App\Agent\Mcp;

use App\Agent\OperationDefinition;
use LogicException;
use stdClass;

/** Both transports consume the same operation schemas and policy metadata. */
final class ContractGenerator
{
    /** @param list<OperationDefinition> $definitions */
    public function tools(array $definitions): array
    {
        $tools = [];
        foreach ($definitions as $definition) {
            $tool = $definition->tool();
            if (!$definition->readOnly) {
                $tool['inputSchema'] = $this->reviewed($definition) ? $this->commitSchema() : $definition->inputSchema;
                $tool['inputSchema']['properties']['idempotency_key'] = $this->idempotencySchema();
                $tool['inputSchema']['required'] = array_values(array_unique([
                    ...($tool['inputSchema']['required'] ?? []),
                    'idempotency_key'
                ]));
            }
            $tool['inputSchema'] = $this->schema($tool['inputSchema']);
            $tool['outputSchema'] = $this->schema($tool['outputSchema']);
            $tools[] = $tool;
            if ($this->reviewed($definition)) {
                $tools[] = [
                    'name' => $definition->operationId . '_preview',
                    'title' => 'Preview: ' . $definition->title,
                    'description' => 'Review this proposed action before committing it. ' . $definition->description,
                    'inputSchema' => $this->schema($definition->inputSchema),
                    'outputSchema' => $this->schema($this->previewSchema()),
                    'annotations' => [
                        'readOnlyHint' => true,
                        'destructiveHint' => false,
                        'idempotentHint' => true,
                        'openWorldHint' => false
                    ],
                ];
            }
        }
        if (count(array_unique(array_column($tools, 'name'))) !== count($tools)) {
            throw new LogicException('Duplicate operation tool name.');
        }
        usort($tools, static fn (array $left, array $right): int => strcmp($left['name'], $right['name']));
        return $tools;
    }

    /** @param list<OperationDefinition> $definitions */
    public function openApi(array $definitions, string $baseUrl, string $version): array
    {
        $baseUrl = rtrim($baseUrl, '/');
        $issuer = preg_replace('#/api/agent/v2$#', '', $baseUrl);
        $document = [
            'openapi' => '3.1.0',
            'jsonSchemaDialect' => 'https://json-schema.org/draft/2020-12/schema',
            'info' => ['title' => 'VoxelBooking Agent API', 'version' => $version],
            'servers' => [['url' => $baseUrl]],
            'paths' => [],
            'components' => [
                'securitySchemes' => [
                    'bearer' => ['type' => 'http', 'scheme' => 'bearer'],
                    'oauth' => ['type' => 'oauth2', 'flows' => ['authorizationCode' => [
                        'authorizationUrl' => $issuer . '/oauth/authorize',
                        'tokenUrl' => $issuer . '/oauth/token',
                        'scopes' => [],
                    ]]],
                ],
                'schemas' => ['Problem' => [
                    'type' => 'object',
                    'properties' => [
                        'type' => ['type' => 'string', 'format' => 'uri-reference'],
                        'title' => ['type' => 'string'],
                        'status' => ['type' => 'integer'],
                        'code' => ['type' => 'string'],
                        'field_errors' => ['type' => 'object', 'additionalProperties' => true],
                        'request_id' => ['type' => 'string'],
                        'retryable' => ['type' => 'boolean'],
                        'next_action' => ['type' => ['string', 'null']],
                    ],
                    'required' => ['type', 'title', 'status', 'code', 'retryable'],
                ]],
            ],
        ];
        foreach ($definitions as $definition) {
            $path = str_ends_with($baseUrl, '/api/agent/v2')
                ? preg_replace('#^/api/agent/v2(?=/|$)#', '', $definition->httpPath)
                : $definition->httpPath;
            $public = $this->publicOperation($definition);
            $method = strtolower($definition->httpMethod);
            if (!in_array($method, ['get', 'post', 'put', 'patch', 'delete'], true)) {
                throw new LogicException('Unsupported operation HTTP method.');
            }
            if (isset($document['paths'][$path][$method])) {
                throw new LogicException('Duplicate operation HTTP binding.');
            }
            foreach ($public ? [] : $definition->requiredScopes as $scope) {
                $document['components']['securitySchemes']['oauth']['flows']['authorizationCode']['scopes'][$scope] = $scope;
            }
            $document['paths'][$path][$method] = $this->operation($definition);
            if ($this->reviewed($definition)) {
                $previewPath = $path . '/preview';
                if (isset($document['paths'][$previewPath]['post'])) {
                    throw new LogicException('Duplicate preview HTTP binding.');
                }
                $document['paths'][$previewPath]['post'] = $this->operation($definition, true);
            }
            if ($public) {
                $prefix = str_starts_with($path, '/api/agent/v2/') ? '/api/agent/v2' : '';
                $business = str_contains($path, '/public/{slug}/');
                $sessionPath = $prefix . ($business ? '/public/{slug}/session' : '/public/session');
                $document['paths'][$sessionPath]['post'] = $this->publicSession($business);
            }
        }
        ksort($document['paths']);
        if ($document['paths'] === []) {
            $document['paths'] = new stdClass();
        }
        $scopes = &$document['components']['securitySchemes']['oauth']['flows']['authorizationCode']['scopes'];
        ksort($scopes);
        if ($scopes === []) {
            $scopes = new stdClass();
        }
        return $document;
    }

    private function operation(OperationDefinition $definition, bool $preview = false): array
    {
        $operation = [
            'operationId' => $definition->operationId . ($preview ? '_preview' : ''),
            'summary' => ($preview ? 'Preview: ' : '') . $definition->title,
            'description' => $definition->description,
            'security' => $this->publicOperation($definition) ? [] : [['oauth' => $definition->requiredScopes], ['bearer' => []]],
            'parameters' => [],
            'responses' => [
                '200' => [
                    'description' => 'Operation result',
                    'content' => [
                        'application/json' => [
                            'schema' => $this->schema($preview ? $this->previewSchema() : $definition->resultSchema())
                        ]
                    ]
                ],
            ],
            'externalDocs' => ['url' => 'https://voxelbooking.com/docs/agent-api/' . Documentation::page($definition->documentationPage)],
            'x-actor-policy' => $definition->actorPolicy,
            'x-required-scopes' => $definition->requiredScopes,
            'x-booking-patterns' => $definition->patterns,
            'x-confirmation-policy' => $preview ? 'none' : $definition->confirmationPolicy,
            'x-capability-ids' => $definition->capabilityIds,
            'x-acceptance-tests' => $definition->acceptanceTestIds,
            'x-side-effects' => $preview ? [] : $definition->sideEffects,
            'x-error-codes' => $definition->errorCodes,
            'x-read-only' => $preview || $definition->readOnly,
            'x-destructive' => !$preview && $definition->destructive,
        ];
        $input = $definition->inputSchema;
        preg_match_all('/\{([a-zA-Z0-9_]+)\}/', $definition->httpPath, $matches);
        foreach ($matches[1] as $name) {
            $transportSlug = $name === 'slug' && $this->publicOperation($definition);
            if (!$transportSlug && !isset($input['properties'][$name])) {
                throw new LogicException('HTTP path parameter is absent from the operation input schema.');
            }
            $operation['parameters'][] = [
                'name' => $name,
                'in' => 'path',
                'required' => true,
                'schema' => $transportSlug ? $this->slugSchema() : $this->schema($input['properties'][$name])
            ];
            unset($input['properties'][$name]);
        }
        $input['required'] = array_values(array_diff($input['required'] ?? [], $matches[1]));
        if ($this->reviewed($definition) && !$preview) {
            $input = $this->commitSchema();
        }
        if ($this->publicOperation($definition) && (!$definition->readOnly || str_contains($definition->httpPath, '/receipts/'))) {
            $operation['parameters'][] = [
                'name' => 'X-Booking-Continuation',
                'in' => 'header',
                'required' => true,
                'schema' => ['type' => 'string', 'minLength' => 1],
                'description' => 'Obtain a continuation from POST /public/'
                    . (str_contains($definition->httpPath, '/{slug}/') ? '{slug}/' : '')
                    . 'session and reuse it for this task. It does not authorize access to existing customer records.',
            ];
        }
        if (!$definition->readOnly && !$preview) {
            $operation['parameters'][] = [
                'name' => 'Idempotency-Key',
                'in' => 'header',
                'required' => true,
                'schema' => $this->idempotencySchema(),
                'description' => 'Reuse this key for retries of the same reviewed operation.',
            ];
        }
        if (!$preview && strtoupper($definition->httpMethod) === 'GET') {
            foreach ($input['properties'] ?? [] as $name => $property) {
                $parameter = ['name' => $name, 'in' => 'query', 'required' => in_array($name, $input['required'], true)];
                // Structured query values cannot be expressed as a plain schema, so they travel as JSON.
                if (in_array($property['type'] ?? null, ['object', 'array'], true)
                    || isset($property['oneOf'])
                    || isset($property['anyOf'])) {
                    $parameter['content'] = ['application/json' => ['schema' => $this->schema($property)]];
                } else {
                    $parameter['schema'] = $this->schema($property);
                }
                $operation['parameters'][] = $parameter;
            }
        } else {
            $operation['requestBody'] = [
                'required' => $input['required'] !== [],
                'content' => ['application/json' => ['schema' => $this->schema($input)]]
            ];
        }
        foreach ([400, 401, 403, 404, 409, 422, 429, 503] as $status) {
            $operation['responses'][(string) $status] = [
                'description' => 'Operation problem',
                'content' => ['application/problem+json' => ['schema' => ['$ref' => '#/components/schemas/Problem']]],
            ];
        }
        return $operation;
    }

    private function publicOperation(OperationDefinition $definition): bool
    {
        return preg_match('#^(?:/api/agent/v2)?/public/#', $definition->httpPath) === 1;
    }

    private function slugSchema(): array
    {
        return ['type' => 'string', 'minLength' => 1, 'maxLength' => 100];
    }

    private function publicSession(bool $business): array
    {
        return [
            'operationId' => $business ? 'public_booking_session_start' : 'public_application_session_start',
            'summary' => $business ? 'Start a public booking task' : 'Start a public application task',
            'description' => 'Create a temporary continuation for public booking previews, browser review and retries. This grants no access to existing bookings or customer data.',
            'security' => [],
            'parameters' => $business
                ? [['name' => 'slug', 'in' => 'path', 'required' => true, 'schema' => $this->slugSchema()]]
                : [],
            'responses' => [
                '200' => ['description' => 'Temporary booking continuation', 'content' => ['application/json' => ['schema' => [
                    'type' => 'object',
                    'properties' => [
                        'continuation' => ['type' => 'string'],
                        'expires_at' => ['type' => 'string', 'format' => 'date-time'],
                        'business_id' => ['type' => 'string'],
                        'permissions' => [
                            'type' => 'array',
                            'items' => ['type' => 'string', 'enum' => ['public:read', 'public:book']]
                        ],
                    ],
                    'required' => ['continuation', 'expires_at', 'business_id', 'permissions'],
                    'additionalProperties' => false,
                ]]]],
                '404' => ['description' => 'Business unavailable'],
                '429' => ['description' => 'Rate limit reached'],
            ],
            'externalDocs' => ['url' => 'https://voxelbooking.com/docs/agent-api/book-an-appointment'],
        ];
    }

    private function reviewed(OperationDefinition $definition): bool
    {
        return !$definition->readOnly && $definition->confirmationPolicy !== 'none';
    }

    private function idempotencySchema(): array
    {
        return ['type' => 'string', 'minLength' => 8, 'maxLength' => 128, 'pattern' => '^[\\x21-\\x7e]+$'];
    }

    private function commitSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'preview_handle' => ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$'],
            ],
            'required' => ['preview_handle']
        ];
    }

    private function previewSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'preview_handle' => ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$'],
                'review' => ['type' => 'object', 'additionalProperties' => true],
                'expires_at' => ['type' => 'string', 'format' => 'date-time'],
                'review_url' => ['type' => 'string', 'format' => 'uri'],
                'approval_required' => ['type' => 'boolean'],
                'reserves_inventory' => ['type' => 'boolean', 'const' => false],
            ],
            'required' => ['preview_handle', 'review', 'expires_at', 'review_url', 'approval_required', 'reserves_inventory']
        ];
    }

    private function schema(array $schema): array
    {
        // PHP otherwise serializes empty schema maps as arrays, which violates JSON Schema.
        foreach (['properties', 'patternProperties', '$defs', 'definitions', 'dependentSchemas'] as $map) {
            if (array_key_exists($map, $schema)) {
                $schema[$map] = $schema[$map] === []
                    ? new stdClass()
                    : array_map(fn ($child) => is_array($child) ? $this->schema($child) : $child, $schema[$map]);
            }
        }
        foreach (
            ['items', 'contains', 'additionalProperties', 'unevaluatedProperties', 'propertyNames', 'not', 'if', 'then', 'else'] as $single
        ) {
            if (isset($schema[$single]) && is_array($schema[$single])) {
                $schema[$single] = $this->schema($schema[$single]);
            }
        }
        foreach (['allOf', 'anyOf', 'oneOf', 'prefixItems'] as $list) {
            if (isset($schema[$list])) {
                $schema[$list] = array_map(fn ($child) => is_array($child) ? $this->schema($child) : $child, $schema[$list]);
            }
        }
        return $schema;
    }
}
