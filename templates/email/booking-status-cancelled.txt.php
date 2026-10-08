Tisztelt <?= $data->contactName ?>!

Ezúton tájékoztatjuk, hogy foglalási igényét töröltük.
<?php if ($data->adminNote !== null && trim($data->adminNote) !== ''): ?>

Indoklás:
<?= $data->adminNote ?>
<?php endif; ?>
<?php if ($data->cancellationHasPenalty()): ?>
Kalkulált szállásdíj: <?= \App\Presentation\HufFormatter::format((string) $data->cancellationAccommodationFee) ?>

Lemondási kötbér: <?= \App\Presentation\HufFormatter::format((string) $data->cancellationPenaltyAmount) ?>

A kötbér összege tájékoztató jellegű; automatikus terhelés nem történt.
<?php else: ?>
A lemondás kötbérmentes.
<?php endif; ?>

Üdvözlettel:
Petróczki-Oravecz Anikó
tulajdonos-üzemeltető
A BATA
