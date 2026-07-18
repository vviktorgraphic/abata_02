# Production monitoring és cron runbook

**Állapot:** IMPLEMENTED health/readiness alap; PLANNED/BLOCKED automatikus alkalmazásjobok

## Health és külső probe

Az `GET /health` az alkalmazás és az adatbázis minimális readiness ellenőrzése. Siker esetén `200` és `{"status":"ok"}`, a route eléréséig sikeres bootstrap után fellépő adatbázishibánál `503` és `{"status":"unavailable"}` a válasz. A front controller előtti autoload/config/PHP fatális hibák kezelését a webszerver/PHP-FPM 5xx monitorozása fedi le; ezekre az endpoint nem ígér saját JSON választ. A health válasz nem közöl verziót, hostnevet, credentialt, migrációszintet, kivételt vagy személyes adatot.

A külső monitor kizárólag HTTPS-en, átirányítás nélkül kérje le a production URL-t, 30–60 másodperces probe-időközzel és rövid timeouttal. A konkrét időköz és riasztási küszöb üzemeltetői döntés; a repository nem talál ki SLA-t. Ellenőrizendő a státuszkód, a JSON státusz és a válaszidő. A publikus `/` külön, ritkább szintetikus probe-ja jelezheti a webes renderelési hibát, de ne küldjön foglalást és ne használjon PII-t.

## Metrikák és riasztások

Minimum dashboard:

- `/health` rendelkezésre állás és válaszidő;
- HTTP 5xx arány, PHP fatális hibák és adatbázis-kapcsolati hibák;
- lemez- és inode-telítettség, process/memory limitek;
- sikertelen SMTP-küldések és `email_outbox` állapotok;
- iCal források utolsó sikere, sync warning/error és confirmed konfliktus;
- backup frissesség, méret/checksum és utolsó igazolt restore;
- admin auth rate-limit/lockout anomáliák.

Azonnali riasztás indokolt ismétlődő health/DB hibánál, backup-hiánynál vagy biztonsági eseménynél. SMTP/iCal tartós hiba riasztandó, de a konkrét időablak és ügyeleti címzett **BLOCKED**, amíg az owner az SLA-t és az escalation útvonalat jóvá nem hagyja. Minden alerthez legyen felelős, acknowledge és escalation cél; secret nem lehet alert címében vagy payloadjában.

## Naplózás és rotáció

Az alkalmazás- és webszervernapló legyen a `public/` könyvtáron kívül, például `/home/CPANEL_USER/logs/APP_NAME/`. A helyőrzőket telepítéskor a valós cPanel útvonalakra kell cserélni. Javasolt mezők: ISO-8601 időpont, környezet, severity, eseménytípus, korrelációs azonosító és PII-mentes technikai eredmény. Üzleti naptári dátum Europe/Budapest szerint értelmezendő.

Tilos naplózni: jelszó, session/CSRF/2FA/feed token, SMTP/DB credential, teljes iCal URL, nyers request body és szükségtelen vendégadat. A webserver query-string naplózását úgy kell konfigurálni, hogy a `/calendar/export.ics?token=...` token ne kerüljön access logba.

A cPanel rotáció vagy a hosting által támogatott `logrotate` méret/idő alapon archiváljon, tömörítsen és legszűkebb jogosultságot tartson. A konkrét megőrzési idő **BLOCKED** az adatmegőrzési döntésig; korlátlan logmegőrzés nem elfogadott. Rotáció után ellenőrizni kell, hogy PHP/Apache továbbra is ír az aktív fájlba.

## Cron inventory

A cron minden esetben abszolút PHP- és scriptútvonalat használjon. Secret nem lehet argumentum vagy cron output; a `.env` legyen webrooton kívül. Kimenetet csak secretmentes, rotált logba szabad irányítani, és a job hibánál nem nulla exit kódot adjon.

### IMPLEMENTED, ütemezhető parancsok

Jelenleg nincs productionre ütemezhető iCal, outbox vagy cleanup CLI worker. A meglévő `bin/migrate.php`, `bin/db-check.php`, `bin/admin-create.php` és `bin/seed-demo.php` nem periodikus production cron feladat. A migráció kontrollált deployment lépés; az admin-create kézi bootstrap; a seed productionben tilos.

### PLANNED/BLOCKED jobok

| Job | Állapot | Blokkoló feltétel |
|---|---|---|
| iCal import sync | **PLANNED/BLOCKED** | nincs CLI entrypoint, globális lock, jóváhagyott gyakoriság, retry/backoff és eltűnési grace |
| E-mail outbox retry | **PLANNED/BLOCKED** | nincs worker; maximum attempts, backoff és stale `processing` reclaim nincs jóváhagyva |
| Adat/log cleanup | **PLANNED/BLOCKED** | nincs worker és jóváhagyott retention; booking-idempotencia időalapú törlése kifejezetten tilos új owner döntés nélkül |

Ezért production crontab/cPanel Cron Jobs felületére ezekhez **nem adható futtatható parancssor**. A későbbi belépési pont elfogadási feltétele: idempotencia, párhuzamos futást kizáró lock, részleges hiba kezelése, secretmentes log, dokumentált exit code, automatizált teszt és owner által jóváhagyott ütemezés.

## Üzemeltetési ellenőrzőlista

1. Igazold, hogy `https://PRODUCTION_HOST/health` `200`-at ad, és adatbázis-kiesés staging próbáján `503`-ra vált belső hiba felfedése nélkül.
2. Állíts be külső HTTPS probe-ot és külön 5xx/tárhely/backup riasztást.
3. Ellenőrizd a naplókönyvtár webes elérhetetlenségét, jogosultságát, redakcióját és rotációját.
4. Rögzítsd az ügyeleti tulajdonost, escalation csatornát és jóváhagyott küszöböket.
5. Ne hozz létre iCal/outbox/cleanup cron sort addig, amíg a megfelelő worker és üzleti döntések hiányoznak.
