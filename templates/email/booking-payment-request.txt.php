<?php
use App\Presentation\HufFormatter;
/** @var \App\Application\Mail\BookingPaymentRequestMailData $data */
?>
Kedves <?= $data->contactName ?>!

Köszönjük foglalási igényét az A Bata szálláshelyre.

A foglalás véglegesítéséhez kérjük, utalja át a foglalás teljes összegének <?= $data->advancePercent ?>%-át az alábbi bankszámlára.

Foglalási azonosító: <?= $data->reference ?>

Érkezés: <?= $data->arrivalDate ?>

Távozás: <?= $data->departureDate ?>

Foglalás teljes összege: <?= HufFormatter::format($data->total) ?>

Fizetendő előleg (<?= $data->advancePercent ?>%): <?= HufFormatter::format($data->advanceAmount) ?>

Kedvezményezett: <?= $data->beneficiary ?>

Bankszámlaszám: <?= $data->bankAccount ?>

Közlemény: <?= $data->reference ?>

Kérjük, hogy az átutalás közlemény rovatában feltétlenül tüntesse fel a foglalási azonosítót.

A foglalás az előleg jóváírását és az ezt követő visszaigazolásunkat követően válik véglegessé.

Köszönjük!

A Bata
