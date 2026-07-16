<?php

declare(strict_types=1);

namespace App\Security\Session;

interface SessionStorage
{
    /** Starts the session when it is not already active. */
    public function start(): void;

    /** @return mixed The stored value or the supplied default. */
    public function get(string $key, mixed $default = null): mixed;

    public function set(string $key, mixed $value): void;

    public function remove(string $key): void;

    /** Removes all server-side session data and expires its cookie. */
    public function destroy(): void;
}
