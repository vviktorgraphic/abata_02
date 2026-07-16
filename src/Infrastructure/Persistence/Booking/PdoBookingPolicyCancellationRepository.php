<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Booking;

use App\Application\Booking\BookingPolicyCancellationRepository;
use PDO;

final class PdoBookingPolicyCancellationRepository implements BookingPolicyCancellationRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function savePolicyAcceptance(
        int $bookingId,
        string $acceptedAt,
        string $version,
        string $url,
    ): bool {
        $statement = $this->pdo->prepare(
            'UPDATE bookings
             SET booking_policy_accepted_at = :accepted_at,
                 booking_policy_version = :version,
                 booking_policy_url = :url
             WHERE id = :id AND booking_policy_accepted_at IS NULL'
        );
        $statement->execute([
            'accepted_at' => $acceptedAt,
            'version' => $version,
            'url' => $url,
            'id' => $bookingId,
        ]);

        return $statement->rowCount() === 1;
    }

    public function saveCancellationSnapshot(
        int $bookingId,
        string $cancelledAt,
        string $penaltyRate,
        string $penaltyAmount,
        string $currency,
        int $ruleVersion,
        array $snapshot,
    ): bool {
        $statement = $this->pdo->prepare(
            'UPDATE bookings
             SET cancelled_at = :cancelled_at,
                 cancellation_penalty_rate = :penalty_rate,
                 cancellation_penalty_amount = :penalty_amount,
                 cancellation_currency = :currency,
                 cancellation_rule_version = :rule_version,
                 cancellation_calculation_snapshot = :snapshot
             WHERE id = :id AND cancelled_at IS NULL'
        );
        $statement->execute([
            'cancelled_at' => $cancelledAt,
            'penalty_rate' => $penaltyRate,
            'penalty_amount' => $penaltyAmount,
            'currency' => $currency,
            'rule_version' => $ruleVersion,
            'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'id' => $bookingId,
        ]);

        return $statement->rowCount() === 1;
    }
}
