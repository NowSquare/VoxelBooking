<?php

declare(strict_types=1);

namespace App\Agent\OAuth;

use App\Agent\RateBudget;
use PDO;

/**
 * Admits OAuth clients from three sources into one bounded registry.
 *
 * Dynamic registration and client metadata documents are open to anyone on the internet, so both spend
 * the same registry capacity and each is metered per source. Operators preregister deliberately and are
 * never metered: a flood of anonymous registrations must not lock the administrator out.
 */
final class ClientRegistration
{
    /** Every client row counts, whatever its source; Maintenance prunes idle anonymous clients after 90 days. */
    public const REGISTRY_CAPACITY = 10000;

    /** A real client registers once per install; twenty an hour covers retries and several agents behind one NAT. */
    public const DYNAMIC_REGISTRATIONS_PER_ORIGIN_PER_HOUR = 20;

    /** New metadata-document clients one host may introduce; a client publishes one or two documents, not hundreds. */
    public const METADATA_CLIENTS_PER_HOST_PER_HOUR = 10;

    /**
     * Outbound fetches toward one host. no-store documents are fetched on every authorize and token request,
     * so this is generous for honest clients while keeping us from being used to hammer a third party.
     */
    public const METADATA_FETCHES_PER_HOST_PER_HOUR = 120;

    private const BUDGET_WINDOW_SECONDS = 3600;

    private readonly RateBudget $budgets;

    public function __construct(
        private readonly PDO $db,
        private readonly ClientRepository $clients,
        private readonly MetadataFetcher $fetcher = new MetadataFetcher(),
        ?RateBudget $budgets = null
    ) {
        $this->budgets = $budgets ?? new RateBudget($db);
    }

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
        $host = strtolower((string) parse_url($clientId, PHP_URL_HOST));
        if ($host === '') {
            throw new \InvalidArgumentException('invalid_client_id');
        }
        $registered = $record !== false;
        $budgets = ['oauth:metadata:fetch:' . $host => self::METADATA_FETCHES_PER_HOST_PER_HOUR];
        if (!$registered) {
            // Refreshing a known document never grows the registry, so only a new one needs capacity.
            $this->assertCapacity();
            $budgets['oauth:metadata:client:' . $host] = self::METADATA_CLIENTS_PER_HOST_PER_HOUR;
        }
        $this->admit($budgets);
        $fetched = $this->fetcher->fetch($clientId);
        $metadata = $fetched['metadata'];
        $record = [
            'id' => $clientId,
            'name' => $metadata['client_name'],
            'redirect_uris' => json_encode($metadata['redirect_uris'], JSON_THROW_ON_ERROR),
            'secret_hash' => null,
            'auth_method' => 'none',
            'registration_type' => 'cimd',
            'metadata_expires_at' => gmdate('Y-m-d H:i:s', time() + $fetched['ttl']),
            'revoked_at' => null,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ];
        if ($registered) {
            $this->db
                ->prepare('UPDATE oauth_clients SET name = ?, redirect_uris = ?, metadata_expires_at = ? WHERE id = ? AND revoked_at IS NULL')
                ->execute([$record['name'], $record['redirect_uris'], $record['metadata_expires_at'], $clientId]);
        } else {
            $this->insert($record);
        }
        // no-store CIMD remains usable for this request but is fetched again next time.
        $this->clients->remember($record);
        return new Client($record);
    }

    /** @param ?string $origin The caller's network address; dynamic registrations from the same origin share a budget. */
    public function register(array $input, string $type = 'dynamic', ?string $origin = null): array
    {
        if (!in_array($type, ['dynamic', 'preregistered'], true)) {
            throw new \InvalidArgumentException('invalid_registration_type');
        }
        $anonymous = $type === 'dynamic';
        if ($anonymous) {
            $this->assertCapacity();
        }
        $metadata = ClientMetadata::validate($input);
        // Metered after validation, so a client correcting malformed metadata does not spend its budget.
        if ($anonymous && $origin !== null) {
            $this->admit(['oauth:registration:origin:' . $origin => self::DYNAMIC_REGISTRATIONS_PER_ORIGIN_PER_HOUR]);
        }
        $id = 'vbo_' . bin2hex(random_bytes(24));
        $secret = $metadata['token_endpoint_auth_method'] !== 'none' ? bin2hex(random_bytes(32)) : null;
        $this->insert([
            'id' => $id,
            'name' => $metadata['client_name'],
            'redirect_uris' => json_encode($metadata['redirect_uris'], JSON_THROW_ON_ERROR),
            'secret_hash' => $secret === null ? null : password_hash($secret, PASSWORD_DEFAULT),
            'auth_method' => $metadata['token_endpoint_auth_method'],
            'registration_type' => $type,
            'metadata_expires_at' => null,
            'revoked_at' => null,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $result = ['client_id' => $id, 'client_id_issued_at' => time()] + $metadata;
        if ($secret !== null) {
            $result['client_secret'] = $secret;
            $result['client_secret_expires_at'] = 0;
        }
        return $result;
    }

    private function assertCapacity(): void
    {
        if ((int) $this->db->query('SELECT COUNT(*) FROM oauth_clients')->fetchColumn() >= self::REGISTRY_CAPACITY) {
            throw new \InvalidArgumentException('registration_limit');
        }
    }

    /** @param array<string, int> $budgets */
    private function admit(array $budgets): void
    {
        $admission = $this->budgets->consume($budgets, window: self::BUDGET_WINDOW_SECONDS);
        if (!$admission['allowed']) {
            throw new RegistrationThrottled($admission['retry_after']);
        }
    }

    private function insert(array $record): void
    {
        // Column order matches the key order of every $record built in this class.
        $this->db
            ->prepare('INSERT INTO oauth_clients (id, name, redirect_uris, secret_hash, auth_method, registration_type, metadata_expires_at, revoked_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute(array_values($record));
    }
}
