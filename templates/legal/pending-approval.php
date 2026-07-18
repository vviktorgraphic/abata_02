<!doctype html>
<html lang="hu">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> | A Bata</title>
    <link rel="stylesheet" href="/assets/css/booking.css">
</head>
<body>
<main class="booking-shell" data-legal-page="<?= htmlspecialchars($contentType, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
    <section class="form-panel" aria-labelledby="legal-title">
        <p class="eyebrow">A Bata</p>
        <h1 id="legal-title"><?= htmlspecialchars($pageTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>
        <div class="notice notice-error" role="status">
            <strong>Fejlesztői tartalom – jogi jóváhagyásra vár.</strong>
            <p>Ez az oldal kizárólag a technikai útvonal ellenőrzésére szolgál. Nem tartalmaz jóváhagyott jogi tájékoztatást, ezért production környezetben nem publikálható ebben az állapotban.</p>
        </div>
        <p><a href="/">Vissza a foglaláshoz</a></p>
    </section>
</main>
</body>
</html>
