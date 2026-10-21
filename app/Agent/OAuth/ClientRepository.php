<?php

declare(strict_types=1);

namespace App\Agent\OAuth;

use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Repositories\ClientRepositoryInterface;
use PDO;

final class ClientRepository implements ClientRepositoryInterface
{
    /** Records fetched during this request, so no-store client metadata still works once. */
    private array $fresh = [];

    public function __construct(private readonly PDO $db) {}

    public function record(string $identifier): ?array
    {
        if (isset($this->fresh[$identifier])) {
            return $this->fresh[$identifier];
        }
        $statement = $this->db->prepare('SELECT * FROM oauth_clients WHERE id = ? AND revoked_at IS NULL');
        $statement->execute([$identifier]);
        $record = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$record
            || ($record['metadata_expires_at'] !== null && strtotime($record['metadata_expires_at'] . ' UTC') <= time())) {
            return null;
        }
        return $record;
    }

    public function remember(array $record): void
    {
        $this->fresh[$record['id']] = $record;
    }

    public function getClientEntity(string $clientIdentifier): ?ClientEntityInterface
    {
        $record = $this->record($clientIdentifier);
        return $record === null ? null : new Client($record);
    }

    public function validateClient(string $clientIdentifier, ?string $clientSecret, ?string $grantType): bool
    {
        $record = $this->record($clientIdentifier);
        if ($record === null || !in_array($grantType, ['authorization_code', 'refresh_token'], true)) {
            return false;
        }
        return $record['auth_method'] === 'none'
            ? $clientSecret === null || $clientSecret === ''
            : is_string($clientSecret)
                && is_string($record['secret_hash'])
                && password_verify($clientSecret, $record['secret_hash']);
    }
}
