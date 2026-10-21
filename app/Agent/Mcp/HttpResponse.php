<?php

declare(strict_types=1);

namespace App\Agent\Mcp;

final readonly class HttpResponse
{
    public function __construct(
        public int $status,
        public ?array $body = null,
        public array $headers = [],
    ) {
    }

    public function json(): string
    {
        return $this->body === null ? '' : json_encode(
            $this->body,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }
}
