<?php

declare(strict_types=1);

namespace App\Presentation;

final class ChildAgeSummaryFormatter
{
    /** @param list<int> $childAges */
    public static function format(int $childCount, array $childAges): string
    {
        if ($childAges === []) {
            return (string) $childCount;
        }

        $ages = array_map(static fn (int $age): string => (string) $age, $childAges);
        $lastAge = array_pop($ages);
        $ageList = $ages === [] ? $lastAge : implode(', ', $ages) . ' és ' . $lastAge;

        return $childCount . ' (' . $ageList . ' éves)';
    }
}
