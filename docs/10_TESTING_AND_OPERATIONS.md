# Tesztelés és üzemeltetés

**Állapot:** IMPLEMENTED fejlesztői alapok és PLANNED staging/production folyamatok
**Utolsó ellenőrzött commit:** `9adc564`

## Igazolt kiindulási állapot

**IMPLEMENTED:** A dokumentációs sprint előtti, `9adc564` commiton végzett futtatás eredménye **16 teszt, 47 assertion, sikeres**. A suite PHPUnit 10.5 alapú, és unit-, feature-, valamint feltételes MySQL-integrációs teszteket tartalmaz. Ez az eredmény nem igazolja a lent felsorolt jövőbeli modulokat.

**IMPLEMENTED:** Docker Compose fejlesztői környezetben PHP 8.2 + Apache, MySQL 8 healthcheckkel, Mailpit és opcionális phpMyAdmin érhető el. A `public/` az egyetlen webes document root, a frontend natív CSS/JavaScript, production build és Node.js runtime nélkül.

Minden további, ebben a dokumentumban előírt új teszt és production eljárás **PLANNED**, hacsak külön nincs IMPLEMENTED-ként jelölve. A jelenlegi architektúrát az [architektúra](01_ARCHITECTURE.md), a biztonsági kontrollokat a [biztonság](09_SECURITY.md) részletezi.

## Tesztpiramis

| Szint | Cél és példák | Jelenlegi állapot | 1.0 elfogadás |
|---|---|---|---|
| Unit | tiszta domain szabályok: fél-nyitott intervallum, napállapot, validáció | **IMPLEMENTED:** availability, booking state machine, összetett pricing, cancellation, auth/2FA és e-mail | **PLANNED:** iCal |
| Integration | PDO repository, migráció, tranzakció, MySQL-specifikus viselkedés | **IMPLEMENTED** availability read repositoryra, DB elérhetőség esetén | **PLANNED:** write race, új táblák, rollback/restore utáni séma |
| Feature | controller és use case együtt, kontrollált függőségekkel | **IMPLEMENTED:** availability, admin auth/booking/pricing, policy checkbox és booking create HTTP-szerződés; teljes front-controller dispatch nincs automatizálva | **PLANNED:** iCal műveletek |
| API contract | metódus, schema, státuszkód, auth, PII és hibaválasz | részben **IMPLEMENTED** az availability válaszra | **PLANNED:** minden [API](08_API_REFERENCE.md) végpontra pozitív/negatív szerződés, különösen `POST /api/booking/validate` |
| Browser | valós böngésző: naptárkijelölés, űrlap, admin flow | **PLANNED** automatizálás | kritikus desktop/mobil utak és JavaScript hibák ellenőrzése |
| Accessibility | billentyűzet, fókusz, név/szerep/érték, kontraszt, zoom, screen reader | **PLANNED** formális ellenőrzés | automata audit + manuális billentyűzetes és screen reader smoke |
| Security | authz, CSRF, XSS, injection, rate limit, SSRF, secret/PII leakage | **PLANNED** külön suite | a [threat model](09_SECURITY.md) kiemelt kontrolljai igazoltak |
| Staging smoke | productionhoz hasonló cPanel, HTTPS, SMTP, cron, migráció | **PLANNED** | minden release előtt dokumentált siker |
| Production smoke | read-only vagy visszafordítható minimális ellenőrzés | **PLANNED** | deploy után health, publikus oldal, DB és cron állapot; teszt PII nélkül |

A piramis alsó szintjein sok gyors, determinisztikus teszt szükséges, feljebb kevesebb, de valós integrációt ellenőrző teszt. A staging/production smoke nem helyettesíti az automatizált regressziós suite-ot.

## Kritikus üzleti és technikai tesztek

### Dátum és availability

- **IMPLEMENTED:** egymást követő `[arrival, departure)` foglalások nem ütköznek; azonos napi és közrefogott időszak ütközik; múltbeli érkezés és nem pozitív időtartam elutasítható.
- **IMPLEMENTED:** turnover és napi availability állapotok közvetlenül unit szinten teszteltek; az availability JSON alapszerződés controller/use-case feature tesztet kapott; az integrációs teszt a PDO repository `confirmed`/`pending`/`cancelled` szűrését és PII-mentes kimenetét ellenőrzi.
- **PLANNED – P1 teszthiány:** a `POST /api/booking/validate` kötelező mezői, `privacy`, Content-Type és hibás JSON, minimum/maximum éjszaka, booking horizon, foglaltsági elutasítás és 500-as ág automatizált szerződés- és HTTP tesztje. Ez a jelenlegi üzleti szabályok teljes lefedéséhez szükséges.
- **PLANNED:** Europe/Budapest dátumhatár, szökőnap, hónap-/évváltás és booking horizon szélsőértékek teljes mátrixa.

Példa: `[2026-08-01, 2026-08-05)` és `[2026-08-05, 2026-08-08)` nem ütközik; `[2026-08-04, 2026-08-06)` ütközik.

### Pricing

**IMPLEMENTED:** stay-length, három base unit, szezon/hétvége, fix díj, IFA, konfigurálható mentesség, azonos prioritású konfliktus, HUF HALF_UP és immutable snapshot. **PLANNED:** production értékek és jogilag jóváhagyott mentességi kategóriák.

### Párhuzamos foglalás és idempotencia

**IMPLEMENTED:** két külön kérés azonos időszakra külön kulccsal pendingként sikerülhet; confirmed vagy blocked átfedés elutasított. Nincs részleges booking/child/history/snapshot/outbox rekord. Azonos idempotenciakulcs ismétlése ugyanazt az eredményt adja, eltérő payload `409`.

### Státuszváltás

**IMPLEMENTED:** minden engedélyezett és tiltott átmenet, kétprocesszes konkurens confirm, status history, audit, cancellation snapshot és e-mail tesztelt.

### Admin és 2FA

**IMPLEMENTED komponensszinten:** helyes/hibás jelszó, ismeretlen felhasználó időzítésének kiegyenlítése, kódhash, 10 perces lejárat, maximum 5 próbálkozás, resend limit, egyszer használhatóság, session rotation, logout/revocation, CSRF, rate limit és lockout. **PLANNED:** teljes böngésző- és HTTPS staging regresszió. Részletek: [admin és hitelesítés](04_ADMIN_AND_AUTHENTICATION.md).

### iCal import/export

**PLANNED:** RFC 5545 fixture-ek, `DATE DTSTART`, exkluzív `DTEND`, UID/SEQUENCE/DTSTAMP, cancellation, raw hash, last seen, eltűnési türelmi idő, loop prevention, rosszindulatú/túlméretes ICS, SSRF, konfliktus és tokenrotáció. Részletek: [iCal szinkron](07_ICAL_SYNC.md).

### E-mail idempotencia

**PLANNED:** egy domain-esemény és sablonverzió legfeljebb egy aktív küldési rekordot hoz létre; timeout utáni retry nem duplikál; permanens hiba és admin újraküldés auditált; HTML/plain-text tartalom escapingje és header injection védelme tesztelt. Mailpit csak fejlesztői/staging tesztcímzettekkel használható. Részletek: [e-mail folyamatok](06_EMAIL_WORKFLOWS.md).

### Cron újrafutás

**PLANNED:** ugyanazon job ismételt és párhuzamos futása idempotens; lock timeout után helyreáll; részleges hiba után a következő futás folytatható; hibánál nem nulla exit code és secretmentes napló keletkezik.

### Backup és restore

**PLANNED:** titkosított mentésből izolált környezetbe visszaállítás, migrációszint, rekordszám/checksum és kritikus üzleti olvasások ellenőrzése. A teszt igazolja a megállapított RPO/RTO célt, majd a visszaállított személyes adat biztonságosan törlendő.

## Helyi Docker fejlesztés

**IMPLEMENTED:** A következő parancsok PowerShell-kompatibilisek:

```powershell
Copy-Item .env.example .env
docker compose up -d --build
docker compose ps
docker compose exec app composer db:check
docker compose exec app composer migrate
docker compose exec app vendor/bin/phpunit
```

A `.env` értékeit helyben kell beállítani, de a fájl nem commitolható. A `db` állapota legyen `healthy`. A migráció ismételt futása idempotens. A fejlesztői demo adatok csak `development`, `local` vagy `testing` környezetben tölthetők:

```powershell
docker compose exec app composer seed:demo
```

> **FIGYELEM:** A következő parancs törli a Docker volume-ban tárolt helyi adatbázist. Csak eldobható fejlesztői adaton használható, stagingen és productionben tilos.

```powershell
docker compose down -v
```

Részletes hitelesítési helyreállítás a gyökér [README](../README.md) fájlban található.

## Környezetek

| Környezet | Cél | Adat és integráció | Minimum kontroll |
|---|---|---|---|
| Localhost Docker | fejlesztés és automatizált teszt | szintetikus adatok, Mailpit | `.env` kizárva, DB healthy, debug csak helyben |
| Staging cPanel | release candidate és valós hosting-korlátok | anonimizált/szintetikus adat, sandbox SMTP/iCal | külön DB és secret, HTTPS, productionhoz azonos PHP/ext/cron |
| Production cPanel | valódi felhasználás | valódi PII, élő SMTP és iCal | least privilege, debug off, backup, monitoring, audit, jóváhagyott deploy |

Staging és production nem oszthat adatbázist, SMTP credentialt, iCal tokent, session/cookie domaint vagy backup könyvtárat. Production adata stagingre csak dokumentált, anonimizált eljárással kerülhet.

## Release és cPanel telepítés

### Előfeltételek

**PLANNED:** PHP 8.2+ CLI és web SAPI azonos támogatott minor verzióval; PDO MySQL és a tényleges függőségekhez szükséges extensionök; MySQL 8 kompatibilitás; Composer 2 vagy előre elkészített vendor artifact; Apache document root kizárólag `public/`; HTTPS; cPanel cron; írható, web rooton kívüli log/átmeneti könyvtár.

Node.js nem production runtime-függőség, és frontend build lépés nincs. Production Composer telepítés:

```text
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
```

Ha a tárhelyen Composer nem futtatható, ugyanazon PHP-platformkövetelményekkel ellenőrzött CI/build artifact tartalmazza a `vendor/` könyvtárat. Secret és valódi `.env` nem kerülhet az artifactba.

### Telepítési sorrend

**PLANNED:**

1. Jóváhagyott commit/tag és sikeres CI eredmény rögzítése; karbantartási/rollback felelős kijelölése.
2. Alkalmazásfájlok feltöltése új, verziózott release könyvtárba; a `public/` document root beállítás ellenőrzése.
3. Composer production függőségek telepítése vagy ellenőrzött artifact kibontása.
4. Web rooton kívüli `.env` létrehozása jogosultságszűkítéssel; DB, `APP_ENV=production`, `APP_DEBUG=false`, `APP_TIMEZONE=Europe/Budapest`, SMTP és későbbi iCal secret beállítása. Értéket nem szabad terminálba vagy naplóba írni.
5. Adatbázismentés és visszaállíthatóság ellenőrzése, majd `php bin/migrate.php`. A migráció csak előrefelé fut, ezért előzetes kompatibilitási terv kötelező.
6. Szükséges web rooton kívüli log/cache könyvtár létrehozása legszűkebb jogosultsággal; általános `777` tilos.
7. HTTPS átirányítás, security headerek, secure cookie, SMTP és cron beállítása.
8. Atomikus release-váltás vagy cPanel által támogatott, rövid karbantartási ablak; opcache ürítése a hosting lehetősége szerint.
9. Staging/production smoke és logellenőrzés; sikertelenségnél rollback.

Migrációt nem szabad automatikusan minden webkéréskor vagy app induláskor futtatni. A deployment egyszer, kontrollált CLI lépésként hajtja végre.

### Cron

**PLANNED:** A cPanel cron abszolút PHP- és scriptútvonalat használ, nem függ working directorytól, Dockertől vagy PowerShelltől:

```text
/usr/local/bin/php /home/CPANEL_USER/app/bin/ical-sync.php
```

Ugyanez a minta alkalmazandó e-mail retry és más ütemezett job belépési pontjára. A pontos PHP útvonalat a tárhelyszolgáltató/cPanel adja. Secret nem lehet parancssori argumentum; nem nulla exit code és strukturált, rotált napló szükséges. A példa iCal script **PLANNED**, jelenleg nem létezik.

## Backup és restore

**PLANNED backup minimum:**

- automatikus, időbélyegzett MySQL mentés és az alkalmazás által feltöltött tartós fájlok mentése;
- `.env`/secretek külön, hozzáférésvezérelt secret-kezelése; ne kerüljenek alkalmazás-archívumba;
- átvitel és tárolás titkosítva, a tárhelyfióktól elkülönített másolat;
- sikeresség, méret és checksum monitorozása; retenció az adatmegőrzési döntés szerint;
- rendszeres, dokumentált restore próba izolált környezetben.

**PLANNED restore sorrend:** incidens és kívánt időpont azonosítása; tiszta izolált cél; megfelelő kódrelease visszaállítása; DB és fájlok restore; secret bekötése; séma/migrációszint, integritás és smoke teszt; csak jóváhagyás után forgalom; ideiglenes másolat biztonságos törlése.

> **DECISION REQUIRED:** RPO, RTO, mentési gyakoriság, megőrzési idő és restore-próba gyakorisága tulajdonosi/üzemeltetői jóváhagyást igényel.

## Rollback

**PLANNED:** A kód rollbackje az előző, változatlan release könyvtárra történő atomikus visszaváltás. A DB rollback nem alapozható automatikus down migrationre, mert a jelenlegi migrátor csak előrefelé fut. Minden sémaváltozásnak expand/contract kompatibilitási tervet kell adnia:

- előbb visszafelé kompatibilis új oszlop/tábla;
- kódváltás és adat backfill külön kontrollált lépésben;
- destruktív eltávolítás csak későbbi release-ben, igazolt mentés után.

Adatvesztő hiba esetén a jóváhagyott backup restore eljárás használható; production `docker compose down -v`, kézi táblaeldobás vagy ellenőrizetlen SQL tilos. Rollback után health, migrációszint, publikus oldal és kritikus read flow ellenőrzendő.

## Monitoring és naplózás

**PLANNED:** Monitorozandó legalább:

- HTTPS health endpoint elérhetősége és válaszideje;
- PHP fatális hibák, HTTP 5xx arány, DB kapcsolati hibák és tárhely telítettsége;
- migrációs eltérés release-kor;
- cron utolsó sikeres futása, futási idő, lock és egymást követő hibák;
- SMTP sikertelenség/retry queue, iCal forrás frissessége és konfliktusok;
- backup frissessége, mérete, checksumja és legutóbbi restore-próba;
- admin auth rate-limit/lockout anomália.

A napló strukturált időpontot (UTC technikai eseményhez, üzleti dátumnál Europe/Budapest kontextust), környezetet, severityt, eseménytípust és korrelációs azonosítót tartalmazzon. Jelszó, session, CSRF/2FA kód, teljes iCal token/URL, SMTP credential, nyers request body és szükségtelen PII tilos. Rotáció, retenció és adminhozzáférés dokumentálandó.

Riasztási útvonal és ügyeleti felelős szükséges kritikus health/DB/backup hibára, tartós SMTP/iCal hibára és biztonsági eseményre. A health endpoint ne fedjen fel verziót, credentialt vagy belső hibát.

## Staging és production smoke

**PLANNED staging smoke:**

1. HTTPS és security headerek;
2. `/health`, `/`, availability API és mentés nélküli validáció;
3. DB kapcsolat, migráció ismételt futásának idempotenciája;
4. később admin login/2FA tesztfiókkal, SMTP sandbox, iCal fixture és cron kézi futtatása;
5. fájljogosultság, web root izoláció, debug és secretmentes log;
6. backup készítés és restore próba.

**PLANNED production smoke:** kizárólag visszafordítható/read-only ellenőrzés szintetikus PII nélkül: health, publikus oldal és availability, release/migráció állapot belső ellenőrzése, cron/backup legutóbbi siker. Valódi foglalást, e-mailt vagy iCal módosítást csak előre engedélyezett tesztfolyamat végezhet.

## Elfogadási feltételek

Az 1.0 üzemeltetési készültség feltétele:

1. minden üzleti szabály unit és megfelelő magasabb szintű tesztet kapott;
2. a kritikus race, 2FA, pricing, e-mail idempotencia, iCal és cron tesztek automatizáltan sikeresek;
3. a teljes suite tiszta adatbázison, kétszeri migráció után reprodukálható;
4. staging cPanelen production-azonos PHP/ext/HTTPS/cron/SMTP konfigurációval sikeres smoke futott;
5. production artifact Node és dev Composer csomag nélkül telepíthető;
6. backup restore próbája teljesült és a jóváhagyott RPO/RTO dokumentált;
7. rollbacket legalább stagingen elpróbálták;
8. monitoring, riasztási felelős, naplóretenció és incidenseljárás rögzített;
9. a [biztonsági](09_SECURITY.md) P0 kontrollok ellenőrzöttek, nincs secret vagy szükségtelen PII a logokban.

## Kapcsolódó dokumentumok

- [Dokumentációs index](README.md)
- [Projektáttekintés](00_PROJECT_OVERVIEW.md)
- [Architektúra](01_ARCHITECTURE.md)
- [Adatbázis- és domainmodell](02_DATABASE_AND_DOMAIN_MODEL.md)
- [Publikus foglalási folyamat](03_PUBLIC_BOOKING_FLOW.md)
- [Admin és hitelesítés](04_ADMIN_AND_AUTHENTICATION.md)
- [Árképzés](05_PRICING.md)
- [E-mail folyamatok](06_EMAIL_WORKFLOWS.md)
- [iCal szinkron](07_ICAL_SYNC.md)
- [API referencia](08_API_REFERENCE.md)
- [Biztonság](09_SECURITY.md)
- [Roadmap és döntési napló](11_ROADMAP_AND_DECISIONS.md)

## Sprint 3 ellenőrzési mátrix

**AUTOMATIZÁLTAN LEFEDETT komponensszinten:** jelszó siker/hiba/inaktív/ismeretlen fiók és rehash; 2FA generálás, hash, lejárat, öt próbálkozás, egyszer használat és resend; pending/authenticated session, rotáció, idle lejárat és logout; CSRF; rate limit és audit metadata; PDO auth persistence; SMTP konfiguráció, MIME-renderelés és transport-hibák; admin controllerek CSRF- és auth-ágai.

**RELEASE ELLENŐRZÉS:** friss Docker volume-on migráció kétszer; teljes PHPUnit suite; `composer admin:create`; Mailpitben valódi 2FA levél; sikeres login → 2FA → dashboard → logout; hibás jelszó/kód, lejárt kód, ötödik hiba, túl korai resend, rate limit, idle timeout és közvetlen `/admin` elérés.

**PLANNED / környezetfüggő:** HTTPS staging cookie-attribútum, production SMTP/TLS, cPanel session-viselkedés, böngésző- és accessibility audit. Abszolút session lifetime teszt csak tulajdonosi döntés után írható.

### cPanel Sprint 3 konfiguráció

Productionben kötelező a hosszú, véletlen `AUTH_RATE_LIMIT_PEPPER`, a HTTPS miatt `SESSION_COOKIE_SECURE=true`, valamint a tulajdonos által jóváhagyott `MAIL_HOST`, `MAIL_PORT`, `MAIL_ENCRYPTION`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_EMAIL` és `MAIL_FROM_NAME`. Secret nem kerülhet Gitbe, webrootba, parancssori argumentumba vagy deployment naplóba.

Az első admin a `composer admin:create` paranccsal hozható létre. Az e-mail az `ADMIN_CREATE_EMAIL`, a legalább 12 karakteres jelszót tartalmazó átmeneti fájl abszolút útvonala az `ADMIN_CREATE_PASSWORD_FILE` környezeti változóban adandó át. A plaintext fájl legyen repositoryn és `public/`-on kívül, minimális jogosultságú, és siker után törlendő. A parancs nem fogad jelszót CLI argumentumként.

Release előtt igazolni kell, hogy a cPanel PHP sessionkönyvtára nem osztott más tenanttal, a cookie `Secure`, `HttpOnly`, `SameSite=Lax`, a document root kizárólag `public/`, és az SMTP tanúsítvány-ellenőrzése aktív. A production SMTP paraméterek nyitott döntése miatt ez jelenleg release-kapu.

## Sprint 4 ellenőrzési mátrix

**AUTOMATIZÁLTAN LEFEDETT:** booking request validáció és kanonikus hash; pending/confirmed overlap szabály; pricing rule kiválasztás, éjszakaszám és immutable snapshot; tranzakciós booking/history/child age/idempotencia/snapshot/outbox mentés és rollback; idempotens replay és payload-konfliktus; confirmed/blocked konfliktus; API Content-Type, JSON, body limit, Origin, rate limit és PII-mentes válasz; HTML/plain booking e-mail, SMTP-hiba utáni booking-megőrzés; branding audit.

**HELYI POWERSHELL RELEASE ELLENŐRZÉS:**

```powershell
docker compose up -d --build
docker compose ps
docker compose exec app composer db:check
docker compose exec app composer migrate
docker compose exec app composer migrate
docker compose exec app composer seed:demo
docker compose exec app vendor/bin/phpunit
git diff --check
git status --short
```

Ezután a [booking API](08_API_REFERENCE.md#post-apibookings--implemented) PowerShell smoke-ja és a `http://localhost:8025` Mailpit felület ellenőrzendő. A seedelt ár szemléltető, productionben tilos változtatás nélkül használni.

**PLANNED / TECHNICAL DEBT:** valódi párhuzamos production-terhelési próba; stale `processing` outbox reclaim és retry cron; production SMTP/TLS kézbesíthetőség; teljes böngésző- és accessibility audit; admin jóváhagyáskor confirmed race teszt.

## Sprint 5 PowerShell release ellenőrzés

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

## Sprint 6 ellenőrzési mátrix

**AUTOMATIZÁLTAN LEFEDETT:** 1/2/3/4–6/7+ éjszakás sávok; mindhárom alapegység; seasonal/weekend sorrend; priority és silent-conflict tiltás; fix díj, IFA és konfigurált exemption; HALF_UP kerekítés és snapshot immutability; policy kötelezőség, version/URL snapshot és tranzakciós rollback; pricing CRUD active/inactive/conflict; preview és booking közös engine adaptere; a pontos 7/8/6/0 napos lemondási határok; immutable accommodation-fee alap; cancellation persistence/history/audit/outbox; admin auth/CSRF/no-store/IDOR/numeric validation; policy/detail/e-mail/branding megjelenítés.

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

**PLANNED / KÖRNYEZETFÜGGŐ:** production ár- és adókonfiguráció jóváhagyása; production SMTP/TLS smoke; teljes interaktív browser/accessibility audit; online pénzügyi beszedés nincs a Sprint 6 hatókörében.

Az automatikus concurrency teszt két PHP processzel ellenőrzi az átfedő pending confirmot. Mailpitben request/confirmed/rejected/cancelled levél, címzett, tárgy, referencia, státusz, összeg és az admin note hiánya ellenőrzendő.
