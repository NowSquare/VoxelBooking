<?php

declare(strict_types=1);

namespace App\Agent\Mcp;

use RuntimeException;

final class ProtocolError extends RuntimeException
{
    public function __construct(
        int $code,
        string $message,
        public readonly int $httpStatus = 400,
        public readonly ?array $data = null,
    ) {
        parent::__construct($message, $code);
    }
}
