<?php

declare(strict_types=1);

namespace App\Http\Controller;

final readonly class LegalPageController
{
    public function __construct(private string $templateDirectory) {}

    /** @param array<string, mixed> $query */
    public function bookingPolicy(array $query = []): void
    {
        $this->render('Foglalási szabályzat', 'booking-policy');
    }

    /** @param array<string, mixed> $query */
    public function privacyPolicy(array $query = []): void
    {
        $this->render('Adatkezelési tájékoztató', 'privacy-policy');
    }

    private function render(string $pageTitle, string $contentType): void
    {
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header("Content-Security-Policy: default-src 'none'; style-src 'self'; img-src 'self'; base-uri 'none'; frame-ancestors 'none'; form-action 'none'");
        require $this->templateDirectory . '/legal/pending-approval.php';
    }
}
