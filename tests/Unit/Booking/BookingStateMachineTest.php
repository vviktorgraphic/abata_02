<?php

declare(strict_types=1);

namespace Tests\Unit\Booking;

use App\Domain\Booking\AdminNote;
use App\Domain\Booking\BookingStateMachine;
use App\Domain\Booking\BookingStatus;
use App\Domain\Booking\BookingTransitionNotAllowed;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BookingStateMachineTest extends TestCase
{
    #[DataProvider('allowedTransitions')]
    public function testAllowsOnlySpecifiedTransitions(BookingStatus $from, BookingStatus $to): void
    {
        $machine = new BookingStateMachine();

        self::assertTrue($machine->canTransition($from, $to));
        self::assertSame($to, $machine->transition($from, $to));
    }

    /** @return iterable<string, array{BookingStatus, BookingStatus}> */
    public static function allowedTransitions(): iterable
    {
        yield 'confirm pending' => [BookingStatus::Pending, BookingStatus::Confirmed];
        yield 'reject pending' => [BookingStatus::Pending, BookingStatus::Rejected];
        yield 'invalidate pending' => [BookingStatus::Pending, BookingStatus::Invalidated];
        yield 'cancel confirmed' => [BookingStatus::Confirmed, BookingStatus::Cancelled];
        yield 'invalidate confirmed' => [BookingStatus::Confirmed, BookingStatus::Invalidated];
    }

    #[DataProvider('forbiddenTransitions')]
    public function testRejectsForbiddenTransitions(BookingStatus $from, BookingStatus $to): void
    {
        $machine = new BookingStateMachine();

        self::assertFalse($machine->canTransition($from, $to));
        $this->expectException(BookingTransitionNotAllowed::class);
        $machine->assertCanTransition($from, $to);
    }

    /** @return iterable<string, array{BookingStatus, BookingStatus}> */
    public static function forbiddenTransitions(): iterable
    {
        yield 'rejected is final' => [BookingStatus::Rejected, BookingStatus::Confirmed];
        yield 'cancelled is final' => [BookingStatus::Cancelled, BookingStatus::Confirmed];
        yield 'invalidated is final' => [BookingStatus::Invalidated, BookingStatus::Confirmed];
        yield 'pending cannot be cancelled' => [BookingStatus::Pending, BookingStatus::Cancelled];
        yield 'same state is not a transition' => [BookingStatus::Pending, BookingStatus::Pending];
        yield 'confirmed cannot be rejected' => [BookingStatus::Confirmed, BookingStatus::Rejected];
    }

    public function testTransitionErrorExposesSafeStateContext(): void
    {
        try {
            (new BookingStateMachine())->assertCanTransition(BookingStatus::Cancelled, BookingStatus::Confirmed);
            self::fail('The forbidden transition should fail.');
        } catch (BookingTransitionNotAllowed $exception) {
            self::assertSame(BookingStatus::Cancelled, $exception->from);
            self::assertSame(BookingStatus::Confirmed, $exception->to);
            self::assertSame('Cannot transition booking from "cancelled" to "confirmed".', $exception->getMessage());
        }
    }

    public function testAdminNoteAcceptsNullAndExactlyTheMaximumLength(): void
    {
        self::assertNull((new AdminNote(null))->value);
        self::assertSame(str_repeat('á', 500), (new AdminNote(str_repeat('á', 500)))->value);
    }

    public function testAdminNoteRejectsMoreThanFiveHundredCharacters(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new AdminNote(str_repeat('á', 501));
    }

    public function testStatusLabelsCoverEveryPersistedState(): void
    {
        self::assertSame([
            'pending' => 'Függőben',
            'confirmed' => 'Megerősítve',
            'rejected' => 'Elutasítva',
            'cancelled' => 'Lemondva',
            'invalidated' => 'Érvénytelenítve',
        ], array_combine(
            array_map(static fn (BookingStatus $status): string => $status->value, BookingStatus::cases()),
            array_map(static fn (BookingStatus $status): string => $status->label(), BookingStatus::cases()),
        ));
    }
}
