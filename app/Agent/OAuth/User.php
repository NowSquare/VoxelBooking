<?php

declare(strict_types=1);
namespace App\Agent\OAuth;

use League\OAuth2\Server\Entities\UserEntityInterface;

/** A consent-bound connection, never an unverified principal supplied by the client. */
final readonly class User implements UserEntityInterface
{
    public function __construct(private string $connectionId) {}
    public function getIdentifier(): string { return $this->connectionId; }
}
