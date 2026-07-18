<?php declare(strict_types=1); $title='Naptárszinkron'; require __DIR__.'/_layout_start.php'; ?>
<h1>Naptárszinkron</h1>
<p><a href="/admin/calendar/sources">Források kezelése</a> · <a href="/admin/calendar/log">Szinkronnapló</a></p>
<?php if ($plainToken !== null): ?><section class="notice"><h2>Az új export token</h2><p>Másolja ki most, később nem jelenik meg újra.</p><code><?= htmlspecialchars($plainToken,ENT_QUOTES,'UTF-8') ?></code><p><code>/calendar/export.ics?token=<?= htmlspecialchars($plainToken,ENT_QUOTES,'UTF-8') ?></code></p></section><?php endif; ?>
<section><h2>iCal export</h2><p><?= $tokenMetadata===null?'Még nincs export token.':'Van aktív export token.' ?></p><form method="post" action="/admin/calendar/token/rotate"><input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken,ENT_QUOTES,'UTF-8') ?>"><button type="submit">Token <?= $tokenMetadata===null?'generálása':'cseréje' ?></button></form></section>
<section><h2>Források</h2><table><thead><tr><th>Név</th><th>Szolgáltató</th><th>Irány</th><th>Állapot</th><th>Művelet</th></tr></thead><tbody>
<?php foreach($sources as $s): ?><tr><td><?= htmlspecialchars((string)$s['name'],ENT_QUOTES,'UTF-8') ?></td><td><?= htmlspecialchars((string)$s['provider'],ENT_QUOTES,'UTF-8') ?></td><td><?= htmlspecialchars((string)$s['direction'],ENT_QUOTES,'UTF-8') ?></td><td><?= (bool)$s['enabled']?'aktív':'inaktív' ?></td><td><?php if((bool)$s['enabled']&&in_array($s['direction'],['import','bidirectional'],true)): ?><form method="post" action="/admin/calendar/sources/<?= (int)$s['id'] ?>/sync"><input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken,ENT_QUOTES,'UTF-8') ?>"><button>Szinkronizálás</button></form><?php endif; ?></td></tr><?php endforeach; ?>
</tbody></table></section>
<section><h2>Legutóbbi futások</h2><p><?= count($logs) ?> bejegyzés. <a href="/admin/calendar/log">Részletek</a></p></section>
<?php require __DIR__.'/_layout_end.php'; ?>
