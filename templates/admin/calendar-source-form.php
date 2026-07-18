<?php declare(strict_types=1); $title=$editing?'Naptárforrás szerkesztése':'Naptárforrás felvétele'; require __DIR__.'/_layout_start.php'; ?>
<h1><?= htmlspecialchars($title,ENT_QUOTES,'UTF-8') ?></h1><form method="post" action="<?= $editing?'/admin/calendar/sources/'.(int)$source['id']:'/admin/calendar/sources' ?>"><input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken,ENT_QUOTES,'UTF-8') ?>">
<label>Név <input name="name" maxlength="150" required value="<?= htmlspecialchars((string)$source['name'],ENT_QUOTES,'UTF-8') ?>"></label>
<label>Szolgáltató <select name="provider"><option value="google_calendar" <?= $source['provider']==='google_calendar'?'selected':'' ?>>Google Calendar</option><option value="szallas_hu" <?= $source['provider']==='szallas_hu'?'selected':'' ?>>Szallas.hu</option></select></label>
<label>iCal URL <input type="url" name="url" required value="<?= htmlspecialchars((string)$source['url'],ENT_QUOTES,'UTF-8') ?>"></label>
<label>Szinkron token <input type="password" name="sync_token" maxlength="255" autocomplete="new-password" value="" aria-describedby="sync-token-help"></label>
<small id="sync-token-help"><?= $editing?'Hagyja üresen a meglévő token megtartásához. A mentett token biztonsági okból nem olvasható vissza.':'Opcionális; a mentett token biztonsági okból nem olvasható vissza.' ?></small>
<label>Irány <select name="direction"><?php foreach(['import'=>'Import','export'=>'Export','bidirectional'=>'Kétirányú'] as $v=>$label): ?><option value="<?= $v ?>" <?= $source['direction']===$v?'selected':'' ?>><?= $label ?></option><?php endforeach; ?></select></label>
<label><input type="checkbox" name="enabled" value="1" <?= (bool)$source['enabled']?'checked':'' ?>> Engedélyezve</label><button>Mentés</button></form>
<?php require __DIR__.'/_layout_end.php'; ?>
