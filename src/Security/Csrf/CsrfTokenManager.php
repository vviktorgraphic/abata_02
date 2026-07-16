<?php

declare(strict_types=1);

namespace App\Security\Csrf;

use App\Security\Session\SessionStorage;

final class CsrfTokenManager
{
    private const SESSION_KEY = 'csrf_token';

    public function __construct(private readonly SessionStorage $session)
    {
    }

    /** Returns the existing token or creates a cryptographically secure 256-bit token. */
    public function token(): string
    {
        $this->session->start();
        $token = $this->session->get(self::SESSION_KEY);
        if (is_string($token) && $token !== '') {
            return $token;
        }

        $token = bin2hex(random_bytes(32));
        $this->session->set(self::SESSION_KEY, $token);
        return $token;
    }

    /** Performs a timing-safe comparison with the session token. */
    public function isValid(mixed $submittedToken): bool
    {
        $this->session->start();
        $expected = $this->session->get(self::SESSION_KEY);
        return is_string($expected)
            && $expected !== ''
            && is_string($submittedToken)
            && hash_equals($expected, $submittedToken);
    }

    /** Invalidates the current token; the next token() call creates a new one. */
    public function rotate(): string
    {
        $this->session->start();
        $this->session->remove(self::SESSION_KEY);
        return $this->token();
    }
}
