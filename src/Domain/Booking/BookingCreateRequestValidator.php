<?php

declare(strict_types=1);

namespace App\Domain\Booking;

use DateTimeImmutable;
use DateTimeZone;

final readonly class BookingCreateRequestValidator
{
    public function __construct(
        private DateTimeImmutable $today,
        private int $minimumNights = 1,
        private int $maximumNights = 30,
        private int $bookingHorizonDays = 365,
        private int $maximumAdults = 6,
        private int $maximumChildren = 4,
        private int $maximumNotesLength = 2000,
    ) {
    }

    public static function forBudapestToday(int $minimumNights = 1, int $maximumNights = 30, int $bookingHorizonDays = 365): self
    {
        return new self(new DateTimeImmutable('today', new DateTimeZone('Europe/Budapest')), $minimumNights, $maximumNights, $bookingHorizonDays);
    }

    /** @param array<string, mixed> $payload */
    public function validate(array $payload): BookingCreateRequest
    {
        $errors = [];
        $arrival = $this->date($payload['arrival_date'] ?? null, 'arrival_date', $errors);
        $departure = $this->date($payload['departure_date'] ?? null, 'departure_date', $errors);

        $name = trim((string) ($payload['contact_name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 190) {
            $errors['contact_name'] = 'A kapcsolattartó neve kötelező, és legfeljebb 190 karakter lehet.';
        }

        $email = mb_strtolower(trim((string) ($payload['email'] ?? '')));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 190) {
            $errors['email'] = 'Érvényes e-mail-cím szükséges.';
        }

        $phone = $this->normalizePhone((string) ($payload['phone'] ?? ''));
        if ($phone === null) {
            $errors['phone'] = 'Érvényes telefonszám szükséges.';
        }

        $adults = $this->integer($payload['adults'] ?? null);
        if ($adults === null || $adults < 1 || $adults > $this->maximumAdults) {
            $errors['adults'] = sprintf('A felnőttek száma 1 és %d között lehet.', $this->maximumAdults);
        }

        $children = $this->integer($payload['children'] ?? null);
        if ($children === null || $children < 0 || $children > $this->maximumChildren) {
            $errors['children'] = sprintf('A gyermekek száma 0 és %d között lehet.', $this->maximumChildren);
        }

        $childAges = $payload['child_ages'] ?? [];
        if (!is_array($childAges) || $children === null || count($childAges) !== $children) {
            $errors['child_ages'] = 'Minden gyermekhez pontosan egy életkor szükséges.';
            $childAges = [];
        } else {
            foreach ($childAges as $age) {
                $parsedAge = $this->integer($age);
                if ($parsedAge === null || $parsedAge < 0 || $parsedAge > 17) {
                    $errors['child_ages'] = 'A gyermekek életkora 0 és 17 közötti egész szám lehet.';
                    break;
                }
            }
            $childAges = array_map(static fn (mixed $age): int => (int) $age, $childAges);
        }

        $notes = trim((string) ($payload['notes'] ?? ''));
        if (mb_strlen($notes) > $this->maximumNotesLength) {
            $errors['notes'] = sprintf('A megjegyzés legfeljebb %d karakter lehet.', $this->maximumNotesLength);
        }

        $privacyAccepted = ($payload['privacy_accepted'] ?? null) === true;
        if (!$privacyAccepted) {
            $errors['privacy_accepted'] = 'Az adatkezelési tájékoztató elfogadása kötelező.';
        }

        $idempotencyKey = trim((string) ($payload['idempotency_key'] ?? ''));
        if (preg_match('/^[A-Za-z0-9._:-]{16,128}$/D', $idempotencyKey) !== 1) {
            $errors['idempotency_key'] = 'Érvényes idempotenciakulcs szükséges.';
        }

        if (trim((string) ($payload['website'] ?? '')) !== '') {
            $errors['website'] = 'A kérés nem fogadható el.';
        }

        if ($arrival !== null && $departure !== null) {
            if ($departure <= $arrival) {
                $errors['departure_date'] = 'A távozásnak az érkezés után kell lennie.';
            } else {
                $nights = (int) $arrival->diff($departure)->days;
                if ($nights < $this->minimumNights || $nights > $this->maximumNights) {
                    $errors['departure_date'] = sprintf('%d és %d közötti éjszakaszám engedélyezett.', $this->minimumNights, $this->maximumNights);
                }
            }
            if ($arrival < $this->today) {
                $errors['arrival_date'] = 'Múltbeli érkezés nem választható.';
            }
            $horizon = $this->today->modify(sprintf('+%d days', $this->bookingHorizonDays));
            if ($arrival > $horizon) {
                $errors['arrival_date'] = 'Az érkezés kívül esik a foglalási időhorizonton.';
            }
            if ($departure > $horizon) {
                $errors['departure_date'] = 'A távozás kívül esik a foglalási időhorizonton.';
            }
        }

        if ($errors !== []) {
            throw new BookingValidationFailed($errors);
        }

        return new BookingCreateRequest(
            new BookingPeriod($arrival, $departure), $name, $email, $phone,
            $adults, $children, array_values($childAges), $notes, $privacyAccepted, $idempotencyKey,
        );
    }

    /** @param array<string, string> $errors */
    private function date(mixed $value, string $field, array &$errors): ?DateTimeImmutable
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
            $errors[$field] = 'ISO YYYY-MM-DD formátumú dátum szükséges.';
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('Europe/Budapest'));
        $dateErrors = DateTimeImmutable::getLastErrors();
        if ($date === false || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0)) || $date->format('Y-m-d') !== $value) {
            $errors[$field] = 'Érvényes naptári dátum szükséges.';
            return null;
        }
        return $date;
    }

    private function integer(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?\d+$/D', $value) === 1) {
            return (int) $value;
        }
        return null;
    }

    private function normalizePhone(string $phone): ?string
    {
        $phone = trim($phone);
        if ($phone === '' || preg_match('/[^0-9+() .\/-]/', $phone) === 1) {
            return null;
        }
        $hasPlus = str_starts_with($phone, '+');
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (strlen($digits) < 7 || strlen($digits) > 15) {
            return null;
        }
        return ($hasPlus ? '+' : '') . $digits;
    }
}
