<?php

declare(strict_types=1);

namespace App\Controllers\AgentApi\V2;

use App\Agent\{Endpoints, Executor, Operations, OperationException, TransportAuth};
use App\Agent\Mcp\ContractGenerator;
use App\Engine\{Request, Response, Version};

final class OperationController
{
    public function schema(Request $request): Response
    {
        $schema = (new ContractGenerator())->openApi(Operations::registry()->all(), Endpoints::resource(), Version::get());
        return Response::json($schema)->header('Cache-Control', 'public, max-age=300')->header('X-Robots-Tag', 'noindex');
    }

    public function invoke(Request $request): Response
    {
        return $this->dispatch($request, false);
    }

    public function preview(Request $request): Response
    {
        return $this->dispatch($request, true);
    }

    private function dispatch(Request $request, bool $preview): Response
    {
        try {
            $registry = Operations::registry();
            $path = (string) $request->getAttribute('_route_pattern');
            $relative = substr($path, strlen('/api/agent/v2'));
            if ($preview) {
                $relative = substr($relative, 0, -strlen('/preview'));
            }
            $definition = null;
            foreach ($registry->all() as $candidate) {
                // A preview route shares its operation's path but always arrives as POST.
                if (
                    $candidate->httpPath === $relative
                    && ($preview
                        ? $request->method() === 'POST' && !$candidate->readOnly && $candidate->previewHandler !== null
                        : $candidate->httpMethod === $request->method())
                ) {
                    $definition = $candidate;
                    break;
                }
            }
            if ($definition === null) {
                throw new OperationException('operation_not_found', 404);
            }
            $public = str_starts_with($relative, '/public/');
            $actor = $public
                ? \App\Agent\PublicTransport::actor($request, write: !$definition->readOnly || str_contains($relative, '/receipts/'))
                : TransportAuth::actor($request);
            if ($public && $definition->actorPolicy !== 'public') {
                throw new OperationException('not_found', 404);
            }
            $arguments = $request->method() === 'GET' ? $request->all() : $request->jsonObject();
            preg_match_all('/\{([A-Za-z0-9_]+)\}/', $definition->httpPath, $matches);
            foreach ($matches[1] as $name) {
                if ($name === 'slug') {
                    continue;
                }
                $value = $request->getAttribute($name);
                if (isset($arguments[$name]) && $arguments[$name] !== $value) {
                    throw new OperationException('path_body_mismatch', 422);
                }
                $arguments[$name] = $value;
            }
            if ($request->method() === 'GET') {
                $arguments = $this->queryTypes($arguments, $definition->inputSchema);
            }
            // The reviewed payload already contains the route's business and target IDs.
            if (!$preview && !$definition->readOnly && $definition->confirmationPolicy !== 'none') {
                $stored = (new \App\Agent\PreviewStore())->resolve(
                    $actor,
                    $definition->operationId,
                    (string) ($arguments['preview_handle'] ?? ''),
                );
                foreach ($matches[1] as $name) {
                    if ($name === 'slug') {
                        continue;
                    }
                    if (($stored['arguments'][$name] ?? null) !== $arguments[$name]) {
                        throw new OperationException('path_body_mismatch', 422);
                    }
                    unset($arguments[$name]);
                }
            }
            $executor = new Executor($registry);
            $result = $preview
                ? $executor->preview($actor, $definition->operationId, $arguments)
                : $executor->execute($actor, $definition->operationId, $arguments, $request->header('Idempotency-Key'));
            return TransportAuth::headers(Response::json($result), $request);
        } catch (OperationException $error) {
            return TransportAuth::problem($error, $request);
        } catch (\JsonException|\UnexpectedValueException) {
            return TransportAuth::problem(new OperationException('invalid_json', 400), $request);
        } catch (\Throwable) {
            // SQL, provider and filesystem messages may contain personal data or credentials.
            error_log('Agent HTTP operation failed; request_id=' . \App\Engine\AuditLog::getRequestId());
            return TransportAuth::problem(
                new OperationException('operation_unavailable', 503, retryable: true, nextAction: 'recover_operation'),
                $request,
            );
        }
    }

    /** Query strings carry only text, so coerce each value to the type its schema declares. */
    private function queryTypes(array $input, array $schema): array
    {
        foreach ($input as $name => $value) {
            $type = $schema['properties'][$name]['type'] ?? null;
            if ($type === 'integer' && is_string($value) && preg_match('/\A-?[0-9]+\z/D', $value)) {
                $input[$name] = (int) $value;
            } elseif ($type === 'boolean' && is_string($value) && in_array($value, ['true', 'false'], true)) {
                $input[$name] = $value === 'true';
            } elseif (in_array($type, ['array', 'object'], true) && is_string($value)) {
                $input[$name] = json_decode($value, true, flags: JSON_THROW_ON_ERROR);
            }
        }
        return $input;
    }
}
