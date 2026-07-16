<?php

declare(strict_types=1);

namespace App\Application\Mail;

use InvalidArgumentException;
use RuntimeException;

final readonly class TwoFactorMailRenderer
{
    public function __construct(
        private string $templateDirectory,
        private string $fromAddress,
    ) {
    }

    public function render(string $recipient, string $code): Message
    {
        if (preg_match('/^\d{6}$/D', $code) !== 1) {
            throw new InvalidArgumentException('A kétlépcsős kódnak pontosan 6 számjegyből kell állnia.');
        }

        $text = $this->load('two-factor.txt');
        $html = $this->load('two-factor.html');

        return new Message(
            $this->fromAddress,
            $recipient,
            'A Bata admin belépési ellenőrzés',
            str_replace('{{code}}', $code, $text),
            str_replace('{{code}}', htmlspecialchars($code, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $html),
        );
    }

    private function load(string $name): string
    {
        $content = @file_get_contents(rtrim($this->templateDirectory, '/\\') . DIRECTORY_SEPARATOR . $name);
        if ($content === false || $content === '') {
            throw new RuntimeException('A 2FA e-mail sablon nem olvasható.');
        }

        return $content;
    }
}
