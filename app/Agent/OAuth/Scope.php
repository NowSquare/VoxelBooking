<?php

declare(strict_types=1);

namespace App\Agent\OAuth;

use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Entities\Traits\EntityTrait;
use League\OAuth2\Server\Entities\Traits\ScopeTrait;

final class Scope implements ScopeEntityInterface
{
    use EntityTrait;
    use ScopeTrait;

    public function __construct(string $identifier)
    {
        $this->identifier = $identifier;
    }
}
