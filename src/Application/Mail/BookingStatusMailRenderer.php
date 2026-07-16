<?php

declare(strict_types=1);

namespace App\Application\Mail;

final readonly class BookingStatusMailRenderer
{
    public function __construct(private string $templateDirectory, private string $fromEmail)
    {
    }

    public function render(BookingStatusMailData $data): Message
    {
        $subjects = [
            BookingStatusMailData::CONFIRMED => 'A Bata – foglalás megerősítve – ' . $data->reference,
            BookingStatusMailData::REJECTED => 'A Bata – foglalási igény elutasítva – ' . $data->reference,
            BookingStatusMailData::CANCELLED => 'A Bata – foglalás lemondva – ' . $data->reference,
        ];

        return new Message(
            $this->fromEmail,
            $data->recipient,
            $subjects[$data->status],
            $this->renderTemplate('booking-status-' . $data->status . '.txt.php', $data),
            $this->renderTemplate('booking-status-' . $data->status . '.html.php', $data),
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
