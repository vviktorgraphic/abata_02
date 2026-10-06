<?php

declare(strict_types=1);

namespace App\Application\Authentication;

use App\Application\Mail\BookingNotificationRecipientProvider;

interface AdminUserRepository extends AdminCredentialRepository, BookingNotificationRecipientProvider
{
    /** @return list<array{id:int,name:string,email:string,is_active:bool,receives_booking_notifications:bool,created_at:string}> */
    public function allForManagement(): array;
    public function createAdmin(string $name, string $email, string $passwordHash, bool $receivesBookingNotifications = false): int;
    public function setActive(int $adminId, bool $active): bool;
    public function setActiveSafely(int $adminId, bool $active): bool;
    public function countActive(): int;
}
