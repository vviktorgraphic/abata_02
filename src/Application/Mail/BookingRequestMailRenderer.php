<?php

declare(strict_types=1);

namespace App\Application\Mail;

final readonly class BookingRequestMailRenderer
{
    public function __construct(
        private string $templateDirectory,
        private string $fromEmail,
        private string $fromName,
        private string $guestReplyToEmail,
        private string $guestReplyToName,
    ) {
    }

    public function render(BookingRequestMailData $data): Message
    {
        $owner = $data->messageType === 'booking_request_admin_notification';
        return new Message(
            $this->fromEmail,
            $data->recipient,
            $owner ? 'Új foglalási igény érkezett' : 'A Bata – Foglalását megkaptuk',
            $this->renderTemplate($owner ? 'booking-admin-notification.txt.php' : 'booking-request.txt.php', $data),
            $this->renderTemplate($owner ? 'booking-admin-notification.html.php' : 'booking-request.html.php', $data),
            fromName: $this->fromName,
            replyToEmail: $owner ? null : $this->guestReplyToEmail,
            replyToName: $owner ? null : $this->guestReplyToName,
        );
    }

    private function renderTemplate(string $file, BookingRequestMailData $data): string
    {
        $path = $this->templateDirectory . '/' . $file;
        if (!is_file($path)) {
            throw new \RuntimeException('A foglalási e-mail sablon nem olvasható.');
        }
        ob_start();
        require $path;

        return (string) ob_get_clean();
    }
}
