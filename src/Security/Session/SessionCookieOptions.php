<?php

declare(strict_types=1);

namespace App\Security\Session;

use InvalidArgumentException;

final readonly class SessionCookieOptions
{
    public function __construct(
        public bool $secure = false,
        public bool $httpOnly = true,
        public string $sameSite = 'Lax',
        public string $path = '/',
    ) {
        if (!in_array($this->sameSite, ['Lax', 'Strict', 'None'], true)) {
            throw new InvalidArgumentException('Session cookie SameSite must be Lax, Strict or None.');
        }

        if ($this->sameSite === 'None' && !$this->secure) {
            throw new InvalidArgumentException('SameSite=None session cookies must be Secure.');
        }
    }

    /** @return array{lifetime: int, path: string, secure: bool, httponly: bool, samesite: string} */
    public function toPhpOptions(): array
    {
        return [
            'lifetime' => 0,
            'path' => $this->path,
            'secure' => $this->secure,
            'httponly' => $this->httpOnly,
            'samesite' => $this->sameSite,
        ];
    }
}
