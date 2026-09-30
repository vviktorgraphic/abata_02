A Bata – foglalás lemondva

Foglalási referencia: <?= $data->reference ?>
Érkezés: <?= $data->arrivalDate ?>
Távozás: <?= $data->departureDate ?>

Foglalását lemondtuk.
<?php if ($data->cancellationHasPenalty()): ?>
Kalkulált szállásdíj: <?= \App\Presentation\HufFormatter::format((string) $data->cancellationAccommodationFee) ?>
Lemondási kötbér: <?= \App\Presentation\HufFormatter::format((string) $data->cancellationPenaltyAmount) ?>
A kötbér összege tájékoztató jellegű; automatikus terhelés nem történt.
<?php else: ?>
A lemondás kötbérmentes.
<?php endif; ?>
