<?php

declare(strict_types=1);

namespace App\Application\Mail;

final readonly class InlineAttachment
{
    public function __construct(
        public string $contentId,
        public string $filename,
        public string $mimeType,
        public string $bytes,
    ) {
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,127}\z/', $contentId) !== 1) {
            throw new \InvalidArgumentException('Az inline kép CID értéke érvénytelen.');
        }
        if (basename($filename) !== $filename || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,127}\z/', $filename) !== 1) {
            throw new \InvalidArgumentException('Az inline kép fájlneve érvénytelen.');
        }
        if ($mimeType !== 'image/jpeg') {
            throw new \InvalidArgumentException('Csak JPEG inline kép engedélyezett.');
        }
        if ($bytes === '' || !str_starts_with($bytes, "\xFF\xD8")) {
            throw new \InvalidArgumentException('Az inline kép nem érvényes JPEG adat.');
        }
    }
}
