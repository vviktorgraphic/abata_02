<?php declare(strict_types=1); ?>
<?php if (!empty($snapshot['nightly_breakdown'])): ?>
<h2>Éjszakánkénti árbontás</h2>
<div class="table-scroll" tabindex="0" role="region" aria-label="Éjszakánkénti árbontás">
<table><thead><tr><th>Éjszaka</th><th>Árforrás</th><th>Éjszakai díj</th></tr></thead><tbody>
<?php foreach ($snapshot['nightly_breakdown'] as $night): ?>
<tr><th scope="row"><?= $e($night['date']) ?></th><?php if (isset($night['source'])): ?><td><?= ($night['source'] ?? '') === 'date_override' ? 'Egyedi időszakos ár' : 'Alapár' ?></td><td><?= $e(\App\Presentation\HufFormatter::format($night['nightly_price'] ?? '0.00')) ?></td><?php else: ?><td>Személyár</td><td><?= $e(\App\Presentation\HufFormatter::format($night['total'] ?? '0.00')) ?></td><?php endif; ?></tr>
<?php endforeach ?></tbody></table></div>
<?php endif ?>
