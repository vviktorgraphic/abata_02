<?php

declare(strict_types=1);

namespace App\Application\Mail;

final readonly class BookingPaymentRequestMailRenderer
{
    public function __construct(private string $templateDirectory, private string $fromEmail)
    {
    }

    public function render(BookingPaymentRequestMailData $data): Message
    {
        return new Message($this->fromEmail, $data->recipient, $data->subject(),
            $this->template('txt', $data), $this->template('html', $data));
    }

    private function template(string $format, BookingPaymentRequestMailData $data): string
    {
        $suffix = $data->templateVersion === 1 ? '-v1' : '';
        $path = $this->templateDirectory . '/booking-payment-request' . $suffix . '.' . $format . '.php';
        if (!is_file($path)) {
            throw new \RuntimeException('A díjbekérő e-mail sablon nem olvasható.');
        }
        ob_start();
        try {
            require $path;
            return (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }
}
