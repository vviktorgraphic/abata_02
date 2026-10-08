<?php declare(strict_types=1); $staticAssets = require dirname(__DIR__, 2) . '/config/static-assets.php'; ?>
<!doctype html>
<html lang="hu">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($title ?? 'A Bata admin', ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="<?= htmlspecialchars($staticAssets['admin_css'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
    <script src="<?= htmlspecialchars($staticAssets['admin_js'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" defer></script>
</head>
<body>
<header class="brand-header"><a class="brand" href="/admin" aria-label="A Bata admin kezdőlap">A Bata</a><nav aria-label="Admin navigáció"><a href="/admin/bookings">Foglalások</a><a href="/admin/bookings/monthly">Havi foglaltság</a><a href="/admin/bookings/import">Korábbi import</a><a href="/admin/blocked-periods">Blokkolt időszakok</a><a href="/admin/pricing">Árképzés</a><a href="/admin/users">Felhasználók</a><a href="/admin/calendar">Naptárszinkron</a></nav><?php if (!empty($showLogout) && isset($csrfToken)): ?><form class="header-logout" method="post" action="/admin/logout"><input type="hidden" name="_csrf" value="<?= htmlspecialchars((string)$csrfToken,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8') ?>"><button type="submit" title="Kijelentkezés" aria-label="Kijelentkezés"><svg aria-hidden="true" viewBox="0 0 24 24" width="22" height="22"><path fill="currentColor" d="M10 17v-2h4V9h-4V7l-5 5 5 5zm3-14h6a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-6v-2h6V5h-6V3z"/></svg></button></form><?php endif ?></header>
<main class="admin-main">
