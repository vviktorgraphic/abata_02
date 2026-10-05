<?php
use App\Presentation\HufFormatter;
/** @var \App\Application\Mail\BookingPaymentRequestMailData $data */
$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!doctype html>
<html lang="hu"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>A Bata – előlegfizetés</title></head>
<body>
<h1>A Bata</h1>
<p>Kedves <?= $escape($data->contactName) ?>!</p>
<p>Köszönjük foglalási igényét az A Bata szálláshelyre.</p>
<p>A foglalás véglegesítéséhez kérjük, utalja át a foglalás teljes összegének <?= $data->advancePercent ?>%-át az alábbi bankszámlára.</p>
<p>Foglalási azonosító: <strong><?= $escape($data->reference) ?></strong><br>
Érkezés: <?= $escape($data->arrivalDate) ?><br>
Távozás: <?= $escape($data->departureDate) ?></p>
<p>Foglalás teljes összege: <?= HufFormatter::format($data->total) ?><br>
<strong>Fizetendő előleg (<?= $data->advancePercent ?>%): <?= HufFormatter::format($data->advanceAmount) ?></strong></p>
<p>Kedvezményezett: <?= $escape($data->beneficiary) ?><br>
Bankszámlaszám: <?= $escape($data->bankAccount) ?><br>
<strong>Közlemény: <?= $escape($data->reference) ?></strong></p>
<p>Kérjük, hogy az átutalás közlemény rovatában feltétlenül tüntesse fel a foglalási azonosítót.</p>
<p>A foglalás az előleg jóváírását és az ezt követő visszaigazolásunkat követően válik véglegessé.</p>
<p>Köszönjük!<br>A Bata</p>
</body></html>
