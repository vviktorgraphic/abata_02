<?php
/** @var \App\Application\Mail\BookingRequestMailData $data */
$e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$ages = $data->childAges === [] ? 'nincs' : implode(', ', $data->childAges) . ' év';
?><!doctype html>
<html lang="hu"><head><meta charset="utf-8"><title>A Bata – foglalási igény</title><style>:root { --color-primary: #19194B; --color-accent: #F0A236; --color-background: #FFFFFF; }</style></head>
<body style="margin:0;background:#FFFFFF;color:#19194B;font-family:Arial,sans-serif">
<main style="max-width:640px;margin:auto;padding:32px;border-top:8px solid #F0A236">
<h1 style="color:#19194B">A Bata</h1><p>Köszönjük, hogy elküldte foglalási igényét.</p>
<table role="presentation" style="border-collapse:collapse">
<tr><th align="left">Azonosító</th><td><?= $e($data->reference) ?></td></tr>
<tr><th align="left">Érkezés</th><td><?= $e($data->arrivalDate) ?></td></tr>
<tr><th align="left">Távozás</th><td><?= $e($data->departureDate) ?></td></tr>
<tr><th align="left">Éjszakák</th><td><?= $data->nights() ?></td></tr>
<tr><th align="left">Felnőttek</th><td><?= $data->adults ?></td></tr>
<tr><th align="left">Gyermekek</th><td><?= count($data->childAges) ?> (életkorok: <?= $e($ages) ?>)</td></tr>
<tr><th align="left">Végösszeg</th><td><strong><?= $e($data->totalAmount) ?> <?= $e($data->currency) ?></strong></td></tr>
</table>
<p style="padding:16px;background:#F0A236;color:#19194B"><strong>Ez még csak foglalási igény, nem visszaigazolt foglalás.</strong> A foglalás az admin jóváhagyása után válik véglegessé.</p>
</main></body></html>
