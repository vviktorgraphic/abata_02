<?php

declare(strict_types=1);

namespace App\Infrastructure\Mail;

use InvalidArgumentException;

final readonly class SmtpConfiguration
{
    public function __construct(
        public string $host,
        public int $port,
        public string $encryption = 'none',
        public ?string $username = null,
        #[\SensitiveParameter] public ?string $password = null,
        public int $timeoutSeconds = 10,
        public bool $production = false,
    ) {
        if (!$this->isValidHost($host) || $port < 1 || $port > 65535) {
            throw new InvalidArgumentException('Érvénytelen SMTP host vagy port.');
        }
        if (!in_array($encryption, ['none', 'tls', 'ssl'], true)) {
            throw new InvalidArgumentException('Az SMTP titkosítás csak none, tls vagy ssl lehet.');
        }
        if (($username === null) !== ($password === null)) {
            throw new InvalidArgumentException('Az SMTP felhasználónevet és jelszót együtt kell megadni.');
        }
        if ($username !== null && (trim($username) === '' || $password === '')) {
            throw new InvalidArgumentException('Az SMTP hitelesítési adatok nem lehetnek üresek.');
        }
        if ($username !== null && $encryption === 'none') {
            throw new InvalidArgumentException('SMTP hitelesítés titkosítatlan kapcsolaton nem engedélyezett.');
        }
        if ($production && ($username === null || !in_array($encryption, ['tls', 'ssl'], true))) {
            throw new InvalidArgumentException('Productionben hitelesített, TLS-védett SMTP transport kötelező.');
        }
        if ($timeoutSeconds < 1) {
            throw new InvalidArgumentException('Az SMTP timeoutnak pozitívnak kell lennie.');
        }
    }

    private function isValidHost(string $host): bool
    {
        if ($host === '' || trim($host) !== $host || preg_match('/[\r\n\s\/:?#@]/', $host) === 1) {
            return false;
        }

        return filter_var($host, FILTER_VALIDATE_IP) !== false
            || filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
    }
}
