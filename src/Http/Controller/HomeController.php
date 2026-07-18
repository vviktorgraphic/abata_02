<?php

declare(strict_types=1);

namespace App\Http\Controller;

final readonly class HomeController
{
    public function __construct(private string $templateDirectory, private string $bookingPolicyUrl = '/foglalasi-szabalyzat')
    {
    }

    /** @param array<string, mixed> $query */
    public function index(array $query = []): void
    {
        $bookingPolicyUrl = $this->bookingPolicyUrl;
        require $this->templateDirectory . '/booking/index.php';
    }

    /** @param array<string, mixed> $query */
    public function health(array $query = []): void
    {
        $this->json(['status' => 'ok', 'time' => (new \DateTimeImmutable())->format(DATE_ATOM)]);
    }

    /** @param array<string, mixed> $query */
    public function adminLogin(array $query = []): void
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
