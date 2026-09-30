<?php
declare(strict_types=1);

use App\Presentation\HufFormatter;

$title = 'Árkalkuláció előnézet – A Bata';
$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
require __DIR__ . '/_layout_start.php';
?>
<section class="admin-page" aria-labelledby="preview-title">
<p class="eyebrow">Árképzés</p><h1 id="preview-title">Árkalkuláció előnézet</h1>
<?php if ($error !== null): ?>
<div class="alert" role="alert"><?= $e($error) ?></div>
<?php else: ?>
<dl class="facts preview-facts">
<div><dt>Szállásdíj</dt><dd><?= $e(HufFormatter::format($result->accommodationFee)) ?></dd></div>
<div><dt>IFA</dt><dd><?= $e(HufFormatter::format($result->tourismTax)) ?></dd></div>
<div><dt>Végösszeg</dt><dd class="total"><?= $e(HufFormatter::format($result->totalAmount)) ?></dd></div>
<div><dt>Éjszakák</dt><dd><?= $summary['nights'] ?></dd></div>
<div><dt>Felnőttek</dt><dd><?= $summary['adults'] ?> fő</dd></div>
<div><dt>Gyermekek életkora</dt><dd><?= $summary['child_ages'] === [] ? 'Nincs gyermek' : $e(implode(', ', $summary['child_ages'])) . ' év' ?></dd></div>
<div><dt>Felnőttek díja</dt><dd><?= $summary['adult_fee'] === null ? 'Nem áll rendelkezésre' : $e(HufFormatter::format($summary['adult_fee'])) ?></dd></div>
<div><dt>Gyermekek díja</dt><dd><?= $summary['child_fee'] === null ? 'Nem áll rendelkezésre' : $e(HufFormatter::format($summary['child_fee'])) ?></dd></div>
</dl>
<?php $snapshot = $result->snapshot; ?>
<?php if (!empty($snapshot['nightly_breakdown'])): ?><details class="nightly-details"><summary>Éjszakánkénti részletek</summary><?php require __DIR__ . '/_nightly-pricing.php'; ?></details><?php endif ?>
<h2>Tételek</h2>
<?php $itemLabels = ['accommodation'=>'Szállásdíj','seasonal'=>'Időszakos ármódosítás','fixed_fee'=>'Egyéb díj','tourism_tax'=>'IFA','exemption'=>'IFA-kedvezmény']; ?>
<div class="table-scroll" tabindex="0" role="region" aria-label="Árkalkuláció tételei"><table><thead><tr><th>Megnevezés</th><th>Mennyiség</th><th>Összeg</th></tr></thead><tbody><?php foreach ($result->lineItems as $item): ?><tr><td><?= $e($itemLabels[$item['type'] ?? ''] ?? 'Ártétel') ?></td><td><?= $e($item['quantity'] ?? '') ?></td><td><?= $e(HufFormatter::format($item['total'] ?? '0')) ?></td></tr><?php endforeach ?></tbody></table></div>
<?php endif ?>
<p><a class="button-link" href="/admin/pricing">Vissza az árképzéshez</a></p>
</section>
<?php require __DIR__ . '/_layout_end.php'; ?>
