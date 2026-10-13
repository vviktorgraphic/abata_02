<?php

declare(strict_types=1);

/** @var array<string,mixed> $values */
/** @var array<string,string> $errors */
/** @var \DateTimeImmutable $today */
/** @var ?\App\Application\Booking\AdminManualBookingPreview $preview */
$title = 'Új foglalás – A Bata';
$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$ages = is_array($values['child_ages'] ?? null) ? array_values($values['child_ages']) : [];
$children = filter_var($values['children'] ?? 0, FILTER_VALIDATE_INT);
$children = $children === false ? 0 : max(0, min(4, (int) $children));
$error = static function (string $field) use ($errors, $e): string {
    return isset($errors[$field]) ? '<p class="field-error" id="error-' . $e($field) . '">' . $e($errors[$field]) . '</p>' : '';
};
require __DIR__ . '/_layout_start.php';
?>
<section class="admin-page booking-create-page" aria-labelledby="booking-create-title">
    <p><a href="/admin/bookings">← Vissza a foglalásokhoz</a></p>
    <p class="eyebrow">Adminisztráció</p>
    <h1 id="booking-create-title">Új foglalás</h1>
    <p class="intro">Telefonon, közvetlen e-mailben vagy más közvetlen csatornán érkezett foglalási igény rögzítése.</p>
    <div class="notice">
        Ez adminisztrátor által rögzített foglalás. A vendég weboldali nyilatkozatainak elfogadási időpontja nem jön létre automatikusan.
    </div>
    <?php if ($errors !== []): ?>
        <div class="alert" role="alert"><strong>A foglalás még nem rögzíthető.</strong><ul><?php foreach ($errors as $message): ?><li><?= $e($message) ?></li><?php endforeach ?></ul></div>
    <?php endif ?>

    <form method="post" action="/admin/bookings/create/preview#booking-create-submit" class="booking-create-form" data-admin-booking-create-form>
        <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
        <input type="hidden" name="idempotency_key" value="<?= $e($values['idempotency_key'] ?? '') ?>">
        <fieldset>
            <legend>Időszak és létszám</legend>
            <div class="field-grid">
                <div><label for="admin-booking-arrival">Érkezés</label><input id="admin-booking-arrival" type="date" name="arrival_date" min="<?= $e($today->format('Y-m-d')) ?>" required value="<?= $e($values['arrival_date'] ?? '') ?>"><?= $error('arrival_date') ?></div>
                <div><label for="admin-booking-departure">Távozás</label><input id="admin-booking-departure" type="date" name="departure_date" min="<?= $e($today->format('Y-m-d')) ?>" required value="<?= $e($values['departure_date'] ?? '') ?>"><?= $error('departure_date') ?></div>
                <div><label for="admin-booking-adults">Felnőttek</label><select id="admin-booking-adults" name="adults" required><?php for ($i=1;$i<=4;$i++): ?><option value="<?= $i ?>" <?= (int)($values['adults']??1)===$i?'selected':'' ?>><?= $i ?></option><?php endfor ?></select><?= $error('adults') ?></div>
                <div><label for="admin-booking-children">Gyermekek</label><select id="admin-booking-children" name="children" data-admin-booking-child-count required><?php for ($i=0;$i<=4;$i++): ?><option value="<?= $i ?>" <?= $children===$i?'selected':'' ?>><?= $i ?></option><?php endfor ?></select><?= $error('children') ?></div>
            </div>
            <div class="field-grid admin-booking-child-ages" data-admin-booking-child-ages data-initial-ages="<?= $e(json_encode($ages, JSON_THROW_ON_ERROR)) ?>">
                <?php for ($i=0;$i<$children;$i++): ?><div><label for="admin-booking-child-age-<?= $i ?>"><?= $i+1 ?>. gyermek életkora</label><input id="admin-booking-child-age-<?= $i ?>" type="number" name="child_ages[]" min="0" max="17" step="1" required value="<?= $e($ages[$i] ?? '') ?>"></div><?php endfor ?>
            </div>
            <?= $error('child_ages') ?><?= $error('guests') ?><?= $error('dates') ?>
        </fieldset>
        <fieldset>
            <legend>Kapcsolattartó</legend>
            <div class="field-grid">
                <div><label for="admin-booking-name">Név</label><input id="admin-booking-name" name="contact_name" maxlength="190" required value="<?= $e($values['contact_name'] ?? '') ?>"><?= $error('contact_name') ?></div>
                <div><label for="admin-booking-email">E-mail</label><input id="admin-booking-email" type="email" name="email" maxlength="190" required value="<?= $e($values['email'] ?? '') ?>"><?= $error('email') ?></div>
                <div><label for="admin-booking-phone">Telefon</label><input id="admin-booking-phone" type="tel" name="phone" maxlength="50" required value="<?= $e($values['phone'] ?? '') ?>"><?= $error('phone') ?></div>
            </div>
            <label for="admin-booking-notes">Megjegyzés (opcionális)</label><textarea id="admin-booking-notes" name="notes" maxlength="2000"><?= $e($values['notes'] ?? '') ?></textarea><?= $error('notes') ?>
        </fieldset>
        <?= $error('pricing') ?><?= $error('stale') ?><?= $error('form') ?>
        <button type="submit">Előnézet</button>
    </form>

    <?php if ($preview !== null && $previewSignature !== null): $request=$preview->request; $pricing=$preview->pricing; ?>
        <section class="panel booking-create-preview" aria-labelledby="booking-create-preview-title" aria-live="polite">
            <h2 id="booking-create-preview-title">Foglalás előnézete</h2>
            <dl class="facts">
                <div><dt>Éjszakák</dt><dd><?= $request->nights() ?></dd></div>
                <div><dt>Vendégek</dt><dd><?= $request->adults ?> felnőtt, <?= count($request->childAges) ?> gyermek</dd></div>
                <div><dt>Szállásdíj</dt><dd><?= $e(\App\Presentation\HufFormatter::format($pricing->accommodationFee)) ?></dd></div>
                <div><dt>IFA/adó</dt><dd><?= $e(\App\Presentation\HufFormatter::format($pricing->tourismTax)) ?></dd></div>
                <div><dt>Végösszeg</dt><dd><?= $e(\App\Presentation\HufFormatter::format($pricing->totalAmount)) ?></dd></div>
                <div><dt>Pénznem</dt><dd><?= $e($pricing->currency) ?></dd></div>
            </dl>
            <p class="notice">A rögzítéskor a rendszer újra ellenőrzi a foglaltságot és az árat. Automatikus e-mail nem kerül kiküldésre.</p>
            <form id="booking-create-submit" method="post" action="/admin/bookings/create">
                <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                <input type="hidden" name="preview_signature" value="<?= $e($previewSignature) ?>">
                <input type="hidden" name="expected_total" value="<?= $e($pricing->totalAmount) ?>">
                <input type="hidden" name="expected_currency" value="<?= $e($pricing->currency) ?>">
                <input type="hidden" name="idempotency_key" value="<?= $e($request->idempotencyKey) ?>">
                <input type="hidden" name="arrival_date" value="<?= $e($request->period->arrival->format('Y-m-d')) ?>">
                <input type="hidden" name="departure_date" value="<?= $e($request->period->departure->format('Y-m-d')) ?>">
                <input type="hidden" name="contact_name" value="<?= $e($request->contactName) ?>">
                <input type="hidden" name="email" value="<?= $e($request->email) ?>">
                <input type="hidden" name="phone" value="<?= $e($request->phone) ?>">
                <input type="hidden" name="adults" value="<?= $request->adults ?>">
                <input type="hidden" name="children" value="<?= count($request->childAges) ?>">
                <?php foreach ($request->childAges as $age): ?><input type="hidden" name="child_ages[]" value="<?= $age ?>"><?php endforeach ?>
                <input type="hidden" name="notes" value="<?= $e($request->notes) ?>">
                <button type="submit">Foglalás rögzítése</button>
            </form>
        </section>
    <?php endif ?>
</section>
<?php require __DIR__ . '/_layout_end.php'; ?>
