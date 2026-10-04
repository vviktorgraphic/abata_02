<?php
/** @var \App\Application\Mail\BookingRequestMailData $data */
$e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$ages = $data->childAges === [] ? 'nincs' : implode(', ', $data->childAges) . ' év';
$notes = $data->notes === null || trim($data->notes) === '' ? 'Nincs megjegyzés.' : $data->notes;
?><!doctype html><html lang="hu"><head><meta charset="utf-8"><title>Új foglalási igény érkezett</title><style>:root { --color-primary: #19194B; --color-accent: #F0A236; --color-background: #FFFFFF; }</style></head>
<body style="margin:0;background:#fff;color:#19194B;font-family:Arial,sans-serif"><main style="max-width:640px;margin:auto;padding:32px;border-top:8px solid #F0A236">
<h1 style="color:#19194B">Új foglalási igény</h1><p><strong><?= $e($data->reference) ?></strong> — jelenlegi állapot: <strong>pending</strong></p>
<table role="presentation" style="border-collapse:collapse"><tr><th align="left">Név</th><td><?= $e($data->contactName) ?></td></tr><tr><th align="left">E-mail</th><td><?= $e($data->guestEmail !== '' ? $data->guestEmail : $data->recipient) ?></td></tr><tr><th align="left">Telefon</th><td><?= $e($data->phone) ?></td></tr>
<tr><th align="left">Érkezés</th><td><?= $e($data->arrivalDate) ?></td></tr><tr><th align="left">Távozás</th><td><?= $e($data->departureDate) ?></td></tr><tr><th align="left">Éjszakák</th><td><?= $data->nights() ?></td></tr><tr><th align="left">Felnőttek</th><td><?= $data->adults ?></td></tr><tr><th align="left">Gyermekek</th><td><?= count($data->childAges) ?> (életkorok: <?= $e($ages) ?>)</td></tr><tr><th align="left">Megjegyzés</th><td><?= nl2br($e($notes)) ?></td></tr><tr><th align="left">Végösszeg</th><td><strong><?= $e(\App\Presentation\HufFormatter::format($data->totalAmount)) ?> <?= $e($data->currency) ?></strong></td></tr></table>
<?php if ($data->adminUrl !== ''): ?><p style="margin-top:28px"><a href="<?= $e($data->adminUrl) ?>" style="display:inline-block;padding:14px 22px;background:#F0A236;color:#19194B;text-decoration:none;font-weight:bold">Foglalás megnyitása</a></p><?php endif; ?>
</main></body></html>
