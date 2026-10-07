<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Pricing\PricingConfigurationException;
use App\Application\Pricing\PricingPreviewer;
use App\Domain\Pricing\PricingInput;
use App\Http\JsonResponse;
use App\Presentation\HufFormatter;
use JsonException;

/** Read-only public quote boundary. It deliberately delegates to the shared pricing engine. */
final readonly class PricingQuoteController
{
    public function __construct(private PricingPreviewer $pricing)
    {
    }

    /** @param array<string,mixed> $payload */
    public function quote(array $payload): void
    {
        $ages = $payload['child_ages'] ?? [];
        $errors = [];
        if (!is_string($payload['arrival_date'] ?? null) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $payload['arrival_date'])) {
            $errors['arrival_date'] = 'Érvényes érkezési dátum szükséges.';
        }
        if (!is_string($payload['departure_date'] ?? null) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $payload['departure_date'])) {
            $errors['departure_date'] = 'Érvényes távozási dátum szükséges.';
        }
        if (!is_int($payload['adults'] ?? null) || $payload['adults'] < 1) {
            $errors['adults'] = 'Legalább egy felnőtt szükséges.';
        }
        if (!is_array($ages) || array_is_list($ages) === false || array_filter($ages, static fn (mixed $age): bool => !is_int($age) || $age < 0 || $age > 17) !== []) {
            $errors['child_ages'] = 'A gyermekéletkorok 0 és 17 év közötti egész számok legyenek.';
        }
        if (is_int($payload['adults'] ?? null) && is_array($ages)) {
            $physical = $payload['adults'] + count($ages);
            $chargeable = $payload['adults'] + count(array_filter($ages, static fn (mixed $age): bool => is_int($age) && $age >= 4));
            if ($physical > 5) {
                $errors['guests'] = 'A szállás legfeljebb 5 vendéget fogad.';
            } elseif ($chargeable > 4) {
                $errors['guests'] = 'Az árazási létszám legfeljebb 4 fő lehet.';
            }
        }
        if ($errors !== []) {
            JsonResponse::send(['error' => 'A megadott adatok hibásak.', 'errors' => $errors], 422);
            return;
        }

        try {
            $result = $this->pricing->preview(new PricingInput(
                $payload['arrival_date'],
                $payload['departure_date'],
                $payload['adults'],
                $ages,
            ));
            $snapshot = $result->snapshot;
            $surcharge = $snapshot['one_night_surcharge'] ?? $snapshot['one_night_surcharge_amount'] ?? '0.00';
            JsonResponse::send([
                'currency' => $result->currency,
                'nights' => (int) ($snapshot['nights'] ?? 0),
                'physical_guests' => $payload['adults'] + count($ages),
                'chargeable_guests' => $payload['adults'] + count(array_filter($ages, static fn (int $age): bool => $age >= 4)),
                'accommodation_fee' => $result->accommodationFee,
                'public_accommodation_total' => (string) (
                    (int) HufFormatter::input($result->totalAmount)
                    - (int) HufFormatter::input($result->tourismTax)
                ) . '.00',
                'one_night_surcharge' => is_string($surcharge) ? $surcharge : number_format((float) $surcharge, 2, '.', ''),
                'taxes' => $result->tourismTax,
                'total' => $result->totalAmount,
                'nightly_breakdown' => $snapshot['nightly_breakdown'] ?? [],
            ]);
        } catch (PricingConfigurationException|\InvalidArgumentException $error) {
            JsonResponse::send(['error' => 'Erre a létszámra és tartózkodási időre jelenleg nincs ár beállítva.'], 503);
        } catch (\Throwable) {
            JsonResponse::send(['error' => 'Az ár jelenleg nem számítható ki.'], 503);
        }
    }

    public function fromJson(string $body): void
    {
        try {
            $payload = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            JsonResponse::send(['error' => 'Érvénytelen JSON kérés.'], 400);
            return;
        }
        $this->quote(is_array($payload) && array_is_list($payload) === false ? $payload : []);
    }
}
