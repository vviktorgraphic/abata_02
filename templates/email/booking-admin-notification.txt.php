<?php
/** @var \App\Application\Mail\BookingRequestMailData $data */
$ages = $data->childAges === [] ? 'nincs' : implode(', ', $data->childAges) . ' év';
$notes = $data->notes === null || trim($data->notes) === '' ? 'Nincs megjegyzés.' : $data->notes;
?>Új foglalási igény érkezett

Referencia: <?= $data->reference ?>
Állapot: pending
Név: <?= $data->contactName ?>
E-mail: <?= $data->guestEmail !== '' ? $data->guestEmail : $data->recipient ?>
Telefon: <?= $data->phone ?>
Érkezés: <?= $data->arrivalDate ?>
Távozás: <?= $data->departureDate ?>
Éjszakák: <?= $data->nights() ?>
Felnőttek: <?= $data->adults ?>
Gyermekek: <?= count($data->childAges) ?> (életkorok: <?= $ages ?>)
Megjegyzés: <?= $notes ?>
Végösszeg: <?= \App\Presentation\HufFormatter::format($data->totalAmount) ?> <?= $data->currency ?>

Foglalás megnyitása: <?= $data->adminUrl ?>
