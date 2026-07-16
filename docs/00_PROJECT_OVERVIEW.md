# Projektáttekintés

**Állapot:** IMPLEMENTED állapotfelmérés és PLANNED 1.0 rendszerterv
**Utolsó ellenőrzött commit:** `9adc564`

## A dokumentum célja

Ez a dokumentum elválasztja a repositoryban igazolható jelenlegi működést a tervezett 1.0 rendszer hatókörétől. A technikai felépítés részleteit az [architektúra](01_ARCHITECTURE.md), az adatsémát az [adatbázis- és domainmodell](02_DATABASE_AND_DOMAIN_MODEL.md), a fejlesztési sorrendet pedig a [roadmap és döntési napló](11_ROADMAP_AND_DECISIONS.md) tartalmazza.

## Üzleti cél

**IMPLEMENTED:** A vendég a nyilvános naptárban időszakot választ és `pending` foglalási igényt küld; az admin booking- és pricing-kezelőfelület elkészült.

**IMPLEMENTED:** A vendég megtekintheti a naptárt, majd a `POST /api/bookings` végponton tranzakciósan és idempotensen `pending` igényt hozhat létre szerveroldali árral, snapshot-tal és e-mail outbox rekorddal.

## Szerepkörök

| Szerepkör | Cél | Állapot |
|---|---|---|
| Publikus látogató / vendég | Foglaltság megtekintése, időszak és vendégadatok megadása | **IMPLEMENTED**, mentés nélkül |
| Adminisztrátor | Belépés jelszóval és e-mailes 2FA-val; foglalások és árak kezelése | Auth, booking és pricing admin **IMPLEMENTED**; külső integrációk **PLANNED** |
| Üzemeltető | Telepítés, migráció, mentés, cron, SMTP és megfigyelés | **PLANNED** productionre; Docker fejlesztői parancsok **IMPLEMENTED** |
| Külső naptárszolgáltató | iCal események átadása és fogadása | **DEFERRED** későbbi sprintre |

> **DECISION REQUIRED:** El kell dönteni, hogy az adminisztrátor és az üzemeltető 1.0-ban külön jogosultsági szerepkör-e. A jelenlegi `admins` séma nem tartalmaz szerepkört.

## Egyetlen szálláshely modell

**IMPLEMENTED:** A séma és a kód nem tartalmaz `properties` vagy hasonló táblát/azonosítót. Minden `booking`, `blocked_period` és `pricing_rule` ugyanahhoz az egy szálláshelyhez tartozik.

**OUT OF SCOPE:** Több ingatlan, több apartman, szobakészlet, csatornánkénti kapacitás és marketplace jellegű működés. Ilyen igény esetén új rendszerhatár- és sémadöntés szükséges; nem kezelhető egyszerű konfigurációként.

## Funkcionális hatókör

### Jelenleg elkészült

- **IMPLEMENTED:** frameworkfüggetlen PHP 8.2+ alkalmazás egyszerű front controllerrel és routerrel;
- **IMPLEMENTED:** `public/` az egyetlen tervezett webes document root;
- **IMPLEMENTED:** publikus, reszponzív, két hónapos naptár build lépés nélküli CSS-sel és natív JavaScripttel;
- **IMPLEMENTED:** `GET /api/availability` fél-nyitott dátumtartománnyal és személyes adat nélküli napi állapotokkal;
- **IMPLEMENTED:** `POST /api/booking/validate` kötelező mező-, e-mail-, hozzájárulás-, tartomány-, horizont- és foglaltságellenőrzéssel, mentés nélkül;
- **IMPLEMENTED:** kizárólag `confirmed` foglalás blokkol; `pending` és `cancelled` nem blokkol; a `blocked_periods` mindig blokkol;
- **IMPLEMENTED:** verziózott, csak előre futó SQL migrációk és idempotens fejlesztői demo seeder;
- **IMPLEMENTED:** PDO natív prepared statementekkel (`ATTR_EMULATE_PREPARES = false`);
- **IMPLEMENTED:** Docker Compose: Apache + PHP 8.2, MySQL 8, Mailpit, opcionális phpMyAdmin és MySQL healthcheck;
- **IMPLEMENTED:** unit-, controller/use-case feature- és feltételes MySQL-integrációs tesztek az availability szabályok egy részére. A router/front controller HTTP dispatch és a booking-validation végpont teljes szerződése még nincs automatizáltan lefedve.

### Tervezett 1.0

- **IMPLEMENTED:** foglalási igény tranzakciós, ismételt availability ellenőrzéssel történő mentése és egyedi referencia képzése;
- **IMPLEMENTED:** admin belépési alap jelszóval, e-mailes 2FA-val és biztonságos sessionnel;
- **IMPLEMENTED:** admin dashboard, lista, részletező, státuszkezelés és blokkolás; kézi admin booking create **PLANNED**;
- **IMPLEMENTED:** közös összetett pricing engine, admin CRUD/preview és immutable snapshot; production értékek **PLANNED**;
- **PLANNED:** SMTP-alapú, naplózott és idempotens e-mail folyamatok;
- **PLANNED:** tokennel védett iCal export, cron alapú import, konfliktuskezelés és auditálás;
- **PLANNED:** cPanel staging/production telepítés, mentés-visszaállítás és monitorozás;
- **PLANNED:** security, accessibility és böngészőszintű regressziós ellenőrzések.

Minden tervezett modul elfogadási feltétele legalább: dokumentált üzleti szabályok, automatizált tesztek, szükség esetén verziózott migráció, hibafolyamat, jogosultság- és naplózási szabály, valamint a kapcsolódó `docs/` fájl frissítése.

## Fő technológiák

| Terület | Választás | Állapot |
|---|---|---|
| Backend | PHP `^8.2`, Composer PSR-4, framework nélkül | **IMPLEMENTED** |
| Web | Apache, `.htaccess`, `public/index.php` front controller | **IMPLEMENTED** |
| Adatbázis | MySQL 8, InnoDB, `utf8mb4`, PDO | **IMPLEMENTED** |
| Frontend | szerveroldali PHP template, natív JavaScript és CSS | **IMPLEMENTED** |
| Tesztelés | PHPUnit 10.5 | **IMPLEMENTED** |
| Fejlesztői környezet | Docker Compose, Mailpit, opcionális phpMyAdmin | **IMPLEMENTED** |
| Production | hagyományos cPanel PHP/MySQL tárhely | **PLANNED** |
| E-mail | cserélhető SMTP absztrakció; 2FA és booking-request sablon/transport | **IMPLEMENTED:** booking outbox + egyszeri commit utáni küldés; retry/admin resend **PLANNED** |
| Naptárintegráció | RFC 5545 alapú iCal | **DEFERRED** |

## cPanel-kompatibilitás

**IMPLEMENTED alap:** a runtime nem igényel Node.js-t vagy alkalmazásszervert; a PHP-kód PSR-4 autoloaddal, PDO-val és Apache document roottal futtatható. A frontend statikus assetjeihez nincs production build.

**PLANNED:** a production eljárásnak rögzítenie kell a `public/` document rootot, a web rooton kívüli `.env`/konfiguráció védelmét, a `composer install --no-dev --optimize-autoloader` lépést, a migrációt, a fájljogosultságokat, a HTTPS-t, az SMTP-t, a cron parancsokat, valamint a rollback és restore próbát. Részletek: [tesztelés és üzemeltetés](10_TESTING_AND_OPERATIONS.md).

## Nem funkcionális követelmények

- **IMPLEMENTED szerver/domain:** minden foglalási nap `DATE`, a PHP domain időzónája `Europe/Budapest`, az időszak `[arrival_date, departure_date)`.
- **PLANNED kliens-hardening:** a JavaScript jelenleg a böngésző helyi `new Date()` értékeit és milliszekundum-különbséget használ; Europe/Budapesttől eltérő kliens-időzóna és DST-határ esetére naptári nap-alapú számítás és regressziós böngészőteszt szükséges.
- **IMPLEMENTED:** titkok `.env`-ből érkeznek; a valódi `.env` nem verziózott.
- **IMPLEMENTED:** értéket tartalmazó jelenlegi SQL lekérdezések prepared statementet használnak.
- **PLANNED:** HTTPS, CSRF, biztonságos session-cookie, rate limiting, audit log, strukturált hibalog és személyesadat-megőrzési rend.
- **PLANNED:** mobil és billentyűzetes használhatóság regressziós ellenőrzése, WCAG-célérték véglegesítése.
- **IMPLEMENTED:** confirmed/blocked ütközést kizáró készletzár és idempotens publikus írás; az átfedő pending igények megengedettek.
- **PLANNED:** visszaállítással rendszeresen ellenőrzött adatbázis-mentés.

> **DECISION REQUIRED:** Rögzíteni kell a rendelkezésre állási célértéket, az RPO/RTO értékeket, a támogatott böngészőket és a kötelező WCAG megfelelési szintet.

## Magas szintű felhasználói utak

### Vendég – jelenlegi

1. **IMPLEMENTED:** megnyitja a `/` oldalt;
2. **IMPLEMENTED:** a böngésző lekéri két hónap napi availability adatait;
3. **IMPLEMENTED:** kiválasztja az érkezést és a távozást;
4. **IMPLEMENTED:** megadja a kapcsolati és létszámadatokat, és külön bepipálja a kötelező adatkezelési és verziózott foglalási szabályzat checkboxot;
5. **IMPLEMENTED:** a szerver újra validálja az adatokat és az időszakot;
6. **IMPLEMENTED:** idempotens `pending` igény, policy- és pricing snapshot készül, majd commit után e-mail-kísérlet indul.

Példa: a `2026-08-01` érkezés és `2026-08-05` távozás négy éjszakát jelent; egy `2026-08-05` napon kezdődő másik foglalás határnapja miatt nincs átfedés.

### Vendég – tervezett 1.0

1. **IMPLEMENTED:** a szerver mentés előtt tranzakcióban ismét ellenőrzi a confirmed/blocked foglalhatóságot;
2. **IMPLEMENTED:** idempotenciakulccsal létrehozza a `pending` igényt és az immutable ár-/policy-pillanatképet;
3. **IMPLEMENTED:** commit után naplózott visszaigazolást küld a vendégnek;
4. **IMPLEMENTED:** az admin megerősíti, elutasítja, lemondja vagy érvényteleníti az igényt;
5. **IMPLEMENTED:** minden státuszváltás előzménybe és audit logba kerül.

### Admin – tervezett 1.0

1. **IMPLEMENTED:** e-mail + jelszó után egyszer használatos e-mailes kóddal hitelesít;
2. **IMPLEMENTED:** biztonságos sessionből megnyitja a dashboardot;
3. **IMPLEMENTED:** megtekinti és kezeli a foglalásokat, blokkolásokat és árakat; külső integrációk **PLANNED**;
4. **IMPLEMENTED:** kijelentkezéskor a session visszavonódik.

## Nem tervezett vagy későbbre halasztott elemek

- **OUT OF SCOPE 1.0:** több szálláshely és több pénznem;
- **OUT OF SCOPE 1.0:** natív mobilalkalmazás;
- **OUT OF SCOPE 1.0:** marketplace, channel manager vagy teljes PMS;
- **OUT OF SCOPE 1.0:** Node.js production runtime;
- **DEFERRED:** TOTP második faktor; az 1.0 e-mailes 2FA-ra készül;
- **DECISION REQUIRED:** online fizetés, előlegkezelés és számlázóintegráció üzleti hatóköre;
- **DECISION REQUIRED:** vendégfiók és önkiszolgáló módosítás/lemondás szükséges-e.

## Release-mérföldkövek

| Mérföldkő | Tartalom | Állapot / elfogadás |
|---|---|---|
| Technikai alap | MVC/domain-service alap, Docker, migráció, séma, interval tesztek | **IMPLEMENTED** |
| Publikus naptár | availability API, két hónapos UI, mentés nélküli validáció | **IMPLEMENTED** |
| Rendszerspecifikáció | jelenlegi és 1.0 állapot verziózott dokumentációja | **PLANNED** ebben a dokumentációs sprintben |
| Admin biztonsági alap | jelszó + e-mailes 2FA, session, CSRF, rate limit, audit | **IMPLEMENTED**, release smoke szükséges |
| Foglalási mag | mentés, konkurenciavédelem, státuszok, admin kezelés | **IMPLEMENTED** |
| Kereskedelmi folyamat | összetett pricing/policy/cancellation snapshot, outbox és SMTP-kísérlet | **IMPLEMENTED**; automatikus retry **PLANNED** |
| Integráció és release | iCal, staging hardening, backup/restore, production smoke | **PLANNED** |

Az 1.0 kiadás csak akkor fogadható el, ha a kritikus üzleti utak automatizált tesztjei sikeresek, a security kontrollok ellenőrzöttek, a staging telepítés és egy restore próba dokumentáltan lefutott, és nem maradt kiemelt `DECISION REQUIRED` kérdés, amely az adatok vagy az árképzés helyességét befolyásolja.

## Kapcsolódó dokumentumok

- [Architektúra](01_ARCHITECTURE.md)
- [Adatbázis- és domainmodell](02_DATABASE_AND_DOMAIN_MODEL.md)
- [Publikus foglalási folyamat](03_PUBLIC_BOOKING_FLOW.md)
- [Admin és hitelesítés](04_ADMIN_AND_AUTHENTICATION.md)
- [Árképzés](05_PRICING.md)
- [E-mail folyamatok](06_EMAIL_WORKFLOWS.md)
- [iCal szinkron](07_ICAL_SYNC.md)
- [API referencia](08_API_REFERENCE.md)
- [Biztonság](09_SECURITY.md)
- [Tesztelés és üzemeltetés](10_TESTING_AND_OPERATIONS.md)
- [Roadmap és döntési napló](11_ROADMAP_AND_DECISIONS.md)

## Sprint 3 állapot – admin authentication foundation

**IMPLEMENTED komponensek:** az admin credential ellenőrzés, e-mailes 2FA, csúszó idle session, CSRF, rate limit, audit persistence, SMTP absztrakció és minimális admin UI kódja elkészült. A `008_create_admin_authentication_tables.sql` létrehozza a szükséges auth-táblákat.

**IMPLEMENTED:** admin üzleti felület, jóváhagyás, pricing CRUD/preview, publikus booking create és snapshot/outbox. **PLANNED:** iCal, online fizetés és általános automatikus e-mail retry; release előtt HTTP/Mailpit smoke szükséges.

**DECISION REQUIRED:** abszolút session maximum; production SMTP port/titkosítás/auth/feladó; végleges rate-limit küszöbök.
