A Bata – foglalás megerősítve

Foglalási referencia: <?= $data->reference ?>
Érkezés: <?= $data->arrivalDate ?>
Távozás: <?= $data->departureDate ?>
Éjszakák: <?= $data->nights() ?>
Létszám: <?= $data->adults ?> felnőtt, <?= $data->children ?> gyermek
Végösszeg: <?= \App\Presentation\HufFormatter::format($data->totalAmount) ?>

Foglalását megerősítettük.
