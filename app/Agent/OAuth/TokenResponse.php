<?php

declare(strict_types=1);
namespace App\Agent\OAuth;

use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\ResponseTypes\BearerTokenResponse;

final class TokenResponse extends BearerTokenResponse
{
    protected function getExtraParams(AccessTokenEntityInterface $accessToken): array
    {
        return ['scope' => implode(' ', array_map(static fn ($scope) => $scope->getIdentifier(), $accessToken->getScopes()))];
    }
}
