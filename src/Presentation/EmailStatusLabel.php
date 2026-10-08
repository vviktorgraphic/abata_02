<?php
declare(strict_types=1);
namespace App\Presentation;
final class EmailStatusLabel
{
    public static function for(string $status): string { return ['pending'=>'Várakozik','processing'=>'Küldés folyamatban','sent'=>'Elküldve','failed'=>'Sikertelen'][$status] ?? $status; }
    public static function type(string $type): string { return [
        'booking_request_received'=>'Vendég értesítése','booking_request_admin_notification'=>'Admin értesítés új foglalásról',
        'booking_payment_request'=>'Díjbekérő','booking_payment_reminder'=>'Előleg emlékeztető',
        'booking_arrival_information'=>'Érkezési tájékoztató','booking_confirmed'=>'Foglalás visszaigazolása',
        'booking_rejected'=>'Elutasítás','booking_cancelled'=>'Törlés / lemondás','booking_review_request'=>'Értékeléskérés',
    ][$type] ?? $type; }
}
