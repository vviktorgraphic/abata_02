<?php

declare(strict_types=1);

namespace App\Infrastructure\Mail;

use App\Application\Mail\Mailer;
use App\Application\Mail\Message;

final readonly class SmtpMailer implements Mailer
{
    public function __construct(private SmtpConfiguration $configuration)
    {
    }

    public function send(Message $message): void
    {
        $prefix = $this->configuration->encryption === 'ssl' ? 'ssl://' : '';
        $context = stream_context_create(['ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
        ]]);
        $socket = @stream_socket_client(
            $prefix . $this->configuration->host . ':' . $this->configuration->port,
            $errorNumber,
            $errorMessage,
            $this->configuration->timeoutSeconds,
            STREAM_CLIENT_CONNECT,
            $context,
        );
        if ($socket === false) {
            throw new MailTransportException('Nem sikerült kapcsolódni az SMTP szolgáltatáshoz.');
        }

        try {
            stream_set_timeout($socket, $this->configuration->timeoutSeconds);
            $this->expect($socket, [220]);
            $this->command($socket, 'EHLO abata.local', [250]);

            if ($this->configuration->encryption === 'tls') {
                $this->command($socket, 'STARTTLS', [220]);
                if (@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) !== true) {
                    throw new MailTransportException('Az SMTP TLS kapcsolat létrehozása sikertelen.');
                }
                $this->command($socket, 'EHLO abata.local', [250]);
            }

            if ($this->configuration->username !== null) {
                $this->command($socket, 'AUTH LOGIN', [334]);
                $this->command($socket, base64_encode($this->configuration->username), [334]);
                $this->command($socket, base64_encode((string) $this->configuration->password), [235]);
            }

            $this->command($socket, 'MAIL FROM:<' . $message->from . '>', [250]);
            $this->command($socket, 'RCPT TO:<' . $message->to . '>', [250, 251]);
            $this->command($socket, 'DATA', [354]);
            $payload = $this->mimeMessage($message);
            fwrite($socket, $this->dotStuff($payload) . "\r\n.\r\n");
            $this->expect($socket, [250]);
            $this->command($socket, 'QUIT', [221]);
        } finally {
            fclose($socket);
        }
    }

    /** @param resource $socket @param list<int> $codes */
    private function command($socket, string $command, array $codes): void
    {
        if (fwrite($socket, $command . "\r\n") === false) {
            throw new MailTransportException('Az SMTP parancs elküldése sikertelen.');
        }
        $this->expect($socket, $codes);
    }

    /** @param resource $socket @param list<int> $acceptedCodes */
    private function expect($socket, array $acceptedCodes): void
    {
        $last = '';
        do {
            $line = fgets($socket, 1024);
            if ($line === false) {
                throw new MailTransportException('Az SMTP szolgáltató nem adott érvényes választ.');
            }
            $last = $line;
        } while (isset($line[3]) && $line[3] === '-');

        $code = (int) substr($last, 0, 3);
        if (!in_array($code, $acceptedCodes, true)) {
            // Deliberately omit the provider response: it may echo credentials or PII.
            throw new MailTransportException('Az SMTP szolgáltató elutasította a műveletet (kód: ' . $code . ').');
        }
    }

    private function mimeMessage(Message $message): string
    {
        $boundary = 'abata_' . bin2hex(random_bytes(18));
        $headers = [
            'Date: ' . date(DATE_RFC2822),
            'From: <' . $message->from . '>',
            'To: <' . $message->to . '>',
            'Subject: =?UTF-8?B?' . base64_encode($message->subject) . '?=',
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        ];
        $parts = [
            '--' . $boundary,
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            '',
            chunk_split(base64_encode($message->textBody), 76, "\r\n"),
            '--' . $boundary,
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            '',
            chunk_split(base64_encode($message->htmlBody), 76, "\r\n"),
            '--' . $boundary . '--',
        ];

        return implode("\r\n", $headers) . "\r\n\r\n" . implode("\r\n", $parts);
    }

    private function dotStuff(string $payload): string
    {
        $normalized = preg_replace("/\r?\n/", "\r\n", $payload) ?? $payload;
        return preg_replace('/^\./m', '..', $normalized) ?? $normalized;
    }
}
