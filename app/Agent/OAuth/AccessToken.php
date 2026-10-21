<?php

declare(strict_types=1);
namespace App\Agent\OAuth;

use League\OAuth2\Server\CryptKeyInterface;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\Traits\EntityTrait;
use League\OAuth2\Server\Entities\Traits\TokenEntityTrait;

final class AccessToken implements AccessTokenEntityInterface
{
    use EntityTrait;
    use TokenEntityTrait;

    public string $audience = '';
    private ?string $rawToken = null;

    public function setRawToken(string $rawToken): void { $this->rawToken = $rawToken; }

    public function setPrivateKey(CryptKeyInterface $privateKey): void
    {
        // The application uses revocable opaque credentials, so there is no JWT to sign.
    }

    public function toString(): string
    {
        return $this->rawToken ?? throw new \LogicException('Access token has not been persisted.');
    }
}
