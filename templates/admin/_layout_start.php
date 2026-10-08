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
<header class="brand-header"><a class="brand" href="/admin" aria-label="A Bata admin kezdőlap">A Bata</a><nav aria-label="Admin navigáció"><a href="/admin/bookings">Foglalások</a><a href="/admin/bookings/monthly">Havi foglaltság</a><a href="/admin/bookings/import">Korábbi import</a><a href="/admin/blocked-periods">Blokkolt időszakok</a><a href="/admin/pricing">Árképzés</a><a href="/admin/users">Felhasználók</a><a href="/admin/calendar">Naptárszinkron</a></nav></header>
<main class="admin-main">
