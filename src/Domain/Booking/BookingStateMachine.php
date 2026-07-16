<?php

declare(strict_types=1);

namespace App\Domain\Booking;

final readonly class BookingStateMachine
{
    public const STATE_PENDING = 'pending';
    public const STATE_CONFIRMED = 'confirmed';
    public const STATE_REJECTED = 'rejected';
    public const STATE_CANCELLED = 'cancelled';
    public const STATE_INVALIDATED = 'invalidated';

    /** @var array<string, list<string>> */
    private const TRANSITIONS = [
        self::STATE_PENDING => [self::STATE_CONFIRMED, self::STATE_REJECTED, self::STATE_INVALIDATED],
        self::STATE_CONFIRMED => [self::STATE_CANCELLED, self::STATE_INVALIDATED],
        self::STATE_REJECTED => [],
        self::STATE_CANCELLED => [],
        self::STATE_INVALIDATED => [],
    ];

    public function canTransition(BookingStatus|string $from, BookingStatus|string $to): bool
    {
        $from = $this->status($from);
        $to = $this->status($to);

        return in_array($to->value, self::TRANSITIONS[$from->value], true);
    }

    public function assertCanTransition(BookingStatus|string $from, BookingStatus|string $to): void
    {
        $from = $this->status($from);
        $to = $this->status($to);

        if (!$this->canTransition($from, $to)) {
            throw new BookingTransitionNotAllowed($from, $to);
        }
    }

    public function transition(BookingStatus|string $from, BookingStatus|string $to): BookingStatus
    {
        $target = $this->status($to);
        $this->assertCanTransition($from, $target);

        return $target;
    }

    private function status(BookingStatus|string $status): BookingStatus
    {
        return $status instanceof BookingStatus ? $status : BookingStatus::from($status);
    }
}
