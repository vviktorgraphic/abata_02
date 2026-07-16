<?php

declare(strict_types=1);

namespace App\Application\Mail;

final class InMemoryMailer implements Mailer
{
    /** @var list<Message> */
    private array $messages = [];

    public function send(Message $message): void
    {
        $this->messages[] = $message;
    }

    /** @return list<Message> */
    public function messages(): array
    {
        return $this->messages;
    }

    public function lastMessage(): ?Message
    {
        return $this->messages[array_key_last($this->messages)] ?? null;
    }
}
