<?php

declare(strict_types=1);

namespace App\Application\Booking;

use App\Domain\Booking\BookingCreateRequestValidator;
use App\Domain\Booking\BookingValidationFailed;
use App\Domain\Booking\GuestCapacityPolicy;
use DateTimeImmutable;
use DateTimeZone;

final readonly class ConfirmedBookingModificationValidator
{
    public function __construct(
        private DateTimeImmutable $today,
        private int $maximumNights = 30,
        private int $bookingHorizonDays = 365,
        private GuestCapacityPolicy $capacityPolicy = new GuestCapacityPolicy(),
    ) {
    }

    public static function forBudapestToday(): self
    {
        return new self(new DateTimeImmutable('today', new DateTimeZone('Europe/Budapest')));
    }

    /** @param array<string,mixed> $payload */
    public function validate(array $payload): ConfirmedBookingModification
    {
        $errors = [];
        $arrival = $this->date($payload['arrival_date'] ?? null, 'arrival_date', $errors);
        $departure = $this->date($payload['departure_date'] ?? null, 'departure_date', $errors);
        $adults = $this->integer($payload['adults'] ?? null);
        if ($adults === null || $adults < 1 || $adults > BookingCreateRequestValidator::MAX_CHARGEABLE_GUESTS) {
            $errors['adults'] = 'A felnőttek száma 1 és 4 között lehet.';
        }
        $children = $this->integer($payload['children'] ?? null);
        if ($children === null || $children < 0 || $children > BookingCreateRequestValidator::MAX_CHARGEABLE_GUESTS) {
            $errors['children'] = 'A gyermekek száma 0 és 4 között lehet.';
        }
        $ages = $payload['child_ages'] ?? [];
        if (!is_array($ages) || $children === null || count($ages) !== $children) {
            $errors['child_ages'] = 'Minden gyermekhez pontosan egy életkor szükséges.';
            $ages = [];
        } else {
            foreach ($ages as $age) {
                $value = $this->integer($age);
                if ($value === null || $value < 0 || $value > 17) {
                    $errors['child_ages'] = 'A gyermekek életkora 0 és 17 közötti egész szám lehet.';
                    break;
                }
            }
            $ages = array_map('intval', $ages);
        }
        if ($adults !== null && $children !== null && !isset($errors['adults'], $errors['children'], $errors['child_ages'])) {
            $violation = $this->capacityPolicy->violation($adults, array_values($ages));
            if ($violation !== null) $errors['guests'] = $violation;
        }
        if ($arrival !== null && $departure !== null) {
            if ($departure <= $arrival) {
                $errors['departure_date'] = 'A távozásnak az érkezés után kell lennie.';
            } else {
                $nights = (int) $arrival->diff($departure)->days;
                if ($nights > $this->maximumNights) {
                    $errors['departure_date'] = sprintf('Legfeljebb %d éjszaka adható meg.', $this->maximumNights);
                }
            }
            $horizon = $this->today->modify(sprintf('+%d days', $this->bookingHorizonDays));
            if ($arrival > $horizon) $errors['arrival_date'] = 'Az érkezés kívül esik a foglalási időhorizonton.';
            if ($departure > $horizon) $errors['departure_date'] = 'A távozás kívül esik a foglalási időhorizonton.';
        }
        if ($errors !== []) throw new BookingValidationFailed($errors);

        return new ConfirmedBookingModification(
            $arrival->format('Y-m-d'), $departure->format('Y-m-d'), (int) $adults, array_values($ages)
        );
    }

    /** @param array<string,string> $errors */
    private function date(mixed $value, string $field, array &$errors): ?DateTimeImmutable
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
            $errors[$field] = 'ISO YYYY-MM-DD formátumú dátum szükséges.';
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('Europe/Budapest'));
        $state = DateTimeImmutable::getLastErrors();
        if ($date === false || ($state !== false && ($state['warning_count'] > 0 || $state['error_count'] > 0)) || $date->format('Y-m-d') !== $value) {
            $errors[$field] = 'Érvényes naptári dátum szükséges.';
            return null;
        }
        return $date;
    }

    private function integer(mixed $value): ?int
    {
        if (is_int($value)) return $value;
        return is_string($value) && preg_match('/^-?\d+$/D', $value) === 1 ? (int) $value : null;
    }
}
