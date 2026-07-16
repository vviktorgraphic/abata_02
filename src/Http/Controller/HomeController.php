<?php

declare(strict_types=1);

namespace App\Http\Controller;

final class HomeController
{
    public function index(): void
    {
        $this->json(['name' => 'Foglalási rendszer', 'status' => 'ready']);
    }

    public function health(): void
    {
        $this->json(['status' => 'ok', 'time' => (new \DateTimeImmutable())->format(DATE_ATOM)]);
    }

    public function adminLogin(): void
    {
        $this->json(['message' => 'Admin login endpoint placeholder']);
    }

    /** @param array<string, mixed> $payload */
    private function json(array $payload): void
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
}
