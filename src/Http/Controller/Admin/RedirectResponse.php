<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

final readonly class RedirectResponse implements AdminResponse
{
    public function __construct(public string $location, public int $status = 303)
    {
        if (!str_starts_with($this->location, '/') || str_starts_with($this->location, '//')) {
            throw new \InvalidArgumentException('Only local absolute-path redirects are allowed.');
        }
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach (SecurityHeaders::admin() as $name => $value) {
            header($name . ': ' . $value);
        }
        header('Location: ' . $this->location);
    }
}
