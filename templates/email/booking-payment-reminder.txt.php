<?php /** @var array<string,mixed> $payload */ use App\Presentation\HufFormatter; ?>
Tisztelt <?= $payload['contact_name'] ?>!

Bizonyára elkerülte a figyelmét, de foglalási igényével kapcsolatban korábban kiküldött díjbekérőnk alapján még nem érkezett meg a foglalás véglegesítéséhez szükséges előleg összege.

Kérem amennyiben fenntartja foglalási igényét, az előleget legkésőbb a mai napon legyen szíves átutalni bankszámlánkra.

Utalás részletei:

<?= $payload['bank_name'] ?>
<?= $payload['beneficiary'] ?>
<?= $payload['bank_account'] ?>
<?= $payload['swift_bic'] ?>

Előleg összege: <?= HufFormatter::format((string)$payload['advance_amount']) ?>
Közlemény: <?= $payload['payment_reference'] ?>

Amennyiben foglalását nem kívánja fenntartani, vagy a megadott határidőt elmulasztja, igényét a holnapi napon töröljük.

Üdvözlettel:
Petróczki-Oravecz Anikó
üzemeltető-tulajdonos
A Bata
