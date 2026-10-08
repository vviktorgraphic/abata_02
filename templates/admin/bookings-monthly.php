<?php

declare(strict_types=1);

$title = 'Havi foglaltság – A Bata';
require __DIR__ . '/_layout_start.php';
$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$monthUrl = static fn (string $month): string => '/admin/bookings/monthly?' . http_build_query(['month' => $month]);
$renderNavigation = static function (array $data) use ($e, $monthUrl): void { ?>
    <nav class="month-navigation" aria-label="Hónapválasztás">
        <a class="button-secondary" href="<?= $e($monthUrl($data['previous_month'])) ?>" rel="prev">← Előző hónap</a>
        <strong aria-live="polite"><?= $e($data['label']) ?></strong>
        <a class="button-secondary" href="<?= $e($monthUrl($data['next_month'])) ?>" rel="next">Következő hónap →</a>
        <?php if (!$data['is_current_month']): ?>
            <a class="button-secondary" href="<?= $e($monthUrl($data['current_month'])) ?>">Mai hónap</a>
        <?php endif ?>
    </nav>
<?php };
?>
<section class="admin-page monthly-occupancy" aria-labelledby="monthly-title">
    <p class="eyebrow">Adminisztráció</p>
    <h1 id="monthly-title">Havi foglaltság</h1>
    <p class="intro">A havi nézet a függőben lévő és megerősített foglalásokat, valamint az aktív blokkolt időszakokat mutatja. A távozás napja már nem foglalt éjszaka.</p>
    <?php $renderNavigation($occupancy) ?>
    <div class="table-scroll monthly-table-scroll" tabindex="0" role="region" aria-label="<?= $e($occupancy['label']) ?> napi foglaltsága">
        <table class="monthly-table">
            <thead><tr><th>Dátum</th><th>Nap</th><th>Állapot</th><th>Foglalások</th><th>Blokkolások és források</th></tr></thead>
            <tbody>
            <?php foreach ($occupancy['days'] as $day): ?>
                <tr data-date="<?= $e($day['date']) ?>" class="<?= $day['is_today'] ? 'is-today ' : '' ?><?= $day['is_weekend'] ? 'is-weekend' : '' ?>">
                    <th scope="row"><time datetime="<?= $e($day['date']) ?>"<?= $day['is_today'] ? ' aria-current="date"' : '' ?>><?= $e($day['display_date']) ?></time></th>
                    <td><?= $e($day['weekday']) ?></td>
                    <td class="monthly-badges">
                        <?php foreach ($day['badges'] as $badge): ?><span class="occupancy-badge occupancy-<?= $e($badge['key']) ?>"><?= $e($badge['label']) ?></span><?php endforeach ?>
                    </td>
                    <td>
                        <?php if ($day['bookings'] === []): ?><span class="muted">—</span><?php else: ?><ul class="occupancy-events">
                        <?php foreach ($day['bookings'] as $booking): ?><li>
                            <a href="/admin/bookings/<?= rawurlencode($booking['reference']) ?>"><?= $e($booking['reference']) ?></a>
                            — <?= $e($booking['contact_name']) ?>
                            <span class="status status-<?= $e($booking['status']) ?>"><?= $e(\App\Presentation\BookingStatusLabel::for($booking['status'])) ?></span>
                            <small><?= $e($booking['event_label']) ?><?php if ($booking['legacy']): ?> · korábbi import<?php endif ?></small>
                        </li><?php endforeach ?>
                        </ul><?php endif ?>
                    </td>
                    <td>
                        <?php if ($day['blocks'] === []): ?><span class="muted">—</span><?php else: ?><ul class="occupancy-events">
                        <?php foreach ($day['blocks'] as $block): ?><li>
                            <?php if ($block['kind'] === 'external'): ?>
                                <strong><?= $e($block['provider_label']) ?></strong><?php if ($block['source_name'] !== null && $block['source_name'] !== ''): ?> — <?= $e($block['source_name']) ?><?php endif ?><?php if ($block['summary'] !== null && $block['summary'] !== ''): ?> · <?= $e($block['summary']) ?><?php endif ?>
                            <?php else: ?>
                                <strong>Kézi blokkolás</strong><?php if ($block['reason'] !== ''): ?> — <?= $e($block['reason']) ?><?php endif ?>
                            <?php endif ?>
                        </li><?php endforeach ?>
                        </ul><?php endif ?>
                    </td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>
    <?php $renderNavigation($occupancy) ?>
</section>
<?php require __DIR__ . '/_layout_end.php'; ?>
