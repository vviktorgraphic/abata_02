<?php

declare(strict_types=1);

namespace App\Application\Mail;

final readonly class BookingReviewMailRenderer
{
    public function __construct(private string $templateDirectory, private string $fromEmail) {}

    /** @param array{recipient:string} $payload */
    public function render(array $payload): Message
    {
        return new Message($this->fromEmail, $payload['recipient'], 'Értékelés',
            $this->template('booking-review-request.txt.php'), $this->template('booking-review-request.html.php'));
    }

    private function template(string $file): string
    {
        $path=$this->templateDirectory.DIRECTORY_SEPARATOR.$file;
        if (!is_file($path)) throw new \RuntimeException('Az értékeléskérő sablon nem olvasható.');
        ob_start();
        try { require $path; return (string)ob_get_contents(); } finally { ob_end_clean(); }
    }
}
