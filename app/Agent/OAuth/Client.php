<?php

declare(strict_types=1);

namespace App\Agent\OAuth;

use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\Traits\ClientTrait;
use League\OAuth2\Server\Entities\Traits\EntityTrait;

final class Client implements ClientEntityInterface
{
    use EntityTrait;
    use ClientTrait;

    /** How the client was registered: `preregistered`, `dynamic` or `cimd` (metadata document). */
    public readonly string $registrationType;

    public function __construct(array $record)
    {
        $this->registrationType = (string) ($record['registration_type'] ?? 'dynamic');
        $this->identifier = $record['id'];
        $this->name = $record['name'];
        $this->redirectUri = json_decode($record['redirect_uris'], true, flags: JSON_THROW_ON_ERROR);
        $this->isConfidential = $record['auth_method'] !== 'none';
    }

    /**
     * Only an administrator's registration vouches for the name. Dynamic and
     * metadata-document clients name themselves.
     */
    public function hasVerifiedName(): bool
    {
        return $this->registrationType === 'preregistered';
    }

    public function supportsGrantType(string $grantType): bool
    {
        return in_array($grantType, ['authorization_code', 'refresh_token'], true);
    }
}
