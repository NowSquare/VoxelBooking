<?php

declare(strict_types=1);

namespace App\Agent\OAuth;

use App\Agent\ConnectionService;
use App\Agent\Endpoints;
use App\Agent\OperationException;
use App\Agent\OperationRegistry;
use App\Agent\PrincipalResolver;
use App\Engine\Database;

final class Factory
{
    public readonly ClientRepository $clients;
    public readonly ClientRegistration $registration;
    public readonly AuthorizationService $authorization;

    public function __construct(OperationRegistry $registry, ?string $issuer = null)
    {
        $db = Database::pdo();
        $issuer ??= Endpoints::baseUrl();
        $connectionService = new ConnectionService($registry);
        $this->clients = new ClientRepository($db);
        $this->registration = new ClientRegistration($db, $this->clients);
        $tokens = new TokenRepository(
            $db,
            static fn (string $connection, string $audience, string $expiry, array $scopes): array
                => $connectionService->issueCredential($connection, $audience, $expiry, $scopes),
            static function (string $connection) use ($db): void {
                $query = $db->prepare('SELECT * FROM agent_connections WHERE id = ?');
                $query->execute([$connection]);
                $record = $query->fetch();
                if (!$record) {
                    throw new OperationException('unauthorized', 401);
                }
                (new PrincipalResolver($db))->resolve(
                    $record['principal_type'],
                    $record['principal_id'],
                    json_decode($record['business_ids'], true, flags: JSON_THROW_ON_ERROR),
                    json_decode($record['scopes'], true, flags: JSON_THROW_ON_ERROR),
                    $record['identity_hash'],
                    $record['id'],
                    customerCapabilityId: $record['customer_capability_id'] ?? null,
                );
            },
            static function (string $id) use ($db): void {
                $db->prepare('UPDATE agent_credentials SET revoked_at = COALESCE(revoked_at, ?) WHERE id = ?')
                    ->execute([gmdate('Y-m-d H:i:s'), $id]);
            },
        );
        $scopes = [];
        foreach ($registry->all() as $operation) {
            $scopes = [...$scopes, ...$operation->requiredScopes];
        }
        $keys = (new KeyStore(dirname(__DIR__, 3) . '/storage/keys'))->load();
        $this->authorization = new AuthorizationService(
            $db,
            $this->clients,
            $tokens,
            array_values(array_unique($scopes)),
            $issuer,
            [$issuer . '/mcp', $issuer . '/api/agent/v2'],
            $keys['private_key'],
            $keys['encryption_key']
        );
    }
}
