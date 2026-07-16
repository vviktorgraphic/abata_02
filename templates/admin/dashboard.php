<?php declare(strict_types=1); $title = 'Vezérlőpult – A Bata'; require __DIR__ . '/_layout_start.php'; ?>
<section class="dashboard" aria-labelledby="dashboard-title">
    <div><p class="eyebrow">A Bata admin</p><h1 id="dashboard-title">Vezérlőpult</h1></div>
    <form method="post" action="/admin/logout">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars((string) $csrfToken, ENT_QUOTES, 'UTF-8') ?>">
        <button type="submit" class="button-secondary">Kijelentkezés</button>
    </form>
    <div class="placeholder-panel">
        <h2>Üdv, <?= htmlspecialchars((string) $admin['name'], ENT_QUOTES, 'UTF-8') ?>!</h2>
        <p>A foglalások adminisztrációs funkciói egy későbbi sprintben készülnek el.</p>
    </div>
</section>
<?php require __DIR__ . '/_layout_end.php'; ?>
