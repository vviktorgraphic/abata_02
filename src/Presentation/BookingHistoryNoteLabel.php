<?php

declare(strict_types=1);

namespace App\Presentation;

final class BookingHistoryNoteLabel
{
    public static function for(?string $note): ?string
    {
        return match ($note) {
            'Public booking request created' => 'Foglalási igény létrehozva a publikus felületen',
            default => $note,
        };
    }
}
