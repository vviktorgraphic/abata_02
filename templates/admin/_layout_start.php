<?php declare(strict_types=1); ?>
<!doctype html>
<html lang="hu">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($title ?? 'A Bata admin', ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="/assets/css/admin.css">
    <script src="/assets/js/admin-auth.js" defer></script>
</head>
<body>
<header class="brand-header"><a class="brand" href="/admin" aria-label="A Bata admin kezdőlap">A Bata</a><nav aria-label="Admin navigáció"><a href="/admin/bookings">Foglalások</a><a href="/admin/blocked-periods">Blokkolt időszakok</a><a href="/admin/pricing">Árképzés</a></nav></header>
<main class="admin-main">
