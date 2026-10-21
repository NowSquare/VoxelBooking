<?php

declare(strict_types=1);
namespace App\Agent\OAuth;

use PDO;

final class ClientRegistration
{
    public function __construct(private readonly PDO $db, private readonly ClientRepository $clients, private readonly MetadataFetcher $fetcher = new MetadataFetcher()) {}

    public function ensure(string $clientId): Client
    {
        $known = $this->clients->getClientEntity($clientId);
        if ($known instanceof Client) {
            return $known;
        }
        if (!str_starts_with($clientId, 'https://')) {
            throw new \InvalidArgumentException('invalid_client');
        }
        $revoked = $this->db->prepare('SELECT revoked_at FROM oauth_clients WHERE id = ?');
        $revoked->execute([$clientId]);
        $record = $revoked->fetch(PDO::FETCH_ASSOC);
        if ($record && $record['revoked_at'] !== null) {
            throw new \InvalidArgumentException('invalid_client');
        }
        $fetched = $this->fetcher->fetch($clientId);
        $metadata = $fetched['metadata'];
        $record = [
            'id' => $clientId, 'name' => $metadata['client_name'],
            'redirect_uris' => json_encode($metadata['redirect_uris'], JSON_THROW_ON_ERROR),
            'secret_hash' => null, 'auth_method' => 'none', 'registration_type' => 'cimd',
            'metadata_expires_at' => gmdate('Y-m-d H:i:s', time() + $fetched['ttl']),
            'revoked_at' => null, 'created_at' => gmdate('Y-m-d H:i:s'),
        ];
        if ($this->exists($clientId)) {
            $this->db->prepare('UPDATE oauth_clients SET name = ?, redirect_uris = ?, metadata_expires_at = ? WHERE id = ? AND revoked_at IS NULL')
                ->execute([$record['name'], $record['redirect_uris'], $record['metadata_expires_at'], $clientId]);
        } else {
            $this->insert($record);
        }
        // no-store CIMD remains usable for this request but is fetched again next time.
        $this->clients->remember($record);
        return new Client($record);
    }

    public function register(array $input, string $type = 'dynamic'): array
    {
        if (!in_array($type, ['dynamic', 'preregistered'], true)) {
            throw new \InvalidArgumentException('invalid_registration_type');
        }
        if ((int) $this->db->query('SELECT COUNT(*) FROM oauth_clients')->fetchColumn() >= 10000) {
            throw new \InvalidArgumentException('registration_limit');
        }
        $metadata = ClientMetadata::validate($input);
        $id = 'vbo_' . bin2hex(random_bytes(24));
        $secret = $metadata['token_endpoint_auth_method'] !== 'none' ? bin2hex(random_bytes(32)) : null;
        $this->insert([
            'id' => $id, 'name' => $metadata['client_name'],
            'redirect_uris' => json_encode($metadata['redirect_uris'], JSON_THROW_ON_ERROR),
            'secret_hash' => $secret === null ? null : password_hash($secret, PASSWORD_DEFAULT),
            'auth_method' => $metadata['token_endpoint_auth_method'], 'registration_type' => $type,
            'metadata_expires_at' => null, 'revoked_at' => null, 'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $result = ['client_id' => $id, 'client_id_issued_at' => time()] + $metadata;
        if ($secret !== null) {
            $result['client_secret'] = $secret;
            $result['client_secret_expires_at'] = 0;
        }
        return $result;
    }

    private function exists(string $id): bool
    {
        $statement = $this->db->prepare('SELECT 1 FROM oauth_clients WHERE id = ?');
        $statement->execute([$id]);
        return $statement->fetchColumn() !== false;
    }

    private function insert(array $record): void
    {
        $this->db->prepare('INSERT INTO oauth_clients (id, name, redirect_uris, secret_hash, auth_method, registration_type, metadata_expires_at, revoked_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute(array_values($record));
    }
}
