<?php declare(strict_types=1); $title = 'Megerősítés díjbekérő nélkül – A Bata'; require __DIR__ . '/_layout_start.php'; $e = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
<article class="admin-page detail" aria-labelledby="confirm-without-payment-title">
<p class="eyebrow">Kivételes megerősítés</p>
<h1 id="confirm-without-payment-title">Megerősítés díjbekérő nélkül</h1>
<div class="alert" role="alert">
<p><strong>Biztosan megerősíted ezt a foglalást díjbekérő és előleg nélkül?</strong></p>
<p>A vendég foglalása azonnal Megerősítve státuszba kerül, és a szokásos foglalás-visszaigazoló e-mailt megkapja.</p>
<ul><li>Díjbekérő nem kerül kiküldésre.</li><li>Előleg nem kerül rögzítésre.</li><li>A vendég foglalás-visszaigazoló e-mailt kap.</li></ul>
</div>
<dl class="facts">
<div><dt>Foglalási referencia</dt><dd><?= $e($booking['reference']) ?></dd></div>
<div><dt>Érkezés</dt><dd><?= $e($booking['arrival_date']) ?></dd></div>
<div><dt>Távozás</dt><dd><?= $e($booking['departure_date']) ?></dd></div>
<div><dt>Aktuális végösszeg</dt><dd><?= !empty($booking['legacy_pricing_unavailable']) ? 'nem áll rendelkezésre' : $e(\App\Presentation\HufFormatter::format($booking['total_amount'])) ?></dd></div>
</dl>
<form class="panel" method="post" action="/admin/bookings/<?= rawurlencode((string) $booking['reference']) ?>/confirm-without-payment">
<input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
<label for="confirm-without-payment-note">Admin megjegyzés (opcionális)</label>
<textarea id="confirm-without-payment-note" name="admin_note" maxlength="500"></textarea>
<button class="danger" type="submit">Igen, megerősítem díjbekérő nélkül</button>
</form>
<p><a class="filter-reset" href="/admin/bookings/<?= rawurlencode((string) $booking['reference']) ?>">Mégse</a></p>
</article>
<?php require __DIR__ . '/_layout_end.php'; ?>
