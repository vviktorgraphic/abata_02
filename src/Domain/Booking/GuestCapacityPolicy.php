<?php
declare(strict_types=1);
namespace App\Domain\Booking;

final readonly class GuestCapacityPolicy
{
    public const MAX_PHYSICAL_GUESTS = 5;
    public const MAX_CHARGEABLE_GUESTS = 4;
    public const MIN_CHARGEABLE_CHILD_AGE = 4;

    /** @param list<int> $childAges */
    public function violation(int $adults, array $childAges): ?string
    {
        if ($adults + count($childAges) > self::MAX_PHYSICAL_GUESTS) {
            return 'A szállás legfeljebb 5 vendéget fogad, a gyermekeket is beleszámítva.';
        }
        $chargeableChildren = count(array_filter(
            $childAges,
            static fn (int $age): bool => $age >= self::MIN_CHARGEABLE_CHILD_AGE,
        ));
        if ($adults + $chargeableChildren > self::MAX_CHARGEABLE_GUESTS) {
            return 'Legfeljebb 4 fizető vendég foglalható; a 4 éves vagy idősebb gyermekek beleszámítanak.';
        }
        return null;
    }
}
