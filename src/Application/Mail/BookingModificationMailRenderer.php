<?php
declare(strict_types=1);
namespace App\Application\Mail;

final readonly class BookingModificationMailRenderer
{
    public function __construct(
        private string $templateDirectory,
        private string $fromEmail,
        private string $fromName,
        private string $guestReplyToEmail,
        private string $guestReplyToName,
    ) {}

    public function render(BookingModificationMailData $data): Message
    {
        return new Message(
            $this->fromEmail, $data->recipient, 'Foglalásának adatai módosultak',
            $this->template('txt', $data), $this->template('html', $data),
            fromName: $this->fromName, replyToEmail: $this->guestReplyToEmail, replyToName: $this->guestReplyToName,
        );
    }

    private function template(string $format, BookingModificationMailData $data): string
    {
        $path = $this->templateDirectory . '/booking-modified.' . $format . '.php';
        if (!is_file($path)) throw new \RuntimeException('A foglalásmódosítási e-mail sablon nem olvasható.');
        ob_start();
        try { require $path; return (string) ob_get_contents(); }
        finally { ob_end_clean(); }
    }
}
