<?php declare(strict_types=1); $title = 'Admin bejelentkezés – A Bata'; require __DIR__ . '/_layout_start.php'; ?>
<section class="auth-card" aria-labelledby="login-title">
    <p class="eyebrow">Adminisztráció</p>
    <h1 id="login-title">Bejelentkezés</h1>
    <p class="intro">Add meg az adminisztrátori hozzáférésed adatait.</p>
    <?php if (isset($error)): ?><div class="alert" role="alert"><?= htmlspecialchars((string) $error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
    <form method="post" action="/admin/login">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars((string) $csrfToken, ENT_QUOTES, 'UTF-8') ?>">
        <label for="email">E-mail-cím</label>
        <input id="email" name="email" type="email" autocomplete="username" maxlength="254" required autofocus>
        <label for="password">Jelszó</label>
        <input id="password" name="password" type="password" autocomplete="current-password" maxlength="1024" required>
        <button type="submit">Tovább</button>
    </form>
</section>
<?php require __DIR__ . '/_layout_end.php'; ?>
