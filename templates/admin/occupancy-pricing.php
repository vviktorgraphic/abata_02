<?php
declare(strict_types=1);

use App\Presentation\HufFormatter;

$title = 'Árképzés – A Bata';
$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$c = $configuration;
require __DIR__ . '/_layout_start.php';
?>
<section class="admin-page pricing-page" aria-labelledby="pricing-title">
<p class="eyebrow">Árkezelés</p>
<h1 id="pricing-title">Árképzés</h1>
<?php if ($error !== null): ?><p class="alert" role="alert"><?= $e($error) ?></p><?php endif ?>
<p>A 0–3 éves gyermek szállása ingyenes, ezért nem számít bele az árazási létszámba. A 4 éves vagy idősebb gyermek beleszámít.</p>

<section class="panel" aria-labelledby="base-prices-title">
<h2 id="base-prices-title">Alapárak tartózkodási hossz szerint</h2>
<p>Ezek az alapárak dátumtól függetlenül érvényesek. Ha egy adott évre, szezonra vagy dátumtartományra szeretne eltérő árakat megadni, használja az „Egyedi időszakos árak” részt.</p>
<p><a class="button-link" href="#override-prices-title">Éves vagy szezonális ár beállítása →</a></p>
<div class="table-scroll" tabindex="0" role="region" aria-label="Alapárak">
<table>
<thead><tr><th>Létszám</th><th>Minimum</th><th>Maximum</th><th>Ár / éj</th><th>Állapot</th><th>Művelet</th></tr></thead>
<tbody>
<?php foreach ($c->bands as $b): ?>
<tr>
<td><?= $b->guestCount ?></td>
<td><?= $b->minNights ?></td>
<td><?= $b->maxNights ?? 'Korlátlan' ?></td>
<td><?= $e(HufFormatter::format($b->nightlyPrice)) ?></td>
<td><?= $b->active ? 'Aktív' : 'Inaktív' ?></td>
<td>
<form class="inline-form" method="post" action="/admin/pricing">
<input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
<input type="hidden" name="version" value="<?= $c->version ?>">
<input type="hidden" name="action" value="band_toggle">
<input type="hidden" name="band_id" value="<?= $b->id ?>">
<input type="hidden" name="active" value="<?= $b->active ? '0' : '1' ?>">
<button class="compact <?= $b->active ? 'danger' : '' ?>" type="submit"><?= $b->active ? 'Inaktiválás' : 'Aktiválás' ?></button>
</form>
</td>
</tr>
<tr class="pricing-edit-row"><td colspan="6">
<details class="pricing-editor">
<summary>Szerkesztés<span class="sr-only">: <?= $b->guestCount ?> fő, <?= $b->minNights ?>–<?= $b->maxNights ?? 'korlátlan' ?> éjszaka</span></summary>
<form method="post" action="/admin/pricing" aria-label="Ársáv szerkesztése">
<input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
<input type="hidden" name="version" value="<?= $c->version ?>">
<input type="hidden" name="action" value="band">
<input type="hidden" name="band_id" value="<?= $b->id ?>">
<input type="hidden" name="active" value="<?= $b->active ? '1' : '0' ?>">
<div class="field-grid">
<label>Létszám<input name="guest_count" type="number" min="1" max="4" value="<?= $b->guestCount ?>" required></label>
<label>Minimum éjszaka<input name="min_nights" type="number" min="1" value="<?= $b->minNights ?>" required></label>
<label>Maximum éjszaka<input name="max_nights" type="number" min="1" value="<?= $b->maxNights ?? '' ?>" aria-describedby="band-max-help-<?= $b->id ?>"><small id="band-max-help-<?= $b->id ?>">Hagyja üresen, ha nincs felső korlát.</small></label>
<label>Ár / éj (Ft)<input name="nightly_price" inputmode="numeric" value="<?= $e(HufFormatter::groupedInput($b->nightlyPrice)) ?>" required></label>
</div>
<button class="pricing-save" type="submit">Mentés</button>
</form>
</details>
</td></tr>
<?php endforeach ?>
<?php if ($c->bands === []): ?><tr><td colspan="6">Még nincs alapár beállítva.</td></tr><?php endif ?>
</tbody>
</table>
</div>

<details class="new-band">
<summary>+ Új ársáv</summary>
<form method="post" action="/admin/pricing" aria-label="Új ársáv">
<input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
<input type="hidden" name="version" value="<?= $c->version ?>">
<input type="hidden" name="action" value="band">
<input type="hidden" name="band_id" value="0">
<input type="hidden" name="active" value="1">
<div class="field-grid">
<label>Létszám<input name="guest_count" type="number" min="1" max="4" required></label>
<label>Minimum éjszaka<input name="min_nights" type="number" min="1" required></label>
<label>Maximum éjszaka<input name="max_nights" type="number" min="1" aria-describedby="new-band-max-help"><small id="new-band-max-help">Hagyja üresen, ha nincs felső korlát.</small></label>
<label>Ár / éj (Ft)<input name="nightly_price" inputmode="numeric" required></label>
</div>
<button type="submit">Ársáv hozzáadása</button>
</form>
</details>
</section>

<section class="panel" aria-labelledby="surcharge-title">
<h2 id="surcharge-title">Egyéjszakás felár</h2>
<form method="post" action="/admin/pricing" class="field-grid">
<input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
<input type="hidden" name="version" value="<?= $c->version ?>">
<input type="hidden" name="action" value="surcharge">
<label>Felár (Ft / foglalás)<input name="amount" inputmode="numeric" value="<?= $e(HufFormatter::groupedInput($c->oneNightSurcharge)) ?>" required></label>
<button type="submit">Mentés</button>
</form>
</section>

<section class="panel" aria-labelledby="tourism-tax-title">
<h2 id="tourism-tax-title">Idegenforgalmi adó (IFA)</h2>
<form method="post" action="/admin/pricing" class="field-grid">
<input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
<input type="hidden" name="version" value="<?= $c->version ?>">
<input type="hidden" name="action" value="tourism_tax">
<label>IFA (Ft / felnőtt / éj)<input name="amount" inputmode="numeric" value="<?= $e(HufFormatter::groupedInput($c->tourismTaxPerPersonPerNight)) ?>" aria-describedby="tourism-tax-help" required></label>
<button type="submit">Mentés</button>
</form>
<p id="tourism-tax-help">Az IFA összege felnőttenként és éjszakánként kerül hozzáadásra; a 0–17 éves gyermekek nem növelik az IFA-t.</p>
</section>

<section class="panel" aria-labelledby="override-prices-title">
<h2 id="override-prices-title">Egyedi időszakos árak</h2>
<p>Adott évre vagy szezonra itt állíthat be árakat. Az időszak minden éjszakáján ez az ár helyettesíti az alapárat, a záró dátumot is beleértve. Az aktív időszakok nem fedhetik át egymást.</p>
<div class="table-scroll" tabindex="0" role="region" aria-label="Egyedi időszakos árak">
<table>
<thead><tr><th>Időszak</th><th>Tartózkodás</th><th>1 fő</th><th>2 fő</th><th>3 fő</th><th>4 fő</th><th>Állapot</th><th>Művelet</th></tr></thead>
<tbody>
<?php foreach ($c->overrides as $o): ?>
<tr>
<td><?= $e($o->startDate) ?> – <?= $e($o->endDate) ?></td>
<td><?= $o->maxNights === null ? $o->minNights . ' éjtől' : $o->minNights . '–' . $o->maxNights . ' éj' ?></td>
<?php for ($i = 1; $i <= 4; $i++): ?><td><?= $e(HufFormatter::format($o->priceFor($i))) ?></td><?php endfor ?>
<td><?= $o->active ? 'Aktív' : 'Inaktív' ?></td>
<td>
<form class="inline-form" method="post" action="/admin/pricing">
<input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
<input type="hidden" name="version" value="<?= $c->version ?>">
<input type="hidden" name="action" value="override_toggle">
<input type="hidden" name="override_id" value="<?= $o->id ?>">
<input type="hidden" name="active" value="<?= $o->active ? '0' : '1' ?>">
<button class="compact <?= $o->active ? 'danger' : '' ?>" type="submit"><?= $o->active ? 'Inaktiválás' : 'Aktiválás' ?></button>
</form>
</td>
</tr>
<tr class="pricing-edit-row"><td colspan="8">
<details class="pricing-editor">
<summary>Szerkesztés<span class="sr-only">: <?= $e($o->startDate) ?> – <?= $e($o->endDate) ?></span></summary>
<form method="post" action="/admin/pricing" aria-label="Időszakos ár szerkesztése">
<input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
<input type="hidden" name="version" value="<?= $c->version ?>">
<input type="hidden" name="action" value="override">
<input type="hidden" name="override_id" value="<?= $o->id ?>">
<input type="hidden" name="active" value="<?= $o->active ? '1' : '0' ?>">
<div class="field-grid">
<label>Kezdő dátum<input type="date" name="start_date" value="<?= $e($o->startDate) ?>" required></label>
<label>Záró dátum<input type="date" name="end_date" value="<?= $e($o->endDate) ?>" required></label>
<label>Minimum éjszaka<input type="number" name="min_nights" min="1" max="30" value="<?= $o->minNights ?>" required></label>
<label>Maximum éjszaka<input type="number" name="max_nights" min="1" max="30" value="<?= $o->maxNights ?? '' ?>" aria-describedby="override-max-help-<?= $o->id ?>"><small id="override-max-help-<?= $o->id ?>">Hagyja üresen, ha korlátlan.</small></label>
<?php for ($i = 1; $i <= 4; $i++): ?><label><?= $i ?> fő ára (Ft / éj)<input name="price_<?= $i ?>" inputmode="numeric" value="<?= $e(HufFormatter::groupedInput($o->priceFor($i))) ?>" required></label><?php endfor ?>
</div>
<button class="pricing-save" type="submit">Mentés</button>
</form>
</details>
</td>
</tr>
<?php endforeach ?>
<?php if ($c->overrides === []): ?><tr><td colspan="8">Még nincs egyedi időszakos ár beállítva.</td></tr><?php endif ?>
</tbody>
</table>
</div>

<details class="new-band">
<summary>+ Új időszak</summary>
<form method="post" action="/admin/pricing" aria-label="Új időszakos ár">
<input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
<input type="hidden" name="version" value="<?= $c->version ?>">
<input type="hidden" name="action" value="override">
<input type="hidden" name="override_id" value="0">
<input type="hidden" name="active" value="1">
<div class="field-grid">
<label>Kezdő dátum<input type="date" name="start_date" required></label>
<label>Záró dátum<input type="date" name="end_date" required></label>
<label>Minimum éjszaka<input type="number" name="min_nights" min="1" max="30" value="1" required></label>
<label>Maximum éjszaka<input type="number" name="max_nights" min="1" max="30" aria-describedby="new-override-max-help"><small id="new-override-max-help">Hagyja üresen, ha korlátlan.</small></label>
<?php for ($i = 1; $i <= 4; $i++): ?><label><?= $i ?> fő ára (Ft / éj)<input name="price_<?= $i ?>" inputmode="numeric" required></label><?php endfor ?>
</div>
<button type="submit">Időszak hozzáadása</button>
</form>
</details>
</section>
</section>
<?php require __DIR__ . '/_layout_end.php'; ?>
