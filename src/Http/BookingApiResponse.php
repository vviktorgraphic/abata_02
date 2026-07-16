<?php

declare(strict_types=1);

namespace App\Http;

final readonly class BookingApiResponse
{
    /** @param array<string, mixed> $payload @param array<string, string> $headers */
    public function __construct(
        public array $payload,
        public int $status,
        public array $headers = [],
    ) {
    }

    public function send(): void
    {
        http_response_code($this->status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        echo json_encode($this->payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
