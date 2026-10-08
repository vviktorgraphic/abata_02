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
    ) {
        $this->assertEmail($from, 'Feladó');
        $this->assertEmail($to, 'Címzett');

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
}
