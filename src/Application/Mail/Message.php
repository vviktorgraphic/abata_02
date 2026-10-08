<?php

declare(strict_types=1);

namespace App\Application\Mail;

use InvalidArgumentException;

final readonly class Message
{
    public function __construct(
        public string $from,
        public string $to,
        public string $subject,
        public string $textBody,
        public string $htmlBody,
        public array $inlineAttachments = [],
        public ?string $fromName = null,
        public ?string $replyToEmail = null,
        public ?string $replyToName = null,
    ) {
        $this->assertEmail($from, 'Feladó');
        $this->assertEmail($to, 'Címzett');
        $this->assertDisplayName($fromName, 'Feladó');

        if ($replyToEmail !== null) {
            $this->assertEmail($replyToEmail, 'Reply-To');
        }
        $this->assertDisplayName($replyToName, 'Reply-To');
        if ($replyToEmail === null && $replyToName !== null) {
            throw new InvalidArgumentException('Reply-To név csak Reply-To e-mail-címmel adható meg.');
        }

        if ($subject === '' || str_contains($subject, "\r") || str_contains($subject, "\n")) {
            throw new InvalidArgumentException('A levél tárgya nem lehet üres és nem tartalmazhat sortörést.');
        }

        if ($textBody === '' || $htmlBody === '') {
            throw new InvalidArgumentException('A levél szöveges és HTML törzse is kötelező.');
        }

        $contentIds = [];
        foreach ($inlineAttachments as $attachment) {
            if (!$attachment instanceof InlineAttachment) {
                throw new InvalidArgumentException('Az inline mellékletek típusa érvénytelen.');
            }
            if (isset($contentIds[$attachment->contentId])) {
                throw new InvalidArgumentException('Az inline kép CID értéke nem lehet ismétlődő.');
            }
            $contentIds[$attachment->contentId] = true;
        }
    }

    private function assertEmail(string $email, string $field): void
    {
        if (str_contains($email, "\r") || str_contains($email, "\n") || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException($field . ' e-mail-címe érvénytelen.');
        }
    }

    private function assertDisplayName(?string $name, string $field): void
    {
        if ($name === null) {
            return;
        }
        if ($name === '' || trim($name) !== $name || str_contains($name, "\r") || str_contains($name, "\n") || mb_strlen($name) > 120) {
            throw new InvalidArgumentException($field . ' megjelenítési neve érvénytelen.');
        }
    }
}
