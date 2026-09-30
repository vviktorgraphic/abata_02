<?php declare(strict_types=1); ?>
<?php if (!empty($snapshot['nightly_breakdown'])): ?>
<h2>Éjszakánkénti személyárak</h2>
<div class="table-scroll" tabindex="0" role="region" aria-label="Éjszakánkénti árbontás">
<table><thead><tr><th>Éjszaka</th><th>Felnőttek díja</th><th>Gyermekek díja</th><th>Szállásdíj</th></tr></thead><tbody>
<?php foreach ($snapshot['nightly_breakdown'] as $night): ?>
<tr><th scope="row"><?= $e($night['date']) ?><br><?= $night['weekend'] ? 'Hétvége' : 'Hétköznap' ?></th>
<td><?= (int) $night['adults'] ?> fő × <?= $e(\App\Presentation\HufFormatter::format($night['adult_unit_amount'])) ?><br>
Összesen: <?= $e(\App\Presentation\HufFormatter::format($night['adult_total'])) ?></td>
<td><?php foreach ($night['children'] as $child): ?>
<div><?= (int) $child['age'] ?> éves (<?= (int) $child['band']['min_age'] ?>–<?= (int) $child['band']['max_age'] ?> év): <?= $e(\App\Presentation\HufFormatter::format($child['total'])) ?></div>
<?php endforeach ?>Összesen: <?= $e(\App\Presentation\HufFormatter::format($night['children_total'])) ?></td>
<td><?= $e(\App\Presentation\HufFormatter::format($night['total'])) ?></td></tr>
<?php endforeach ?></tbody></table></div>
<?php endif ?>
