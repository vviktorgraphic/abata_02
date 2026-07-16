<?php declare(strict_types=1); $title = 'Biztonsági ellenőrzés – A Bata'; require __DIR__ . '/_layout_start.php'; ?>
<section class="auth-card" aria-labelledby="verify-title">
    <p class="eyebrow">Kétlépcsős azonosítás</p>
    <h1 id="verify-title">Ellenőrző kód</h1>
    <p class="intro">Írd be az e-mailben kapott hatjegyű kódot.</p>
    <?php if (isset($error)): ?><div class="alert" role="alert"><?= htmlspecialchars((string) $error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
    <?php if (isset($message)): ?><div class="notice" role="status"><?= htmlspecialchars((string) $message, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
    <form method="post" action="/admin/verify">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars((string) $csrfToken, ENT_QUOTES, 'UTF-8') ?>">
        <label for="code">Hatjegyű ellenőrző kód</label>
        <input id="code" name="code" class="code-input" type="text" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" minlength="6" maxlength="6" required autofocus>
        <button type="submit">Ellenőrzés</button>
    </form>
    <form method="post" action="/admin/verify/resend" class="secondary-action">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars((string) $csrfToken, ENT_QUOTES, 'UTF-8') ?>">
        <button type="submit" class="button-secondary">Új kód kérése</button>
    </form>
</section>
<?php require __DIR__ . '/_layout_end.php'; ?>
