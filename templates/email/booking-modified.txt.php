Tisztelt <?= $data->contactName ?>!

Foglalását kérésének megfelelően módosítottuk.

Érkezés:
<?= $data->arrivalDate ?>

Távozás:
<?= $data->departureDate ?>

Létszám:
<?= $data->adults ?> felnőtt, <?= count($data->childAges) ?> gyermek

Módosított szállásdíj:
<?= \App\Presentation\HufFormatter::format($data->accommodationFee) ?>

Idegenforgalmi adó:
<?= \App\Presentation\HufFormatter::format($data->tourismTax) ?>, mely a szálláshelyen külön fizetendő

Módosított végösszeg:
<?= \App\Presentation\HufFormatter::format($data->total) ?>

Korábban megfizetett előleg:
<?= $data->unchangedDepositAmount === null ? 'nem áll rendelkezésre' : \App\Presentation\HufFormatter::format($data->unchangedDepositAmount) ?>

A korábban megfizetett előleg összege a módosítás miatt nem változik.

Üdvözlettel:

Petróczki-Oravecz Anikó
tulajdonos-üzemeltető

A BATA
