<?php

declare(strict_types=1);

namespace App\Application\Authentication;

interface AdminBookingNotificationPreferenceRepository
{
    /**
     * @param list<int> $selectedAdminIds
     * @return list<array{id:int,old:bool,new:bool}>
     */
    public function replaceBookingNotificationRecipients(array $selectedAdminIds): array;
}
