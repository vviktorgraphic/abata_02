<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Security\Session\SessionStorage;

final readonly class AdminBookingFlashStore
{
    private const KEY = 'admin_booking_success_messages';

    public function __construct(private SessionStorage $session)
    {
    }

    public function put(string $reference, string $message): void
    {
        $messages = $this->session->get(self::KEY, []);
        $messages = is_array($messages) ? $messages : [];
        $messages[$reference] = $message;
        $this->session->set(self::KEY, $messages);
    }

    public function consume(string $reference): ?string
    {
        $messages = $this->session->get(self::KEY, []);
        if (!is_array($messages) || !is_string($messages[$reference] ?? null)) {
            return null;
        }
        $message = $messages[$reference];
        unset($messages[$reference]);
        if ($messages === []) {
            $this->session->remove(self::KEY);
        } else {
            $this->session->set(self::KEY, $messages);
        }

        return $message;
    }
}
