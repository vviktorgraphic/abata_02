<?php

declare(strict_types=1);

namespace App\Application\Mail;

interface BookingNotificationRecipientProvider
{
    /** @return list<string> */
    public function bookingNotificationRecipients(): array;
}
