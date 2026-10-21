<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Agent\{ConnectionPresets, ConnectionService, Endpoints, OperationException, Operations, SessionActor};
use App\Engine\{Auth, CustomerCapability, Database, DemoMode, FormState, Request, Response, Version, View};
use App\Middleware\CsrfMiddleware;

final class ConnectionsController
{
    public function index(Request $request): Response
    {
        $registry = Operations::registry();
        $actor = SessionActor::resolve($registry);
        $businesses = $actor->businessIds === []
            ? []
            : Database::query(
                'SELECT id, name, status FROM tenants WHERE id IN ('
                    . implode(',', array_fill(0, count($actor->businessIds), '?'))
                    . ') ORDER BY name,id',
                $actor->businessIds,
            );
        try {
            $page = (new ConnectionService($registry))->page($actor, $request->string('cursor') ?: null);
        } catch (OperationException $error) {
            FormState::toast('error', __('agent.errors.' . $error->problemCode));
            return CustomerCapability::protect(Response::redirect('/admin/connections'));
        }
        $secret = $_SESSION['new_connection_secret'] ?? null;
        unset($_SESSION['new_connection_secret']);
        return CustomerCapability::protect(View::response('admin.connections.index', [
            'user' => Auth::user(), 'version' => Version::get(), 'csrfToken' => CsrfMiddleware::generateToken(),
            'pageTitle' => __('agent.connections.title'), 'activePage' => 'connections',
            'connections' => $page['items'], 'nextCursor' => $page['next_cursor'],
            'presets' => ConnectionPresets::forActor($actor, $registry),
            'businesses' => $businesses, 'secret' => $secret, 'flash' => FormState::getToast(), 'mcpUrl' => Endpoints::resource('mcp'),
        ]));
    }

    public function create(Request $request): Response
    {
        return $this->mutate(function () use ($request): void {
            $registry = Operations::registry();
            $actor = SessionActor::resolve($registry);
            $preset = ConnectionPresets::forActor($actor, $registry)[$request->string('preset')]
                ?? throw new OperationException('invalid_grant', 422);
            $businessIds = $request->all()['business_ids'] ?? [];
            if (!is_array($businessIds) || array_filter($businessIds, static fn($id) => !is_string($id))) {
                throw new OperationException('invalid_grant', 422);
            }
            if ($businessIds === [] && count($actor->businessIds) === 1) {
                $businessIds = $actor->businessIds;
            }
            $days = $request->int('days', 30);
            if (!in_array($days, [7, 30, 90], true)) {
                throw new OperationException('validation_failed', 422);
            }
            $audience = Endpoints::resource($request->string('transport') === 'mcp' ? 'mcp' : 'http');
            $service = new ConnectionService($registry);
            $secret = Database::transaction(function () use ($service, $actor, $request, $preset, $businessIds, $days, $audience): array {
                $expiry = gmdate('Y-m-d H:i:s', time() + $days * 86400);
                $grant = $service->createGrant(
                    $actor,
                    $request->string('name') ?: __('agent.connections.default_name'),
                    $preset['scopes'],
                    $businessIds,
                    $expiry,
                    $preset['title'],
                );
                return $service->issueCredential($grant['id'], $audience, $expiry);
            });
            $_SESSION['new_connection_secret'] = ['token' => $secret['raw_token'], 'audience' => $audience];
        });
    }

    public function activity(Request $request): Response
    {
        $registry = Operations::registry();
        $actor = SessionActor::resolve($registry);
        try {
            $page = \App\Application\Identity\ConnectionState::browserActivity($actor, $request->getAttribute('id'), [
                'cursor' => $request->string('cursor') ?: null, 'limit' => 25,
            ]);
        } catch (OperationException $error) {
            FormState::toast('error', __('agent.errors.' . $error->problemCode));
            return CustomerCapability::protect(Response::redirect('/admin/connections'));
        }
        $ids = array_values(array_unique(array_filter(array_column($page['items'], 'business_id'))));
        $businesses = $ids === []
            ? []
            : array_column(
                Database::query(
                    'SELECT id,name,timezone FROM tenants WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')',
                    $ids,
                ),
                null,
                'id',
            );
        foreach ($page['items'] as &$item) {
            $operation = str_starts_with($item['action'], 'agent.') ? substr($item['action'], 6) : $item['action'];
            $item['title'] = $registry->get($operation)?->title ?? $operation;
            $business = $item['business_id'] === null ? null : ($businesses[$item['business_id']] ?? null);
            $item['business_name'] = $business['name'] ?? __('agent.connections.installation');
            $timezone = $business['timezone'] ?? 'UTC';
            $localTime = (new \DateTimeImmutable($item['created_at'], new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone($timezone));
            $item['local_time'] = \App\Engine\Locale::datetime($localTime) . ' ' . $localTime->format('T');
        }
        unset($item);
        return CustomerCapability::protect(View::response('admin.connections.activity', [
            'user' => Auth::user(), 'version' => Version::get(), 'csrfToken' => CsrfMiddleware::generateToken(),
            'pageTitle' => __('agent.connections.activity'), 'activePage' => 'connections', 'page' => $page,
        ]));
    }

    public function revoke(Request $request): Response
    {
        return $this->mutate(function () use ($request): void {
            $registry = Operations::registry();
            (new ConnectionService($registry))->revoke(SessionActor::resolve($registry), $request->getAttribute('id'));
            FormState::toast('success', __('agent.connections.revoked'));
        });
    }

    public function rotate(Request $request): Response
    {
        return $this->mutate(function () use ($request): void {
            $registry = Operations::registry();
            $actor = SessionActor::resolve($registry);
            // A rotated secret keeps the transport its newest credential was issued for.
            $credential = Database::query(
                'SELECT audience FROM agent_credentials WHERE connection_id = ? ORDER BY created_at DESC,id DESC LIMIT 1',
                [$request->getAttribute('id')],
            )[0] ?? null;
            if ($credential === null) {
                throw new OperationException('not_found', 404);
            }
            $secret = (new ConnectionService($registry))->rotate($actor, $request->getAttribute('id'), $credential['audience']);
            $_SESSION['new_connection_secret'] = ['token' => $secret['raw_token'], 'audience' => $credential['audience']];
        });
    }

    private function mutate(callable $action): Response
    {
        try {
            if (DemoMode::isActive()) {
                throw new OperationException('demo_restricted', 403);
            }
            $action();
        } catch (OperationException $error) {
            FormState::toast('error', __('agent.errors.' . $error->problemCode));
        }
        return CustomerCapability::protect(Response::redirect('/admin/connections'));
    }
}
