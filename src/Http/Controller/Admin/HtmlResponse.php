<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

final readonly class HtmlResponse implements AdminResponse
{
    /** @param array<string, string> $headers */
    public function __construct(
        public string $body,
        public int $status = 200,
        public array $headers = [],
    ) {
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach (array_merge(SecurityHeaders::admin(), $this->headers) as $name => $value) {
            header($name . ': ' . $value);
        }
        header('Content-Type: text/html; charset=utf-8');
        echo $this->body;
    }
}
