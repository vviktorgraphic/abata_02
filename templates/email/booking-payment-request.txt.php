<?php
use App\Presentation\HufFormatter;
/** @var \App\Application\Mail\BookingPaymentRequestMailData $data */
?>
Tisztelt <?= $data->contactName ?>!

Foglalási igényét rögzítettük!

<?php if ($data->customTotal !== null): ?>Kalkulált szállásdíj: <?= HufFormatter::format((string) $data->calculatedAccommodationFee) ?>
Egyedi végösszeg: <?= HufFormatter::format($data->customTotal) ?>
Fizetendő szállásdíj: <?= HufFormatter::format((string) $data->accommodationFee) ?>
<?php else: ?>Kalkulált szállásdíj: <?= HufFormatter::format((string) $data->accommodationFee) ?>
<?php endif ?>Az idegenforgalmi adó összege: <?= HufFormatter::format((string) $data->taxes) ?>, mely a szálláshelyen külön fizetendő
Előleg összege: <?= HufFormatter::format($data->advanceAmount) ?>

Kérjük az előleg összegét 5 napon belül az alábbi bankszámlára szíveskedjen átutalni:

<?= $data->bankName ?>
<?= $data->beneficiary ?>
<?= $data->bankAccount ?>
<?= $data->swiftBic ?>

Közlemény: <?= $data->paymentReference ?>

Az előleg beérkezését követően foglalásáról visszaigazolást küldünk.

Bármilyen felmerülő kérdés, kérés esetén kérem keressen az info@abata.hu címen, vagy hívjon a +3670 4326-001 telefonszámon!

Üdvözlettel:
Petróczki-Oravecz Anikó
tulajdonos-üzemeltető
A BATA
