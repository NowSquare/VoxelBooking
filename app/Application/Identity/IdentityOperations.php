<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Agent\{
    ActorContext,
    ConnectionService,
    Endpoints,
    Operations,
    OperationDefinition,
    OperationException,
    OperationReceiptStore
};
use App\Agent\Mcp\Server;
use App\Application\Shared\Contract as S;
use App\Engine\Version;

final class IdentityOperations
{
    public static function definitions(): array
    {
        $patterns = ['timeslot', 'resource', 'capacity', 'event'];
        $text = S::text(...);
        $empty = S::object([], []);
        $identity = S::object([
            'principal_type' => $text(30),
            'principal_id' => $text(128),
            'role' => $text(30),
            'business_ids' => ['type' => 'array', 'items' => $text(26)],
            'scopes' => ['type' => 'array', 'items' => $text(100)],
            'connection_id' => $text(26, true),
            'locale' => $text(10)
        ]);
        $receipt = S::object([
            'id' => $text(26),
            'operation' => $text(100),
            'business_id' => $text(26, true),
            'resource_id' => $text(26, true),
            'status' => ['type' => 'string', 'const' => 'committed'],
            'created_at' => $text(40),
            'replay_available' => ['type' => 'boolean'],
            'notification_state' => $text(30, true)
        ]);
        $profile = S::object([
            'id' => $text(26),
            'name' => $text(),
            'email' => $text(),
            'role' => $text(30),
            'business_ids' => ['type' => 'array', 'items' => $text(26)],
            'version' => $text(64),
            'credentials_url' => ['type' => 'string', 'format' => 'uri']
        ]);
        $definitions = [
            new OperationDefinition(
                'identity_get',
                __('agent.operations.identity_get'),
                __('agent.descriptions.identity_get'),
                $empty,
                $identity,
                [],
                'authenticated',
                true,
                false,
                'none',
                $patterns,
                'permissions-and-confirmation',
                ['C01', 'C39'],
                ['A03', 'A04'],
                'GET',
                '/me',
                static fn(ActorContext $a) => [
                    'principal_type' => $a->principalType,
                    'principal_id' => $a->principalId,
                    'role' => $a->role,
                    'business_ids' => $a->businessIds,
                    'scopes' => $a->scopes,
                    'connection_id' => $a->connectionId,
                    'locale' => $a->locale
                ]
            ),
            new OperationDefinition(
                'account_get',
                __('agent.operations.account_get'),
                __('agent.descriptions.account_get'),
                $empty,
                $profile,
                ['account:read'],
                'self',
                true,
                false,
                'none',
                $patterns,
                'permissions-and-confirmation',
                ['C01'],
                ['A03'],
                'GET',
                '/account',
                AccountService::read(...)
            ),
            new OperationDefinition(
                'account_update',
                __('agent.operations.account_update'),
                __('agent.descriptions.account_update'),
                S::object([
                    'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255],
                    'expected_version' => $text(64)
                ]),
                $profile,
                ['account:manage'],
                'self',
                false,
                false,
                'none',
                $patterns,
                'permissions-and-confirmation',
                ['C01'],
                ['A31'],
                'POST',
                '/account',
                AccountService::update(...)
            ),
        ];
        $connection = S::object([
            'id' => $text(26),
            'name' => $text(120),
            'purpose' => $text(500),
            'expires_at' => $text(40),
            'credential_expires_at' => $text(40, true),
            'created_at' => $text(40),
            'last_used_at' => $text(40, true),
            'scopes' => ['type' => 'array', 'items' => $text(100)],
            'business_ids' => ['type' => 'array', 'items' => $text(26)]
        ]);
        $definitions[] = new OperationDefinition(
            'connection_get',
            __('agent.operations.connection_get'),
            __('agent.descriptions.connection_get'),
            $empty,
            $connection,
            [],
            'authenticated',
            true,
            false,
            'none',
            $patterns,
            'connect-your-client',
            ['C40'],
            ['A03', 'A04'],
            'GET',
            '/connection',
            ConnectionState::read(...)
        );
        $activity = S::object([
            'id' => $text(26),
            'business_id' => $text(26, true),
            'action' => $text(100),
            'receipt_id' => $text(26, true),
            'request_id' => $text(100, true),
            'created_at' => $text(40)
        ]);
        $definitions[] = new OperationDefinition(
            'connection_activity',
            __('agent.operations.connection_activity'),
            __('agent.descriptions.connection_activity'),
            S::object(
                ['cursor' => $text(4096), 'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100]],
                []
            ),
            S::object(['items' => ['type' => 'array', 'items' => $activity], 'next_cursor' => $text(4096, true)]),
            [],
            'authenticated',
            true,
            false,
            'none',
            $patterns,
            'connect-your-client',
            ['C40'],
            ['A02', 'A36'],
            'GET',
            '/connection/activity',
            ConnectionState::activity(...)
        );
        $definitions[] = new OperationDefinition(
            'connection_setup',
            __('agent.operations.connection_setup'),
            __('agent.descriptions.connection_setup'),
            $empty,
            S::object([
                'browser_url' => ['type' => 'string', 'format' => 'uri'],
                'requires_browser' => ['type' => 'boolean', 'const' => true]
            ]),
            [],
            'staff',
            true,
            false,
            'none',
            $patterns,
            'connect-your-client',
            ['C40'],
            ['A31'],
            'GET',
            '/connection/setup',
            static fn() => ['browser_url' => Endpoints::baseUrl() . '/admin/connections', 'requires_browser' => true]
        );
        foreach ([false, true] as $public) {
            $policy = $public ? 'public' : 'authenticated';
            $prefix = $public ? 'public_' : '';
            $path = $public ? '/public/{slug}' : '';
            $definitions[] = new OperationDefinition(
                $prefix . 'operation_get',
                __('agent.operations.operation_get'),
                __('agent.descriptions.operation_get'),
                S::object(['receipt_id' => $text(26)]),
                $receipt,
                [],
                $policy,
                true,
                false,
                'none',
                $patterns,
                'errors-and-retries',
                ['C40', 'C41'],
                ['A15', 'A20'],
                'GET',
                $path . '/receipts/{receipt_id}',
                static fn($a, $i) => (new OperationReceiptStore())->get($a, $i['receipt_id'])
            );
            $definitions[] = new OperationDefinition(
                $prefix . 'operation_recover',
                __('agent.operations.operation_recover'),
                __('agent.descriptions.operation_recover'),
                S::object(
                    [
                        'original_business_id' => $text(26, true),
                        'operation' => $text(100),
                        'original_idempotency_key' => ['type' => 'string', 'minLength' => 8, 'maxLength' => 128]
                    ],
                    ['operation', 'original_idempotency_key']
                ),
                $receipt,
                [],
                $policy,
                true,
                false,
                'none',
                $patterns,
                'errors-and-retries',
                ['C40', 'C41'],
                ['A13', 'A15'],
                'GET',
                $path . '/receipts/recover',
                static fn($a, $i) => (new OperationReceiptStore())->recover(
                    $a,
                    $i['operation'],
                    $i['original_business_id'] ?? null,
                    $i['original_idempotency_key']
                )
            );
        }

        $capability = S::object([
            'name' => $text(100),
            'title' => $text(),
            'scopes' => ['type' => 'array', 'items' => $text(100)],
            'read_only' => ['type' => 'boolean'],
            'patterns' => ['type' => 'array', 'items' => $text(30)],
            'documentation' => ['type' => 'string', 'format' => 'uri']
        ]);
        $definitions[] = new OperationDefinition(
            'capabilities_get',
            __('agent.operations.capabilities_get'),
            __('agent.descriptions.capabilities_get'),
            $empty,
            S::object([
                'server_version' => $text(30),
                'protocol_versions' => ['type' => 'array', 'items' => $text(30)],
                'operations' => ['type' => 'array', 'items' => $capability]
            ]),
            [],
            'authenticated',
            true,
            false,
            'none',
            $patterns,
            'coverage-and-limits',
            ['C42'],
            ['A41', 'A42'],
            'GET',
            '/capabilities',
            static fn($a) => [
                'server_version' => Version::get(),
                'protocol_versions' => Server::SUPPORTED_VERSIONS,
                'operations' => array_map(
                    static fn($op) => [
                        'name' => $op->operationId,
                        'title' => $op->title,
                        'scopes' => $op->requiredScopes,
                        'read_only' => $op->readOnly,
                        'patterns' => $op->patterns,
                        'documentation' => 'https://voxelbooking.com/docs/agent-api/' . $op->documentationPage
                    ],
                    array_values(array_filter(
                        Operations::registry()->visible($a),
                        static fn($op) => !str_starts_with($op->httpPath, '/public/')
                    ))
                )
            ]
        );
        $definitions[] = new OperationDefinition(
            'connection_revoke',
            __('agent.operations.connection_revoke'),
            __('agent.descriptions.connection_revoke'),
            $empty,
            S::object(['revoked' => ['type' => 'boolean', 'const' => true]]),
            [],
            'authenticated',
            false,
            true,
            'trusted',
            $patterns,
            'connect-your-client',
            ['C40'],
            ['A04', 'A31'],
            'POST',
            '/connection/revoke',
            static function ($a): array {
                if ($a->connectionId === null) {
                    throw new OperationException('not_found', 404);
                }

                (new ConnectionService(Operations::registry()))->revoke($a, $a->connectionId);
                return ['revoked' => true];
            },
            previewHandler: static fn($a) => [
                'connection_id' => $a->connectionId,
                'effect' => 'revoke_current_connection'
            ]
        );
        return $definitions;
    }
}
