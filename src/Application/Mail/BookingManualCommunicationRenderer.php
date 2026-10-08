<?php

declare(strict_types=1);

namespace App\Application\Mail;

final readonly class BookingManualCommunicationRenderer
{
    private const ARRIVAL_IMAGES = [
        'arrival-front' => 'bata1.jpg',
        'arrival-mailboxes' => 'bata2.jpg',
        'arrival-keybox-safe' => 'bata3-safe.jpg',
        'arrival-inside' => 'bata4.jpg',
    ];

    public function __construct(
        private string $templateDirectory,
        private string $arrivalAssetDirectory,
        private string $fromEmail,
    ) {}

    /** @param array<string,mixed> $payload */
    public function render(string $type, array $payload): Message
    {
        $subjects = [
            'booking_payment_reminder' => 'Emlékeztető – foglalási előleg',
            'booking_arrival_information' => 'Érkezési tájékoztató – A Bata',
        ];
        if (!isset($subjects[$type])) {
            throw new \InvalidArgumentException('Nem támogatott vendégkommunikáció.');
        }
        $name = $type === 'booking_payment_reminder' ? 'booking-payment-reminder' : 'booking-arrival-information';
        $attachments = [];
        if ($type === 'booking_arrival_information') {
            foreach (self::ARRIVAL_IMAGES as $contentId => $filename) {
                $path = $this->arrivalAssetDirectory . DIRECTORY_SEPARATOR . $filename;
                $bytes = is_file($path) ? file_get_contents($path) : false;
                if (!is_string($bytes)) {
                    throw new \RuntimeException('A required érkezési kép nem olvasható: ' . $filename);
                }
                $attachments[] = new InlineAttachment($contentId, $filename, 'image/jpeg', $bytes);
            }
        }
        return new Message(
            $this->fromEmail,
            (string) $payload['recipient'],
            $subjects[$type],
            $this->template($name . '.txt.php', $payload),
            $this->template($name . '.html.php', $payload),
            $attachments,
        );
    }

    /** @param array<string,mixed> $payload */
    private function template(string $file, array $payload): string
    {
        $path = $this->templateDirectory . DIRECTORY_SEPARATOR . $file;
        if (!is_file($path)) throw new \RuntimeException('A vendégkommunikáció sablonja nem olvasható.');
        ob_start();
        try {
            require $path;
            return (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }
}
