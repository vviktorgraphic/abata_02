<?php

declare(strict_types=1);

namespace Tests\Unit\Presentation;

use App\Presentation\BookingHistoryNoteLabel;
use PHPUnit\Framework\TestCase;

final class BookingHistoryNoteLabelTest extends TestCase
{
    public function test_known_system_note_is_localized_for_presentation(): void
    {
        self::assertSame(
            'Foglalási igény létrehozva a publikus felületen',
            BookingHistoryNoteLabel::for('Public booking request created'),
        );
    }

    public function test_unknown_empty_and_null_notes_are_unchanged(): void
    {
        self::assertSame('<egyedi admin megjegyzés>', BookingHistoryNoteLabel::for('<egyedi admin megjegyzés>'));
        self::assertSame('', BookingHistoryNoteLabel::for(''));
        self::assertNull(BookingHistoryNoteLabel::for(null));
    }
}
