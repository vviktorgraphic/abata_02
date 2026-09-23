<?php
declare(strict_types=1);
use App\Presentation\HufFormatter;
$title = 'Személyalapú árak – A Bata';
$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
require __DIR__ . '/_layout_start.php';
?>
<section class="admin-page" aria-labelledby="person-pricing-title">
<p class="eyebrow">Árkezelés</p><h1 id="person-pricing-title">Személyalapú árak</h1>
<?php if ($error !== null): ?><div class="alert" role="alert"><?= $e($error) ?></div><?php endif ?>
<p>A gyermekkor 0–17 év, a felnőtt korhatár 18 év. Péntek és szombat éjszakára a hétvégi ár érvényes.</p>
<form class="panel" method="post" action="/admin/pricing/person">
<input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
<input type="hidden" name="version" value="<?= $configuration->version ?>">
<input type="hidden" name="action" value="settings">
<h2>Árazási mód és felnőttárak</h2>
<label for="pricing-mode">Árazási mód</label>
<select id="pricing-mode" name="mode">
<option value="legacy" <?= $configuration->mode === 'legacy' ? 'selected' : '' ?>>Meglévő árszabályok</option>
<option value="person" <?= $configuration->mode === 'person' ? 'selected' : '' ?>>Személyalapú árak</option>
</select>
<p class="notice">Személyalapú módban a felnőtt- és gyermekárak váltják fel az alapárat, a tartózkodáshossz alapárát és a külön hétvégi felárat. A szezonális módosítások, fix díjak és IFA megmaradnak. A korábbi foglalások ára nem változik.</p>
<div class="field-grid">
<label for="adult-weekday">Hétköznapi felnőttár (Ft / fő / éj)<input id="adult-weekday" name="adult_weekday_price" inputmode="numeric" pattern="[0-9]{1,10}" value="<?= $e($configuration->adultWeekdayPrice === null ? '' : HufFormatter::input($configuration->adultWeekdayPrice)) ?>"></label>
<label for="adult-weekend">Hétvégi felnőttár (Ft / fő / éj)<input id="adult-weekend" name="adult_weekend_price" inputmode="numeric" pattern="[0-9]{1,10}" value="<?= $e($configuration->adultWeekendPrice === null ? '' : HufFormatter::input($configuration->adultWeekendPrice)) ?>"></label>
</div><button type="submit">Felnőttárak és mód mentése</button>
</form>
<h2>Gyermek ársávok</h2>
<p>A korhatárok mindkét végpontja a sáv része. Aktív sávok nem fedhetik egymást. Hiányzó ársávval a foglalás nem küldhető be.</p>
<?php foreach ($configuration->childBands as $band): ?>
<form class="panel" method="post" action="/admin/pricing/person" aria-label="<?= $band->minAge ?>–<?= $band->maxAge ?> éves ársáv">
<input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>"><input type="hidden" name="version" value="<?= $configuration->version ?>"><input type="hidden" name="action" value="band"><input type="hidden" name="band_id" value="<?= $band->id ?>">
<h3><?= $band->minAge ?>–<?= $band->maxAge ?> év — <?= $band->active ? 'Aktív' : 'Inaktív' ?></h3>
<p>Hétköznap <?= $e(HufFormatter::format($band->weekdayPrice)) ?>, hétvégén <?= $e(HufFormatter::format($band->weekendPrice)) ?> / gyermek / éj.</p>
<div class="field-grid">
<label>Minimum életkor<input required type="number" min="0" max="17" name="min_age" value="<?= $band->minAge ?>"></label>
<label>Maximum életkor<input required type="number" min="0" max="17" name="max_age" value="<?= $band->maxAge ?>"></label>
<label>Hétköznapi ár (Ft)<input required inputmode="numeric" pattern="[0-9]{1,10}" name="weekday_price" value="<?= $e(HufFormatter::input($band->weekdayPrice)) ?>"></label>
<label>Hétvégi ár (Ft)<input required inputmode="numeric" pattern="[0-9]{1,10}" name="weekend_price" value="<?= $e(HufFormatter::input($band->weekendPrice)) ?>"></label>
<label>Sorrend<input required type="number" min="0" max="32767" name="sort_order" value="<?= $band->sortOrder ?>"></label>
</div><label class="checkbox-label"><input type="checkbox" name="active" value="1" <?= $band->active ? 'checked' : '' ?>> Aktív</label>
<button type="submit">Ársáv mentése</button></form>
<?php endforeach ?>
<form class="panel" method="post" action="/admin/pricing/person" aria-label="Új gyermek ársáv">
<h3>Új gyermek ársáv</h3>
<input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>"><input type="hidden" name="version" value="<?= $configuration->version ?>"><input type="hidden" name="action" value="band"><input type="hidden" name="band_id" value="0">
<div class="field-grid">
<label>Minimum életkor<input required type="number" min="0" max="17" name="min_age"></label>
<label>Maximum életkor<input required type="number" min="0" max="17" name="max_age"></label>
<label>Hétköznapi ár (Ft)<input required inputmode="numeric" pattern="[0-9]{1,10}" name="weekday_price"></label>
<label>Hétvégi ár (Ft)<input required inputmode="numeric" pattern="[0-9]{1,10}" name="weekend_price"></label>
<label>Sorrend<input required type="number" min="0" max="32767" name="sort_order" value="0"></label>
</div><label class="checkbox-label"><input type="checkbox" name="active" value="1" checked> Aktív</label>
<button type="submit">Ársáv hozzáadása</button></form>
<p><a class="button-link" href="/admin/pricing">Árkalkuláció előnézet és egyéb árszabályok</a></p>
</section>
<?php require __DIR__ . '/_layout_end.php'; ?>
