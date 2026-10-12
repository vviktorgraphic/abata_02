<?php

declare(strict_types=1);

/** @var array{id: int, name: string} $admin */
/** @var \DateTimeImmutable $today */
/** @var \App\Application\Booking\AdminDashboardOverview $overview */
$title = 'Operatív áttekintés – A Bata';
$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$detailUrl = static fn (string $reference): string => '/admin/bookings/' . rawurlencode($reference);
$dateLabel = static function (string $date): string {
    $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);

    return $parsed instanceof \DateTimeImmutable ? $parsed->format('Y. m. d.') : $date;
};
$partyLabel = static fn (array $booking): string => sprintf(
    '%d fő (%d felnőtt, %d gyermek)',
    (int) $booking['party_size'],
    (int) $booking['adults'],
    (int) $booking['children'],
);
$upcomingFrom = $today->modify('+1 day')->format('Y-m-d');
$upcomingUntil = $today->modify('+7 days')->format('Y-m-d');

require __DIR__ . '/_layout_start.php';
?>
<section class="admin-page dashboard-page" aria-labelledby="dashboard-title">
    <header class="dashboard-heading">
        <div>
            <p class="eyebrow">A Bata admin</p>
            <h1 id="dashboard-title">Operatív áttekintés</h1>
            <p class="intro">Üdv, <?= $escape((string) $admin['name']) ?>! A mai nap: <time datetime="<?= $escape($today->format('Y-m-d')) ?>"><?= $escape($dateLabel($today->format('Y-m-d'))) ?></time></p>
        </div>
    </header>

    <section aria-labelledby="dashboard-summary-title">
        <h2 id="dashboard-summary-title">Mai összefoglaló</h2>
        <div class="dashboard-summary-grid">
            <article class="dashboard-summary-card">
                <h3>Függő foglalási igények</h3>
                <p class="dashboard-summary-value"><?= $overview->pendingCount ?></p>
                <a href="/admin/bookings?status=pending">Függő foglalások megnyitása</a>
            </article>
            <article class="dashboard-summary-card">
                <h3>Mai érkezők</h3>
                <p class="dashboard-summary-value"><?= $overview->arrivalsTodayCount ?></p>
            </article>
            <article class="dashboard-summary-card">
                <h3>Mai távozók</h3>
                <p class="dashboard-summary-value"><?= $overview->departuresTodayCount ?></p>
            </article>
            <article class="dashboard-summary-card">
                <h3>Jelenlegi vendégek</h3>
                <p class="dashboard-summary-value"><?= $overview->currentGuestsCount ?></p>
            </article>
        </div>
    </section>

    <div class="dashboard-today-grid">
        <section class="dashboard-panel" aria-labelledby="dashboard-arrivals-title">
            <h2 id="dashboard-arrivals-title">Mai érkezők</h2>
            <?php if ($overview->arrivalsToday === []): ?>
                <p class="dashboard-empty">Ma nincs érkező vendég.</p>
            <?php else: ?>
                <ul class="dashboard-booking-list">
                    <?php foreach ($overview->arrivalsToday as $booking): ?>
                        <li>
                            <div class="dashboard-booking-heading">
                                <a href="<?= $escape($detailUrl($booking['reference'])) ?>"><?= $escape($booking['reference']) ?></a>
                                <strong><?= $escape($booking['contact_name']) ?></strong>
                            </div>
                            <dl class="dashboard-booking-facts">
                                <div><dt>Időszak</dt><dd><?= $escape($dateLabel($booking['arrival_date'])) ?> – <?= $escape($dateLabel($booking['departure_date'])) ?></dd></div>
                                <div><dt>Létszám</dt><dd><?= $escape($partyLabel($booking)) ?></dd></div>
                            </dl>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <section class="dashboard-panel" aria-labelledby="dashboard-departures-title">
            <h2 id="dashboard-departures-title">Mai távozók</h2>
            <?php if ($overview->departuresToday === []): ?>
                <p class="dashboard-empty">Ma nincs távozó vendég.</p>
            <?php else: ?>
                <ul class="dashboard-booking-list">
                    <?php foreach ($overview->departuresToday as $booking): ?>
                        <li>
                            <div class="dashboard-booking-heading">
                                <a href="<?= $escape($detailUrl($booking['reference'])) ?>"><?= $escape($booking['reference']) ?></a>
                                <strong><?= $escape($booking['contact_name']) ?></strong>
                            </div>
                            <dl class="dashboard-booking-facts">
                                <div><dt>Időszak</dt><dd><?= $escape($dateLabel($booking['arrival_date'])) ?> – <?= $escape($dateLabel($booking['departure_date'])) ?></dd></div>
                                <div><dt>Létszám</dt><dd><?= $escape($partyLabel($booking)) ?></dd></div>
                            </dl>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    </div>

    <section class="dashboard-panel" aria-labelledby="dashboard-upcoming-title">
        <h2 id="dashboard-upcoming-title">Következő érkezések</h2>
        <?php if ($overview->upcomingArrivals === []): ?>
            <p class="dashboard-empty">A következő hét napban nincs megerősített érkezés.</p>
        <?php else: ?>
            <ul class="dashboard-booking-list dashboard-upcoming-list">
                <?php foreach ($overview->upcomingArrivals as $booking): ?>
                    <li>
                        <div class="dashboard-booking-heading">
                            <time datetime="<?= $escape($booking['arrival_date']) ?>"><?= $escape($dateLabel($booking['arrival_date'])) ?></time>
                            <a href="<?= $escape($detailUrl($booking['reference'])) ?>"><?= $escape($booking['reference']) ?></a>
                            <strong><?= $escape($booking['contact_name']) ?></strong>
                        </div>
                        <dl class="dashboard-booking-facts">
                            <div><dt>Éjszakák</dt><dd><?= (int) $booking['nights'] ?></dd></div>
                            <div><dt>Létszám</dt><dd><?= (int) $booking['party_size'] ?> fő</dd></div>
                        </dl>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <?php if ($overview->hasMoreUpcoming): ?>
            <p class="dashboard-list-link"><a class="button-link" href="/admin/bookings?status=confirmed&amp;arrival_from=<?= $escape($upcomingFrom) ?>&amp;arrival_until=<?= $escape($upcomingUntil) ?>">Összes közelgő foglalás</a></p>
        <?php endif; ?>
    </section>

    <nav class="dashboard-quick-navigation" aria-labelledby="dashboard-quick-navigation-title">
        <h2 id="dashboard-quick-navigation-title">Gyorsműveletek</h2>
        <div class="dashboard-quick-links">
            <a href="/admin/bookings/create">Új foglalás</a>
            <a href="/admin/bookings">Foglalások</a>
            <a href="/admin/bookings/monthly">Havi foglaltság</a>
            <a href="/admin/blocked-periods">Blokkolt időszakok</a>
            <a href="/admin/pricing">Árképzés</a>
            <a href="/admin/calendar">Naptárszinkron</a>
            <a href="/admin/users">Felhasználók</a>
        </div>
    </nav>
</section>
<?php require __DIR__ . '/_layout_end.php'; ?>
