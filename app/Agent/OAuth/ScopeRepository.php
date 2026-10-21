<?php

declare(strict_types=1);
namespace App\Agent\OAuth;

use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Repositories\ScopeRepositoryInterface;

final class ScopeRepository implements ScopeRepositoryInterface
{
    public function __construct(private readonly array $allowedScopes, private readonly TokenRepository $tokens) {}

    public function getScopeEntityByIdentifier(string $identifier): ?ScopeEntityInterface
    {
        return in_array($identifier, $this->allowedScopes, true) ? new Scope($identifier) : null;
    }

    public function finalizeScopes(array $scopes, string $grantType, ClientEntityInterface $clientEntity, ?string $userIdentifier = null, ?string $authCodeId = null): array
    {
        if ($userIdentifier === null) {
            throw OAuthServerException::invalidGrant();
        }
        $grant = $this->tokens->grant($userIdentifier, $clientEntity->getIdentifier());
        foreach ($scopes as $scope) {
            if (!in_array($scope->getIdentifier(), $grant['scopes_array'], true)) {
                throw OAuthServerException::invalidScope($scope->getIdentifier());
            }
        }
        return $scopes;
    }
}
