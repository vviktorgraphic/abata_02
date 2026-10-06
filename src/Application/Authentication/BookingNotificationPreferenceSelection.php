<?php

declare(strict_types=1);

namespace App\Application\Authentication;

final class BookingNotificationPreferenceSelection
{
    /** @return list<int> */
    public static function fromForm(mixed $value): array
    {
        if ($value === null) {
            return [];
        }
        if (!is_array($value)) {
            throw new \InvalidArgumentException('Az értesítési kijelölés formátuma érvénytelen.');
        }

        $ids = [];
        foreach ($value as $id) {
            if (is_int($id) && $id > 0) {
                $ids[$id] = $id;
                continue;
            }
            if (!is_string($id) || preg_match('/\A[1-9][0-9]*\z/', $id) !== 1) {
                throw new \InvalidArgumentException('Az értesítési kijelölés érvénytelen felhasználót tartalmaz.');
            }
            $validated = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($validated === false) {
                throw new \InvalidArgumentException('Az értesítési kijelölés érvénytelen felhasználót tartalmaz.');
            }
            $ids[(int) $validated] = (int) $validated;
        }

        return array_values($ids);
    }
}
