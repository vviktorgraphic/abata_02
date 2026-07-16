<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Availability\GetAvailabilityHandler;
use App\Application\Availability\InvalidAvailabilityRange;
use App\Http\JsonResponse;

final readonly class BookingValidationController
{
    public function __construct(private GetAvailabilityHandler $availability)
    {
    }

    /** @param array<string, mixed> $input */
    public function validate(array $input): void
    {
        $errors = [];
        foreach (['name', 'email', 'phone', 'arrival_date', 'departure_date'] as $field) {
            if (!is_string($input[$field] ?? null) || trim($input[$field]) === '') {
                $errors[$field] = 'A mező kitöltése kötelező.';
            }
        }
        if (is_string($input['email'] ?? null) && filter_var($input['email'], FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'Érvényes e-mail-cím szükséges.';
        }
        if (($input['privacy'] ?? false) !== true) {
            $errors['privacy'] = 'Az adatkezelési hozzájárulás kötelező.';
        }

        if ($errors !== []) {
            JsonResponse::send(['valid' => false, 'errors' => $errors], 422);
            return;
        }

        try {
            $document = $this->availability->handle($input['arrival_date'], $input['departure_date']);
            $blocked = array_filter(
                $document['days'],
                static fn (array $day): bool => in_array($day['status'], ['occupied', 'arrival_only', 'turnover', 'blocked', 'past'], true),
            );
            $nights = count($document['days']);
            $rules = $document['rules'];
            $today = new \DateTimeImmutable('today', new \DateTimeZone('Europe/Budapest'));
            $departure = new \DateTimeImmutable($input['departure_date'] . ' 00:00:00', new \DateTimeZone('Europe/Budapest'));
            $outsideHorizon = $departure > $today->modify(sprintf('+%d days', $rules['booking_horizon_days']));

            if ($blocked !== [] || $outsideHorizon || $nights < $rules['minimum_nights'] || $nights > $rules['maximum_nights']) {
                JsonResponse::send(['valid' => false, 'errors' => ['dates' => 'A kiválasztott időszak nem foglalható.']], 422);
                return;
            }

            JsonResponse::send([
                'valid' => true,
                'submission_enabled' => false,
                'message' => 'Az adatok érvényesek, de a foglalásküldés a következő sprintben készül el. Foglalás nem történt.',
            ]);
        } catch (InvalidAvailabilityRange $exception) {
            JsonResponse::send(['valid' => false, 'errors' => ['dates' => $exception->getMessage()]], 422);
        } catch (\Throwable) {
            JsonResponse::send(['valid' => false, 'error' => 'Az ellenőrzés átmenetileg nem érhető el.'], 500);
        }
    }
}
