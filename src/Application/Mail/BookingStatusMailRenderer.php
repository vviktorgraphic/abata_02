<?php

declare(strict_types=1);

namespace App\Application\Mail;

final readonly class BookingStatusMailRenderer
{
    public function __construct(
        private string $templateDirectory,
        private string $fromEmail,
        private string $fromName,
        private string $guestReplyToEmail,
        private string $guestReplyToName,
    )
    {
    }

    public function render(BookingStatusMailData $data): Message
    {
        $subjects = [
            BookingStatusMailData::CONFIRMED => 'Foglalás visszaigazolás',
            BookingStatusMailData::REJECTED => 'Foglalási igényét visszautasítottuk',
            BookingStatusMailData::CANCELLED => 'Foglalási igényét töröltük',
        ];

        return new Message(
            $this->fromEmail,
            $data->recipient,
            $subjects[$data->status],
            $this->renderTemplate('booking-status-' . $data->status . '.txt.php', $data),
            $this->renderTemplate('booking-status-' . $data->status . '.html.php', $data),
            fromName: $this->fromName,
            replyToEmail: $this->guestReplyToEmail,
            replyToName: $this->guestReplyToName,
        );
    }

    private function renderTemplate(string $file, BookingStatusMailData $data): string
    {
        $path = $this->templateDirectory . '/' . $file;
        if (!is_file($path)) {
            throw new \RuntimeException('A státusz e-mail sablon nem olvasható.');
        }
        ob_start();
        require $path;

        return (string) ob_get_clean();
    }
}
