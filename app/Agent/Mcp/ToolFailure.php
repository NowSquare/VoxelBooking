<?php

declare(strict_types=1);

namespace App\Agent\Mcp;

use RuntimeException;

/** The executor supplies a sanitized, localized problem document. */
final class ToolFailure extends RuntimeException
{
    public function __construct(public readonly array $problem)
    {
        parent::__construct((string) ($problem['code'] ?? 'operation_failed'));
    }
}
