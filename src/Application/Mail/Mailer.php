<?php

declare(strict_types=1);

namespace App\Application\Mail;

interface Mailer
{
    public function send(Message $message): void;
}
