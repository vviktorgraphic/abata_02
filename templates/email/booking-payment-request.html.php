<?php
use App\Presentation\HufFormatter;
/** @var \App\Application\Mail\BookingPaymentRequestMailData $data */
$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!doctype html>
<html lang="hu"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>A Bata – előlegfizetés</title>
<style>
:root {
    --color-primary: #19194B;
    --color-accent: #F0A236;
    --color-background: #FFFFFF;
}
body { color: var(--color-primary); background: var(--color-background); }
h1 { border-bottom: 3px solid var(--color-accent); }
</style></head>
<body style="margin:0;background:#FFFFFF;color:#19194B;font-family:Arial,sans-serif;line-height:1.5">
<main style="max-width:640px;margin:auto;padding:32px">
<h1 style="color:#19194B;border-bottom:3px solid #F0A236;padding-bottom:12px">A Bata</h1>
<p>Tisztelt <?= $escape($data->contactName) ?>!</p>
<p>Foglalási igényét rögzítettük!</p>
<p>Kalkulált szállásdíj: <?= $escape(HufFormatter::format((string) $data->accommodationFee)) ?><br>
Az idegenforgalmi adó összege: <?= $escape(HufFormatter::format((string) $data->taxes)) ?>, mely a szálláshelyen külön fizetendő<br>
<strong>Előleg összege: <?= $escape(HufFormatter::format($data->advanceAmount)) ?></strong></p>
<p>Kérjük az előleg összegét 5 napon belül az alábbi bankszámlára szíveskedjen átutalni:</p>
<p><?= $escape($data->bankName) ?><br><?= $escape($data->beneficiary) ?><br><?= $escape($data->bankAccount) ?><br><?= $escape($data->swiftBic) ?></p>
<p><strong>Közlemény: <?= $escape((string) $data->paymentReference) ?></strong></p>
<p>Az előleg beérkezését követően foglalásáról visszaigazolást küldünk.</p>
<p>Bármilyen felmerülő kérdés, kérés esetén kérem keressen az info@abata.hu címen, vagy hívjon a +3670 4326-001 telefonszámon!</p>
<p>Üdvözlettel:<br>Petróczki-Oravecz Anikó<br>tulajdonos-üzemeltető<br>A BATA</p>
</main></body></html>
