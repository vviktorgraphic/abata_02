<?php
declare(strict_types=1);

use App\Presentation\HufFormatter;

$title = 'Árképzés – A Bata';
$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
require __DIR__ . '/_layout_start.php';
?>
<section class="admin-page pricing-page" aria-labelledby="pricing-title">
<p class="eyebrow">Adminisztráció</p>
<h1 id="pricing-title">Árképzés</h1>
<?php if ($error !== null): ?><div class="alert" role="alert"><?= $e($error) ?></div><?php endif ?>
<?php if ($configuration->mode === 'legacy'): ?><div class="notice">A felnőttárak mentéséig a korábban beállított árak maradnak érvényben.</div><?php endif ?>

<section class="panel" aria-labelledby="adult-prices-title">
<h2 id="adult-prices-title">Felnőttárak</h2>
<p>A péntek és szombat éjszaka hétvégi árnak számít.</p>
<form method="post" action="/admin/pricing">
<input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
<input type="hidden" name="version" value="<?= $configuration->version ?>">
<input type="hidden" name="action" value="settings">
<div class="field-grid">
<label for="adult-weekday">Hétköznapi ár (Ft / fő / éj)<input required id="adult-weekday" name="adult_weekday_price" inputmode="numeric" pattern="[0-9]{1,10}" value="<?= $e($configuration->adultWeekdayPrice === null ? '' : HufFormatter::input($configuration->adultWeekdayPrice)) ?>"></label>
<label for="adult-weekend">Hétvégi ár (Ft / fő / éj)<input required id="adult-weekend" name="adult_weekend_price" inputmode="numeric" pattern="[0-9]{1,10}" value="<?= $e($configuration->adultWeekendPrice === null ? '' : HufFormatter::input($configuration->adultWeekendPrice)) ?>"></label>
</div>
<button type="submit">Felnőttárak mentése</button>
</form>
</section>

<section class="panel" aria-labelledby="child-prices-title">
<h2 id="child-prices-title">Gyermek ársávok</h2>
<p>A korhatárok mindkét végpontja a sáv része.</p>
<?php if ($hasMissingChildAgeCoverage): ?><div class="alert" role="alert">Nincs minden gyermek életkorhoz ár beállítva. Az érintett életkorral foglalás addig nem küldhető be.</div><?php endif ?>
<div class="table-scroll" tabindex="0" role="region" aria-label="Gyermek ársávok">
<table class="child-price-table">
<thead><tr><th>Kor</th><th>Hétköznap</th><th>Hétvége</th><th>Művelet</th></tr></thead>
<tbody>
<?php foreach ($visibleBands as $band): ?>
<tr>
<td data-label="Kor"><strong><?= $band->minAge ?>–<?= $band->maxAge ?> év</strong></td>
<td data-label="Hétköznap"><?= $e(HufFormatter::format($band->weekdayPrice)) ?></td>
<td data-label="Hétvége"><?= $e(HufFormatter::format($band->weekendPrice)) ?></td>
<td data-label="Művelet" class="table-actions"><details><summary>Szerkesztés</summary><form method="post" action="/admin/pricing"><input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>"><input type="hidden" name="version" value="<?= $configuration->version ?>"><input type="hidden" name="action" value="band"><input type="hidden" name="band_id" value="<?= $band->id ?>"><div class="band-edit-fields"><label>Minimum életkor<input required type="number" min="0" max="17" name="min_age" value="<?= $band->minAge ?>"></label><label>Maximum életkor<input required type="number" min="0" max="17" name="max_age" value="<?= $band->maxAge ?>"></label><label>Hétköznapi ár<input required inputmode="numeric" pattern="[0-9]{1,10}" name="weekday_price" value="<?= $e(HufFormatter::input($band->weekdayPrice)) ?>"></label><label>Hétvégi ár<input required inputmode="numeric" pattern="[0-9]{1,10}" name="weekend_price" value="<?= $e(HufFormatter::input($band->weekendPrice)) ?>"></label></div><button class="compact" type="submit">Mentés</button></form></details><form method="post" action="/admin/pricing" data-confirm="Biztosan törli ezt a gyermek ársávot?"><input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>"><input type="hidden" name="version" value="<?= $configuration->version ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="band_id" value="<?= $band->id ?>"><button class="compact danger" type="submit">Törlés</button></form></td>
</tr>
<?php endforeach ?>
<?php if ($visibleBands === []): ?><tr><td colspan="4">Még nincs gyermek ársáv beállítva.</td></tr><?php endif ?>
</tbody>
</table>
</div>

<details class="new-band"><summary>+ Új gyermek ársáv</summary>
<form method="post" action="/admin/pricing" aria-label="Új gyermek ársáv">
<input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>"><input type="hidden" name="version" value="<?= $configuration->version ?>"><input type="hidden" name="action" value="band"><input type="hidden" name="band_id" value="0">
<div class="field-grid"><label>Minimum életkor<input required type="number" min="0" max="17" name="min_age"></label><label>Maximum életkor<input required type="number" min="0" max="17" name="max_age"></label><label>Hétköznapi ár (Ft)<input required inputmode="numeric" pattern="[0-9]{1,10}" name="weekday_price"></label><label>Hétvégi ár (Ft)<input required inputmode="numeric" pattern="[0-9]{1,10}" name="weekend_price"></label></div>
<button type="submit">Ársáv hozzáadása</button>
</form>
</details>
</section>

<section class="panel" aria-labelledby="stay-length-prices-title">
<h2 id="stay-length-prices-title">Tartózkodás hossza szerinti felnőttárak</h2>
<p>Ha az éjszakák száma beleesik egy aktív sávba, annak Ft/fő/éj ára érvényes minden felnőttre a teljes tartózkodás alatt. Megfelelő sáv hiányában a normál hétköznapi/hétvégi felnőttár érvényes.</p>
<div class="table-scroll" tabindex="0" role="region" aria-label="Tartózkodási felnőtt ársávok"><table><thead><tr><th>Éjszakák</th><th>Ár / fő / éj</th><th>Állapot</th><th>Művelet</th></tr></thead><tbody>
<?php foreach ($visibleAdultStayBands as $band): ?><tr><td><?= $band->maxNights === null ? $band->minNights.'+ éj' : ($band->minNights === $band->maxNights ? $band->minNights.' éj' : $band->minNights.'–'.$band->maxNights.' éj') ?></td><td><?= $e(HufFormatter::format($band->pricePerPersonPerNight)) ?></td><td><?= $band->active ? 'Aktív' : 'Inaktív' ?></td><td><details><summary>Szerkesztés</summary><form method="post" action="/admin/pricing"><input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>"><input type="hidden" name="version" value="<?= $configuration->version ?>"><input type="hidden" name="action" value="adult_stay_band"><input type="hidden" name="band_id" value="<?= $band->id ?>"><div class="field-grid"><label>Minimum éjszaka<input required type="number" min="1" name="min_nights" value="<?= $band->minNights ?>"></label><label>Maximum éjszaka<input type="number" min="1" name="max_nights" value="<?= $band->maxNights ?? '' ?>"></label><label>Ár (Ft / fő / éj)<input required inputmode="numeric" name="price_per_person_per_night" value="<?= $e(HufFormatter::input($band->pricePerPersonPerNight)) ?>"></label></div><button class="compact" type="submit">Mentés</button></form></details><form method="post" action="/admin/pricing"><input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>"><input type="hidden" name="version" value="<?= $configuration->version ?>"><input type="hidden" name="action" value="adult_stay_toggle"><input type="hidden" name="band_id" value="<?= $band->id ?>"><input type="hidden" name="active" value="<?= $band->active ? '0' : '1' ?>"><button class="compact <?= $band->active ? 'danger' : '' ?>" type="submit"><?= $band->active ? 'Inaktiválás' : 'Aktiválás' ?></button></form></td></tr><?php endforeach ?>
<?php if ($visibleAdultStayBands === []): ?><tr><td colspan="4">Még nincs tartózkodási ársáv beállítva.</td></tr><?php endif ?></tbody></table></div>
<details class="new-band"><summary>+ Új tartózkodási ársáv</summary><form method="post" action="/admin/pricing"><input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>"><input type="hidden" name="version" value="<?= $configuration->version ?>"><input type="hidden" name="action" value="adult_stay_band"><input type="hidden" name="band_id" value="0"><div class="field-grid"><label>Minimum éjszaka<input required type="number" min="1" name="min_nights"></label><label>Maximum éjszaka<input type="number" min="1" name="max_nights"></label><label>Ár (Ft / fő / éj)<input required inputmode="numeric" name="price_per_person_per_night"></label></div><button type="submit">Ársáv hozzáadása</button></form></details>
</section>

<section class="panel" aria-labelledby="preview-title">
<h2 id="preview-title">Árkalkuláció előnézet</h2>
<form method="post" action="/admin/pricing/preview">
<input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
<div class="field-grid"><label for="arrival-date">Érkezés<input required type="date" id="arrival-date" name="arrival_date"></label><label for="departure-date">Távozás<input required type="date" id="departure-date" name="departure_date"></label><label for="adults">Felnőttek száma<input required type="number" min="0" max="30" id="adults" name="adults" value="2"></label><label for="child-ages">Gyermekek életkora<input id="child-ages" name="child_ages" placeholder="például: 4, 10" aria-describedby="child-ages-help"><small id="child-ages-help">Vesszővel elválasztva; üresen hagyható.</small></label></div>
<button type="submit">Előnézet számítása</button>
</form>
</section>
</section>
<?php require __DIR__ . '/_layout_end.php'; ?>
