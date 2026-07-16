A Bata – foglalás lemondva

Foglalási referencia: <?= $data->reference ?>
Érkezés: <?= $data->arrivalDate ?>
Távozás: <?= $data->departureDate ?>

Foglalását lemondtuk.
<?php if ($data->cancellationHasPenalty()): ?>
Kalkulált szállásdíj: <?= $data->cancellationAccommodationFee ?> HUF
Lemondási kötbér: <?= $data->cancellationPenaltyAmount ?> HUF
A kötbér összege tájékoztató jellegű; automatikus terhelés nem történt.
<?php else: ?>
A lemondás kötbérmentes.
<?php endif; ?>
