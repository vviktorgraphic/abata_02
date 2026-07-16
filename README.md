# Foglalási rendszer

Frameworkfüggetlen, PHP 8.2+ és MySQL 8 alapú foglalási rendszer egyetlen szálláshelyhez. Ez az első sprint a technikai alapokat, a sémát és a foglalási intervallum domain-szabályait tartalmazza.

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
- `GET /admin/login` – a későbbi admin belépés helyőrzője

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

## Jelenlegi hatókör

Az első sprint szándékosan nem tartalmaz frontend naptárt, e-mailküldést, 2FA-t, iCal-szinkront vagy teljes adminfelületet. Az admin login végpont csak helyőrző.
