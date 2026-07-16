<?php declare(strict_types=1); $title = 'A kérés sikertelen – A Bata'; require __DIR__ . '/_layout_start.php'; ?>
<section class="auth-card" aria-labelledby="error-title"><h1 id="error-title">A kérés sikertelen</h1><div class="alert" role="alert"><?= htmlspecialchars((string) $message, ENT_QUOTES, 'UTF-8') ?></div><p><a href="/admin/login">Vissza a bejelentkezéshez</a></p></section>
<?php require __DIR__ . '/_layout_end.php'; ?>
