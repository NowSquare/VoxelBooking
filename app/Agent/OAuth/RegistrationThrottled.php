<?php

declare(strict_types=1);

namespace App\Agent\OAuth;

/** A registration source spent its hourly budget; the caller may retry once the window resets. */
final class RegistrationThrottled extends \RuntimeException
{
    public function __construct(public readonly int $retryAfter)
    {
        parent::__construct('registration_throttled');
    }
}
