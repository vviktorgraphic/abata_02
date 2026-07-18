<?php declare(strict_types=1); $title = 'Foglalások – A Bata'; require __DIR__ . '/_layout_start.php'; $e = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
<section class="admin-page" aria-labelledby="bookings-title">
 <p class="eyebrow">Adminisztráció</p><h1 id="bookings-title">Foglalások</h1>
 <form class="filters" method="get" action="/admin/bookings" aria-label="Foglalások szűrése">
  <fieldset class="filter-group filter-group-primary"><legend>Gyors szűrés</legend><div class="filter-fields">
   <div class="filter-field filter-field-search"><label for="q">Keresés</label><input id="q" name="q" maxlength="100" value="<?= $e($filters['q'] ?? '') ?>" placeholder="Referencia, név, e-mail vagy telefon"></div>
   <div class="filter-field"><label for="status">Státusz</label><select id="status" name="status"><option value="">Minden státusz</option><?php foreach (['pending'=>'Függő','confirmed'=>'Megerősített','rejected'=>'Elutasított','cancelled'=>'Lemondott','invalidated'=>'Érvénytelenített'] as $value=>$label): ?><option value="<?= $value ?>" <?= ($filters['status'] ?? '') === $value ? 'selected' : '' ?>><?= $label ?></option><?php endforeach ?></select></div>
  </div></fieldset>
  <fieldset class="filter-group"><legend>Érkezési időszak</legend><div class="filter-fields">
   <div class="filter-field"><label for="arrival_from">Érkezés ettől</label><input type="date" id="arrival_from" name="arrival_from" value="<?= $e($filters['arrival_from'] ?? '') ?>"></div>
   <div class="filter-field"><label for="arrival_until">Érkezés eddig</label><input type="date" id="arrival_until" name="arrival_until" value="<?= $e($filters['arrival_until'] ?? '') ?>"></div>
  </div></fieldset>
  <fieldset class="filter-group"><legend>Létrehozási időszak</legend><div class="filter-fields">
   <div class="filter-field"><label for="created_from">Létrehozva ettől</label><input type="date" id="created_from" name="created_from" value="<?= $e($filters['created_from'] ?? '') ?>"></div>
   <div class="filter-field"><label for="created_until">Létrehozva eddig</label><input type="date" id="created_until" name="created_until" value="<?= $e($filters['created_until'] ?? '') ?>"></div>
  </div></fieldset>
  <div class="filter-actions"><button type="submit">Szűrés alkalmazása</button><a class="button-secondary filter-reset" href="/admin/bookings">Szűrők törlése</a></div>
 </form>
 <p aria-live="polite"><?= (int) $total ?> találat</p>
 <div class="table-scroll" tabindex="0" role="region" aria-label="Foglalási találatok"><table><thead><tr><th>Referencia</th><th>Kapcsolattartó</th><th>Érkezés</th><th>Távozás</th><th>Éj</th><th>Létszám</th><th>Összeg</th><th>Státusz</th><th>Létrehozva</th><th></th></tr></thead><tbody>
 <?php foreach ($bookings as $booking): ?><tr><th scope="row"><?= $e($booking['reference']) ?></th><td><?= $e($booking['contact_name']) ?></td><td><?= $e($booking['arrival_date']) ?></td><td><?= $e($booking['departure_date']) ?></td><td><?= (int) $booking['nights'] ?></td><td><?= (int) $booking['party_size'] ?></td><td><?= $e($booking['total_amount']) ?> <?= $e($booking['currency']) ?></td><td><span class="status status-<?= $e($booking['status']) ?>"><?= $e($booking['status']) ?></span></td><td><?= $e($booking['created_at']) ?></td><td><a href="/admin/bookings/<?= rawurlencode((string) $booking['reference']) ?>">Megnyitás<span class="sr-only">: <?= $e($booking['reference']) ?></span></a></td></tr><?php endforeach ?>
 <?php if ($bookings === []): ?><tr><td colspan="10">Nincs a szűrésnek megfelelő foglalás.</td></tr><?php endif ?></tbody></table></div>
 <nav class="pagination" aria-label="Lapozás"><?php if ($page > 1): ?><a href="?<?= $e(http_build_query(array_merge($filters, ['page'=>$page-1]))) ?>">Előző</a><?php endif ?><span><?= (int) $page ?> / <?= (int) $pages ?> oldal</span><?php if ($page < $pages): ?><a href="?<?= $e(http_build_query(array_merge($filters, ['page'=>$page+1]))) ?>">Következő</a><?php endif ?></nav>
</section><?php require __DIR__ . '/_layout_end.php'; ?>
