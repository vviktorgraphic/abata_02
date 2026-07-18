<?php

declare(strict_types=1);

namespace App\Http\Controller;

final readonly class HomeController
{
    public function __construct(
        private string $templateDirectory,
        private string $bookingPolicyUrl = '/foglalasi-szabalyzat',
        private string $privacyPolicyUrl = '/adatkezelesi_tajekoztato',
        private ?\Closure $readinessCheck = null,
    )
    {
    }

    /** @param array<string, mixed> $query */
    public function index(array $query = []): void
    {
        $bookingPolicyUrl = $this->bookingPolicyUrl;
        $privacyPolicyUrl = $this->privacyPolicyUrl;
        require $this->templateDirectory . '/booking/index.php';
    }

    /** @param array<string, mixed> $query */
    public function health(array $query = []): void
    {
        try {
            if ($this->readinessCheck !== null && ($this->readinessCheck)() !== true) {
                throw new \RuntimeException('Readiness check failed.');
            }

            http_response_code(200);
            $this->json(['status' => 'ok']);
        } catch (\Throwable) {
            http_response_code(503);
            $this->json(['status' => 'unavailable']);
        }
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
