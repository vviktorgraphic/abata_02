<?php
/** @var \App\Application\Mail\BookingRequestMailData $data */
$ages = $data->childAges === [] ? 'nincs' : implode(', ', $data->childAges) . ' év';
?>A Bata – foglalási igény

Köszönjük, hogy elküldte foglalási igényét.

Azonosító: <?= $data->reference ?>
Érkezés: <?= $data->arrivalDate ?>
Távozás: <?= $data->departureDate ?>
Éjszakák: <?= $data->nights() ?>
Felnőttek: <?= $data->adults ?>
Gyermekek: <?= count($data->childAges) ?> (életkorok: <?= $ages ?>)
Végösszeg: <?= $data->totalAmount ?> <?= $data->currency ?>

Ez még csak foglalási igény, nem visszaigazolt foglalás. A foglalás az admin jóváhagyása után válik véglegessé.
