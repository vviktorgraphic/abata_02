# Foglalási rendszer

Frameworkfüggetlen, PHP 8.2+ és MySQL 8 alapú foglalási rendszer egyetlen szálláshelyhez. A rendszer tartalmazza a publikus naptárt, admin-hitelesítést és foglaláskezelést, tranzakciós publikus foglalásmentést, közös szerveroldali pricing engine-t és admin pricing CRUD/preview felületet, policy-elfogadási snapshotot, valamint a 7 napos/50%-os lemondási szabályt és kapcsolódó outbox e-maileket.

## Rendszerspecifikáció

Az aktuális implementáció és a tervezett 1.0 célrendszer elsődleges, verziókezelt specifikációja a [docs/README.md](docs/README.md) indexből érhető el. A dokumentáció az **IMPLEMENTED** és **PLANNED** állapotot elkülönítve kezeli; fejlesztés előtt az érintett fejezeteket a kóddal, migrációkkal és tesztekkel együtt kell ellenőrizni.

## Követelmények és telepítés

Dockeres használathoz Docker Engine és Docker Compose szükséges. Helyi, cPanel-szerű futtatáshoz PHP 8.2+, PDO MySQL és Composer 2 kell, a webszerver document rootja pedig kizárólag a `public/` könyvtár legyen.

```powershell
Copy-Item .env.example .env
docker compose build
docker compose run --rm app composer install
```

A `.env` fejlesztői értékeit indulás előtt módosítsd. A valódi `.env` Git által kizárt fájl.

## Docker indítás

```powershell
docker compose up -d
```

Az alkalmazás: `http://localhost:8080`; Mailpit: `http://localhost:8025`. Az opcionális phpMyAdmin a tools profillal indítható:

```powershell
docker compose --profile tools up -d
```

Ekkor a phpMyAdmin a `http://localhost:8081` címen érhető el.

## Migrációk

```powershell
docker compose exec app composer migrate
```

A `bin/migrate.php` név szerint rendezi a `database/migrations/*.sql` fájlokat, és a `migrations` táblában rögzíti a már lefutott verziókat. Sémamódosítást mindig új SQL migrációban kell elkészíteni.

A kapcsolat biztonságosan, jelszó kiírása nélkül ellenőrizhető:

```powershell
docker compose exec app composer db:check
```

## Adatbázis-hitelesítési hiba

A MySQL Docker image a `MYSQL_DATABASE`, `MYSQL_USER` és `MYSQL_PASSWORD` változókat csak egy üres adatkönyvtár első inicializálásakor alkalmazza. Egy már létező volume felhasználóit és jelszavait a `.env` későbbi módosítása nem írja át. Ez tipikusan `SQLSTATE[HY000] [1045] Access denied` hibát okoz.

### Friss, eldobható fejlesztői adatbázis

> **FIGYELEM:** A `docker compose down -v` végleg törli a Docker volume-ban tárolt helyi adatbázis minden adatát. Éles, megőrzendő vagy nem mentett adatokon tilos használni.

Ha a helyi adatbázis biztosan eldobható:

```powershell
docker compose down -v
docker compose up -d --build
docker compose exec app composer migrate
```

### Meglévő, megőrzendő adatbázis

Először készíts ellenőrzött biztonsági mentést. Ezután lépj be interaktívan rootként; a kliens kérje be a jelszót, ne add meg a parancssorban:

```powershell
docker compose exec db mysql -u root -p
```

Az alábbi SQL-ben az adatbázis- és felhasználónevet igazítsd a helyi `.env` értékeihez. Az új jelszót kizárólag az interaktív munkamenetben helyettesítsd be, és ne mentsd repositoryba vagy shell historyba:

```sql
CREATE USER IF NOT EXISTS 'booking_user'@'%' IDENTIFIED BY '<new-application-password>';
ALTER USER 'booking_user'@'%' IDENTIFIED BY '<new-application-password>';
GRANT ALL PRIVILEGES ON booking_system.* TO 'booking_user'@'%';
```

A `CREATE USER`, `ALTER USER` és `GRANT` azonnal frissíti a jogosultsági táblákat, ezért modern MySQL 8 alatt `FLUSH PRIVILEGES` általában nem szükséges. Kézi jogosultságtábla-módosítás után futtasd csak:

```sql
FLUSH PRIVILEGES;
```

Végül ellenőrizd a kapcsolatot a `composer db:check`, majd futtasd a migrációt. Az alkalmazásfelhasználó csak a saját adatbázisára kapjon jogosultságot; globális `*.*` jogosultságot ne adj neki.

## Tesztek

```powershell
docker compose exec app composer test
```

Docker nélkül, telepített függőségekkel: `composer test`.

## Végpontok

- `GET /` – publikus, két hónapos foglalási naptár és adatbekérő űrlap
- `GET /health` – health check
- `GET /api/availability?from=2026-08-01&to=2026-10-01` – publikus foglaltsági adatok
- `POST /api/booking/validate` – mentés nélküli, előkészítő űrlap-validáció
- `GET /admin/login` – admin belépési oldal
- `POST /admin/login` – jelszavas első faktor, CSRF- és rate-limit védelemmel
- `GET /admin/2fa` – e-mailes kód megadása pending sessionben
- `POST /admin/2fa/verify` – második faktor ellenőrzése
- `POST /admin/2fa/resend` – új kód kérése resend limittel
- `GET /admin` – minimális, teljes 2FA-val védett dashboard
- `POST /admin/logout` – CSRF-védett kijelentkezés
- `GET /admin/pricing` – védett árszabálylista és előnézeti űrlap
- `GET /admin/pricing/create` – árszabály létrehozása
- `POST /admin/pricing` – validált, auditált árszabálymentés
- `GET /admin/pricing/{id}/edit` és `POST /admin/pricing/{id}` – árszabály szerkesztése
- `POST /admin/pricing/{id}/activate` és `/deactivate` – aktiválás/inaktiválás

## Sprint 2 indítása PowerShellből

```powershell
Copy-Item .env.example .env
docker compose up -d --build
docker compose exec app composer migrate
docker compose exec app composer seed:demo
docker compose exec app vendor/bin/phpunit
```

A `seed:demo` kizárólag `development`, `local` vagy `testing` környezetben fut. Idempotensen létrehoz egy többnapos megerősített foglalást, két azonos napon váltó foglalást, egy blokkolt időszakot, valamint egy-egy naptárt nem blokkoló `pending` és `cancelled` rekordot.

### Hasznos URL-ek

- Publikus foglalási oldal: `http://localhost:8080/`
- Availability API példa: `http://localhost:8080/api/availability?from=2026-08-01&to=2026-10-01`
- Health endpoint: `http://localhost:8080/health`
- Mailpit: `http://localhost:8025`
- phpMyAdmin a tools profillal: `http://localhost:8081`

### Availability API

A `from` inkluzív, a `to` exkluzív ISO `YYYY-MM-DD` dátum. A maximális lekérdezés 93 nap. A sikeres válasz tartalmazza a `from`, `to`, `timezone`, `rules` és `days` mezőket. Minden napi elem mezői:

- `date`: ISO dátum;
- `status`: `available`, `occupied`, `departure_only`, `arrival_only`, `turnover`, `blocked` vagy `past`;
- `selectable_as_arrival`: választható-e érkezésként;
- `selectable_as_departure`: választható-e távozásként.

Az API csak időszakokat kér le, vendéghez tartozó személyes adatot nem olvas és nem ad vissza. Kizárólag a `confirmed` foglalások blokkolják a naptárt; a `pending` és `cancelled` rekordok nem. A `blocked_periods` mindig blokkol.

PowerShell ellenőrzés:

```powershell
Invoke-RestMethod `
  -Uri "http://localhost:8080/api/availability?from=2026-08-01&to=2026-10-01" `
  -Method Get
```

### Manuális frontend ellenőrzőlista

- Két hónap látható, mobilon egymás alatt.
- Az előző és következő havi navigáció működik, a kijelölés megmarad.
- Először érkezés, majd távozás választható; a harmadik kattintás új kijelölést kezd.
- A foglalt, blokkolt és múltbeli nap nem választható.
- A turnover és a fél napos állapotok átlós cellával, megfelelő jelmagyarázattal jelennek meg.
- A „Dátumok törlése” visszaállítja a kijelölést.
- A gyermekek számához dinamikusan megjelennek az életkor mezők.
- A napok billentyűzettel fókuszálhatók, látható fókuszkerettel és állapotot közlő `aria-label` attribútummal.
- API-hibánál közérthető üzenet jelenik meg, részleges naptár nem marad a képernyőn.
- Az űrlap validációja egyértelműen jelzi, hogy foglalás mentése még nem történik.

## Könyvtárszerkezet

```text
bin/                    CLI belépési pontok, migrációfuttató
config/                 alkalmazáskonfiguráció
database/migrations/    verziózott SQL migrációk
docker/php/             fejlesztői PHP–Apache image
public/                 az egyetlen publikált web root
templates/              szerveroldali HTML sablonok
public/assets/          lokális, build nélküli CSS és JavaScript
src/Application/        alkalmazási use case-ek és repository interfészek
src/Domain/             üzleti objektumok és domain service-ek
src/Http/               routing és controllerek
src/Infrastructure/     PDO és adatbázis-infrastruktúra
tests/Unit/             egységtesztek
```

## Architekturális és dátumszabályok

A front controller egyszerű routeren keresztül hívja az MVC controllereket. Az üzleti logika nem a controllerekben, hanem adatbázistól független domain service-ekben él. Az infrastruktúra PDO-t használ, kikapcsolt emulált prepared statementekkel.

A foglalási időszak fél-nyitott: `[arrival_date, departure_date)`. Az érkezési nap foglalt, a távozási nap már nem, ezért egymást követő foglalások közös határnappal nem ütköznek. A foglalási napok MySQL `DATE` típusúak, az alkalmazás időzónája `Europe/Budapest`.

## Biztonsági alapelvek

- Titok és valódi `.env` nem kerülhet a repositoryba.
- Dinamikus SQL értékeket kizárólag PDO prepared statementtel kell kezelni.
- A jelszavakhoz PHP `password_hash()` / `password_verify()` használandó; a séma csak hash tárolására ad mezőt.
- A webszerver nem publikálhatja a projekt gyökerét, a konfigurációt vagy a migrációkat.
- Production környezetben a debug mód kikapcsolandó, HTTPS és biztonságos session-cookie beállítások kötelezők.
- Kimenetnél kontextusfüggő escaping, állapotmódosításnál CSRF-védelem szükséges.

## Sprint 3 – admin hitelesítési alap

**IMPLEMENTED komponensek:** e-mail-normalizálás és időzítéskiegyenlített jelszóellenőrzés; hatjegyű, hash-elve tárolt, 10 percig érvényes e-mailes 2FA-kód legfeljebb öt próbával és 60 másodperces újraküldési várakozással; 15 perces csúszó idle session; session-ID rotáció; sessionhöz kötött CSRF; konfigurálható rate limit; szűrt audit metadata; PDO persistence; cserélhető mailer és közvetlen `mail()` nélküli SMTP adapter; minimális admin login/2FA/dashboard sablonok.

**DECISION REQUIRED:** nincs feltételezett abszolút session-élettartam. A production SMTP portja, titkosítása, authentikációja, felhasználója és feladó címe nyitott. A rate-limit küszöbök konfigurálható fejlesztési alapértékek, nem végleges tulajdonosi döntések.

Az első admin létrehozásához a jelszó nem adható parancssori argumentumként. Készíts egy webrooton és repositoryn kívüli, csak átmenetileg olvasható fájlt, majd állítsd be az e-mailt és a fájl abszolút útvonalát:

```powershell
$env:ADMIN_CREATE_EMAIL = "admin@example.invalid"
$env:ADMIN_CREATE_PASSWORD_FILE = "/tmp/admin-password.txt"
docker compose cp "C:\secure-temp\admin-password.txt" app:/tmp/admin-password.txt
docker compose exec -e ADMIN_CREATE_EMAIL -e ADMIN_CREATE_PASSWORD_FILE app composer admin:create
docker compose exec app php -r 'unlink("/tmp/admin-password.txt");'
```

A forrásfájl legyen a repositoryn kívül, a konténerbeli másolat pedig csak a parancs idejéig létezzen. Alternatívaként a parancs közvetlenül a megfelelő cPanel/PHP környezetben futtatható. Siker után mindkét plaintext jelszófájlt biztonságosan törölni kell. A fájlt tilos a projektkönyvtárban tartani vagy commitolni.

## Jelenlegi hatókör

**IMPLEMENTED:** két hónapos publikus naptár, availability, admin-auth alapok és `POST /api/bookings`. Az új publikus igény `pending`; más pending igényt nem blokkol és nem jár le automatikusan, a `confirmed` booking és a blocked period viszont blokkol. A mentés idempotens, tranzakciós, HUF ár-pillanatképet és e-mail outbox rekordot hoz létre; SMTP-hiba nem törli a bookingot.

**IMPLEMENTED:** teljes admin booking workflow, pricing admin CRUD/preview, kötelező és verziózott booking-policy elfogadás, immutable pricing/cancellation snapshot, 7 naptári napos kötbérmentes határ és későbbi 50%-os kötbér, valamint Sprint 7 iCal import/export. **PLANNED:** általános e-mail retry, automatikus iCal cron/retry és online fizetés. A pontos határt a [rendszerspecifikáció](docs/README.md) tartja nyilván.

## Sprint 4 API smoke PowerShellből

Az indulás és a demo árszabály betöltése után:

```powershell
docker compose up -d --build
docker compose exec app composer migrate
docker compose exec app composer seed:demo

$body = @{
    arrival_date = '2026-08-10'; departure_date = '2026-08-13'
    contact_name = 'Teszt Elek'; email = 'guest@example.test'; phone = '+3612345678'
    adults = 2; children = 1; child_ages = @(6); notes = ''
    privacy_accepted = $true; idempotency_key = [guid]::NewGuid().ToString()
    website = ''
} | ConvertTo-Json

Invoke-RestMethod -Method Post -Uri 'http://localhost:8080/api/bookings' `
    -ContentType 'application/json' -Body $body
```

A demo seed szemléltető fejlesztési árat tartalmaz, production árként nem használható. A levél a Mailpit felületén ellenőrizhető: `http://localhost:8025`.

## Sprint 5 – admin foglaláskezelés

**IMPLEMENTED:** védett `/admin/bookings` lista/részlet kereséssel, szűréssel és lapozással; confirm/reject/cancel/invalidate; audit/history/outbox; konkurens confirm elleni inventory lock; blocked-period soft delete; státuszlevél és CSRF/no-store/rate-limit. A sikertelen státuszlevél a részletoldalról biztonságosan újraküldhető.

**PLANNED:** általános cron retry, maximális attempts és stale `processing` reclaim tulajdonosi döntés után.

## Sprint 6 – pricing, policy és lemondás

**IMPLEMENTED:** egyetlen szerveroldali pricing engine kezeli a tartózkodáshossz-sávot, mindhárom alapegységet, szezonális és konfigurált hétvégi adjustmentet, fix díjat, IFA-t és admin által megadott exemption kulcsokat. Azonos nyertes prioritás konfigurációs hiba; a publikus booking és az admin preview ugyanazt az engine-t használja. A konkrét production értékek nincsenek előre feltételezve.

A publikus űrlapon az adatkezelési jelölőtől külön, előre ki nem jelölt booking-policy checkbox kötelező. A booking tranzakciója a Budapest-idő szerinti elfogadási időt, a `BOOKING_POLICY_VERSION` értéket és a validált `BOOKING_POLICY_URL` címet snapshotolja. A confirmed booking legalább 7 naptári nappal érkezés előtt kötbérmentesen mondható le; később az immutable snapshot `accommodation_fee` értékének 50%-a a rögzített kötbér. Automatikus terhelés nincs.

PowerShell release-ellenőrzés:

```powershell
docker compose up -d --build
docker compose ps
docker compose exec app composer validate --no-check-publish
docker compose exec app composer db:check
docker compose exec app composer migrate
docker compose exec app composer migrate
docker compose exec app composer seed:demo
docker compose exec app vendor/bin/phpunit
git diff --check
git status
```

## Sprint 7 – iCal szinkron

**IMPLEMENTED:** Google Calendar és Szallas.hu iCal import kézi admin szinkronnal, forrás CRUD és szinkronnapló; külső eseményből külön blocked period készül, booking soha nem módosul. A tokenvédett `GET /calendar/export.ics?token=...` feed confirmed bookingokat és aktív blocked periodokat exportál PII nélkül. Pending/rejected/cancelled/invalidated booking nem exportálódik.

**PLANNED:** cron, automatikus retry/backoff, eltűnt esemény grace és tokenrotációs átfedés. Ezekhez nincs feltételezett alapérték.

PowerShell ellenőrzés:

```powershell
docker compose up -d --build
docker compose ps
docker compose exec app composer db:check
docker compose exec app composer migrate
docker compose exec app vendor/bin/phpunit
git diff --check
git status
```
