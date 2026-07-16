# Foglalási rendszer

Frameworkfüggetlen, PHP 8.2+ és MySQL 8 alapú foglalási rendszer egyetlen szálláshelyhez. Ez az első sprint a technikai alapokat, a sémát és a foglalási intervallum domain-szabályait tartalmazza.

## Követelmények és telepítés

Dockeres használathoz Docker Engine és Docker Compose szükséges. Helyi, cPanel-szerű futtatáshoz PHP 8.2+, PDO MySQL és Composer 2 kell, a webszerver document rootja pedig kizárólag a `public/` könyvtár legyen.

```bash
cp .env.example .env
docker compose build
docker compose run --rm app composer install
```

A `.env` fejlesztői értékeit indulás előtt módosítsd. A valódi `.env` Git által kizárt fájl.

## Docker indítás

```bash
docker compose up -d
```

Az alkalmazás: `http://localhost:8080`; Mailpit: `http://localhost:8025`. Az opcionális phpMyAdmin a tools profillal indítható:

```bash
docker compose --profile tools up -d
```

Ekkor a phpMyAdmin a `http://localhost:8081` címen érhető el.

## Migrációk

```bash
docker compose exec app composer migrate
```

A `bin/migrate.php` név szerint rendezi a `database/migrations/*.sql` fájlokat, és a `migrations` táblában rögzíti a már lefutott verziókat. Sémamódosítást mindig új SQL migrációban kell elkészíteni.

## Tesztek

```bash
docker compose exec app composer test
```

Docker nélkül, telepített függőségekkel: `composer test`.

## Végpontok

- `GET /` – minimális alkalmazásválasz
- `GET /health` – health check
- `GET /admin/login` – a későbbi admin belépés helyőrzője

## Könyvtárszerkezet

```text
bin/                    CLI belépési pontok, migrációfuttató
config/                 alkalmazáskonfiguráció
database/migrations/    verziózott SQL migrációk
docker/php/             fejlesztői PHP–Apache image
public/                 az egyetlen publikált web root
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
