<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

interface AdminAuthWorkflow
{
    /** @param array<string, string> $requestContext */
    public function login(string $email, string $password, array $requestContext = []): bool;

    /** @param array<string, string> $requestContext */
    public function verify(string $code, array $requestContext = []): bool;

    /** @param array<string, string> $requestContext */
    public function resend(array $requestContext = []): bool;

    /** @param array<string, string> $requestContext */
    public function logout(array $requestContext = []): void;

    /** @return array{id: int, name: string}|null */
    public function currentAdmin(): ?array;
}
