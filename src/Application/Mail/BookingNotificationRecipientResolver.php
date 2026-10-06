<?php

declare(strict_types=1);

namespace App\Application\Mail;

final readonly class BookingNotificationRecipientResolver
{
    public function __construct(
        private BookingNotificationRecipientProvider $provider,
        private string $fallbackEmail,
    ) {
    }

    /** @return list<string> */
    public function recipients(): array
    {
        $recipients = [];
        foreach ($this->provider->bookingNotificationRecipients() as $email) {
            $normalized = mb_strtolower(trim($email), 'UTF-8');
            if ($normalized !== '' && filter_var($normalized, FILTER_VALIDATE_EMAIL) !== false) {
                $recipients[$normalized] = $normalized;
            }
        }

        if ($recipients !== []) {
            return array_values($recipients);
        }

        $fallback = mb_strtolower(trim($this->fallbackEmail), 'UTF-8');
        return [$fallback];
    }
}
