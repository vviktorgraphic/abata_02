<?php /** @var array<string,mixed> $payload */ $e=static fn(mixed $v):string=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); use App\Presentation\HufFormatter; ?>
<?php /* --color-primary: #19194B; --color-accent: #F0A236; --color-background: #FFFFFF; */ ?>
<!doctype html>
<html lang="hu">
<head>
<meta charset="utf-8">
<title>Emlékeztető</title>
</head>
<body style="font-family:Arial,sans-serif;line-height:1.5;color:#19194B">
<main style="max-width:640px;margin:auto;padding:32px">
<h1>A Bata</h1>
<p>Tisztelt <?= $e($payload['contact_name']) ?>!</p>
<p>Bizonyára elkerülte a figyelmét, de foglalási igényével kapcsolatban korábban kiküldött díjbekérőnk alapján még nem érkezett meg a foglalás véglegesítéséhez szükséges előleg összege.</p>
<p>Kérem amennyiben fenntartja foglalási igényét, az előleget legkésőbb a mai napon legyen szíves átutalni bankszámlánkra.</p>
<p>
<strong>Utalás részletei:</strong>
<br>
<?= $e($payload['bank_name']) ?>
<br>
<?= $e($payload['beneficiary']) ?>
<br>
<?= $e($payload['bank_account']) ?>
<br>
<?= $e($payload['swift_bic']) ?>
</p>
<p>Előleg összege: <strong>
<?= $e(HufFormatter::format((string)$payload['advance_amount'])) ?>
</strong>
<br>Közlemény: <strong>
<?= $e($payload['payment_reference']) ?>
</strong>
</p>
<p>Amennyiben foglalását nem kívánja fenntartani, vagy a megadott határidőt elmulasztja, igényét a holnapi napon töröljük.</p>
<p>Üdvözlettel:<br>Petróczki-Oravecz Anikó<br>üzemeltető-tulajdonos<br>A Bata</p>
</main>
</body>
</html>
