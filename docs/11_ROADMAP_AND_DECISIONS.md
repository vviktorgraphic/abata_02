# Roadmap és döntési napló

**Állapot:** Phase 10 automatikus e-mail outbox retry/stale recovery IMPLEMENTED, környezetfüggő release-kapuk nyitottak
**Utolsó ellenőrzött állapot:** `release/rc2` Phase 10 munkafa, 2026-10-12

## Kiindulási helyzet

**IMPLEMENTED:** technikai alap, MySQL séma, migrációfuttató, publikus két hónapos naptár, read-only availability API, mentés nélküli booking-validáció és demo seeder.
**IMPLEMENTED:** admin authentication/2FA, admin booking management, tranzakciós booking persistence, legacy és személyalapú közös pricing engine, immutable snapshotok, booking/státusz outbox, automatikus e-mail retry/stale recovery, kézi és automatikus lock/retry/grace iCal import és tokenes export, továbbá production runbookok. **PLANNED / RELEASE GATE:** jóváhagyott jogi/production pricing tartalom, környezetben végrehajtott production/staging smoke és cronaktiválás.
**IMPLEMENTED Phase 9:** teljes admin sessionnel védett, pending-only kézi foglalásrögzítés aláírt read-only előnézettel és a publikus create-tel közös inventory/idempotencia/pricing tranzakcióval. Az admin csatorna nem fabrikál publikus jogi elfogadást és nem küld automatikus create e-mailt.

## Tervezett sprintek

1. **Admin authentication és e-mailes 2FA.** Előfeltétel az admin session- és auditmodell. Elfogadás: rate limitelt jelszó + egyszer használatos kód, session rotation, biztonságos logout, auditált hibafolyamatok. Lásd [admin és hitelesítés](04_ADMIN_AND_AUTHENTICATION.md).
2. **Read-only admin dashboard és foglaláslista — IMPLEMENTED.** A dashboard Budapest szerinti napi operatív összesítést, mai érkező/távozó listát és hét napos közelgő érkezést ad; a foglaláslista lapozható és szűrhető. PII kizárólag jogosult adminnak látható, minden dinamikus lekérdezési érték prepared statementet használ.
3. **Publikus foglalásmentés — IMPLEMENTED.** Tranzakcióban újraellenőrzi a pending/confirmed/blocked rendelkezésre állást és idempotenciakulcsot használ. Átfedő pending nem hozható létre; azonos kulcs csak egy bookingot eredményez. Lásd [publikus flow](03_PUBLIC_BOOKING_FLOW.md).
4. **Foglalási státuszkezelés.** Elfogadás: definiált állapotgép, tiltott átmenetek elutasítása, minden változás audit- és status history rekord.
5. **Árkalkuláció és snapshot.** A foglalásmentés után következik, hogy az ár a végleges vendég- és dátumadatokhoz köthető legyen. Elfogadás: determinisztikus kalkuláció, HUF-kerekítés és megváltoztathatatlan pillanatkép. Lásd [árképzés](05_PRICING.md).
6. **SMTP és e-mail folyamatok.** Elfogadás: queue-szerű outbox/log, idempotens események, retry és admin újraküldés; közvetlen `mail()` nincs. Lásd [e-mail folyamatok](06_EMAIL_WORKFLOWS.md).
7. **iCal import/export.** A stabil belső státusz- és ütközésmodell után. Elfogadás: tokenes feed, idempotens cron import, loop prevention és manuálisan feloldható konfliktusok. Lásd [iCal](07_ICAL_SYNC.md).
8. **Staging hardening.** cPanel staging, HTTPS, security headerek, cron, SMTP, backup/restore próba és smoke teszt.
9. **Production release 1.0.** Csak lezárt P0 döntések, sikeres staging restore és biztonsági ellenőrzés után.

## ADR jellegű döntések

| ADR | Állapot | Döntés | Következmény |
|---|---|---|---|
| ADR-001 | IMPLEMENTED | Frameworkfüggetlen PHP 8.2+ | Kis deployment-felület; saját routing/DI/hibakezelés technikai adósságként kezelendő. |
| ADR-002 | IMPLEMENTED | cPanel-kompatibilis célkörnyezet | `public/` document root, Composer artifact, cron-kompatibilis CLI; konténer productionben nem kötelező. |
| ADR-003 | IMPLEMENTED | Node.js nélküli production runtime | Natív, build nélküli JS/CSS; frontend framework bevezetése külön döntés lenne. |
| ADR-004 | IMPLEMENTED | Foglalási napok MySQL `DATE` típusban | Időpont helyett helyi naptári nap; alkalmazási időzóna `Europe/Budapest`. |
| ADR-005 | IMPLEMENTED | Fél-nyitott `[arrival, departure)` intervallum | Azonos napi turnover ütközés nélkül modellezhető. |
| ADR-006 | IMPLEMENTED | Egyetlen szálláshely | Nincs property azonosító a jelenlegi sémában; multi-property **OUT OF SCOPE** 1.0-ban. |
| ADR-007 | IMPLEMENTED | Repository rétegek read és booking write oldalon | A booking create tranzakciós PDO adapterrel, application/domain határral működik. |
| ADR-008 | IMPLEMENTED | SMTP transport és booking e-mail outbox/log | Közvetlen `mail()` nincs; atomi claim, öt próbás automatikus retry, 15 perces stale recovery és capelt meglévő manuális retry működik. Production SMTP/cron, bounce/complaint és új rekordos resend külön kapu. |
| ADR-009 | IMPLEMENTED | E-mailes 2FA az 1.0-ban | TOTP bővíthetőség megmarad, de TOTP **DEFERRED**. |
| ADR-010 | IMPLEMENTED | Query-tokenes iCal export feed | A token hashként tárolt, rotálható capability secret; a feed PII nélkül `pending` és `confirmed` bookingot exportál. |
| ADR-011 | IMPLEMENTED alap | Importált iCal esemény külön entitás | Nem keverhető belső bookinggal; forrás és UID alapján idempotensen külön blocked periodhoz kapcsolódik. Eltűnés/grace PLANNED. |
| ADR-012 | IMPLEMENTED | Megváltoztathatatlan ár-pillanatkép | Későbbi árszabály-változás nem írja át a korábbi booking árát. |
| ADR-013 | IMPLEMENTED / Phase 1B SUPERSEDED | Pending blokkol és nem jár le automatikusan | Pending és confirmed blokkol, lezárt státuszok nem; párhuzamos átfedő pending nem engedett. |
| ADR-014 | IMPLEMENTED | Bookinghoz kötött, időkorlát nélkül megőrzött idempotencia | Azonos kulcs/payload replay; eltérő payload `409`; cleanup nincs. |
| ADR-015 | IMPLEMENTED | SMTP csak booking commit után | Az outbox a booking tranzakció része, a hálózati küldés nem; SMTP-hiba nem törli a bookingot. |

## Nyitott üzleti kérdések

### P0 – implementációt blokkol

1. **DECISION REQUIRED:** konkrét ársávok, alapegység (személy/éj vagy szállás/éj) és prioritás.
2. **DECISION REQUIRED:** gyermekkor-kategóriák, szorzók és az életkor referencia-időpontja.
3. **DECISION REQUIRED:** minimum/maximum vendégszám és csecsemők beszámítása.
4. **DECISION REQUIRED:** idegenforgalmi adó szabálya, mentességek, kerekítés és külön megjelenítés.
5. **DECISION REQUIRED:** előleg összege/százaléka és az elfogadás jelentése online fizetés nélkül.
6. **DECISION REQUIRED:** lemondási szabály és engedélyezett státuszátmenetek.
7. **RESOLVED / Phase 1B:** a `pending` azonnal blokkol és admin beavatkozásig, automatikus lejárat nélkül megmarad.
8. **DECISION REQUIRED:** adatmegőrzési és törlési idők booking, vendég, audit, e-mail és backup adatokra.

### P1 – modul előtt lezárandó

1. **DECISION REQUIRED:** szezonális és hétvégi felár együttalkalmazási sorrendje.
2. **DECISION REQUIRED:** takarítási és más fix díjak feltételei.
3. **DECISION REQUIRED:** kedvezménytípusok és admin felülírás korlátai.
4. **RESOLVED:** elsődleges iCal források Google Calendar és Szallas.hu.
5. **RESOLVED / Phase 1B:** `pending` és `confirmed` foglalás exportálódik stabil booking UID-val.
6. **DECISION REQUIRED:** admin session abszolút lejáratának production értéke. A konfigurálható idle lejárat minimuma és alapértéke 1800 másodperc.
7. **RESOLVED:** a 2FA kódérvényesség 10 perc.
8. **DECISION REQUIRED:** SMTP szolgáltató, feladó domainek és bounce-kezelés.

### P2 – 1.0 előtt pontosítandó

1. **DECISION REQUIRED:** érkezés előtti emlékeztető engedélyezése és időzítése.
2. **DECISION REQUIRED:** audit- és üzemeltetési naplók hozzáférési köre.
3. **DECISION REQUIRED:** restore point objective és recovery time objective.

## Kifejezetten halasztott vagy kizárt elemek

- **DEFERRED:** TOTP 2FA, online fizetés és automatikus revenue-management.
- **OUT OF SCOPE:** több szálláshely, marketplace, vendégfiók és natív mobilalkalmazás az 1.0-ban.

## Döntésrögzítési folyamat

Tulajdonosi döntés után ebben a fájlban dátummal, indoklással és érintett dokumentumlinkekkel új ADR vagy döntési bejegyzés készül. Ugyanabban a PR-ban frissítendő a kapcsolódó specifikáció és – ha már implementált – a teszt. További kontrollok: [biztonság](09_SECURITY.md) és [tesztelés/üzemeltetés](10_TESTING_AND_OPERATIONS.md).

## Sprint 3 teljesítési állapot

Az admin authentication foundation komponensei **IMPLEMENTED** állapotúak: credential check, e-mailes 2FA, session/CSRF, rate limiting, audit persistence, mailer/SMTP, migráció és minimális auth UI. A végleges elfogadás feltétele a composition root bekötése, friss adatbázisos migráció, teljes teszt és Mailpit/browser smoke.

Az admin booking CRUD, a legacy/person pricing admin/engine, a kézi és automatikus iCal import/tokenes export, valamint az általános automatikus e-mail retry rendszer **IMPLEMENTED**. A production cronok aktiválása környezeti lépés.

Nyitott kapuk: abszolút session maximum; production SMTP port/TLS/auth/feladó; végleges rate-limit küszöbök és retention.

## Sprint 4 teljesítési állapot

**IMPLEMENTED Sprint 4 történeti alap, Phase 1B-ben felülírva:** `POST /api/bookings`, tranzakciós készletzár, idempotencia, gyermekéletkorok és immutable snapshot/outbox. A Phase 1B óta a pending is blokkol; a Sprint 6 ezt közös összetett HUF pricing engine-re bővítette.

**IMPLEMENTED a későbbi sprintekben:** admin approval/list/detail, legacy/person pricing, kézi/automatikus iCal import/tokenes export és outbox retry/stale claim recovery. **PLANNED:** production pricing értékek és online fizetés.

## Sprint 5 teljesítési állapot

**IMPLEMENTED:** admin lista/részlet, explicit state machine, tranzakciós státuszváltás/history/audit/outbox, concurrency lock, blocked-period kezelés, státusz-email és security guard. A pricing, iCal és Phase 10 outbox retry/stale reclaim később elkészült. **PLANNED:** online payment.

## Sprint 6 teljesítési állapot

**IMPLEMENTED:** pricing admin CRUD/active state és közös booking/preview engine; policy checkbox és verziózott elfogadási snapshot; 7 naptári napos kötbérmentes határ, azon belül immutable accommodation fee 50%-a; cancellation snapshot, audit és vendéglevél. Az automatikus outbox retry Phase 10-ben elkészült. **PLANNED:** production árértékek, online beszedés és fennmaradó környezeti release-kapuk.

## Sprint 7 teljesítési állapot

**IMPLEMENTED:** RFC 5545 parser/exporter; Google Calendar és Szallas.hu kézi import; forrás, külső esemény és sync-log persistence; külső eseményből külön blocked period; idempotencia és blocking-booking konfliktusjelzés; admin forrás CRUD/kézi sync/log; query-tokenes, PII-mentes export pending/confirmed bookingokkal és aktív belső blocked periodokkal. **PLANNED:** manuális konfliktusfeloldás és tokenrotációs átfedés.

## Sprint 9 teljesítési állapot

**IMPLEMENTED:** cPanel/Apache deployment runbook, backup/restore, `/health`, monitoring, iCal worker és Phase 10 outbox retry worker. **PLANNED / BLOCKED:** production cronaktiválás és környezeti smoke/monitor döntés. Outbox cleanup nincs engedélyezve; retention külön döntés. A staging/production és owner/legal kapuk továbbra is nyitottak.
## Sprint 10 teljesítési állapot

**IMPLEMENTED:** automatikus iCal CLI, source lock, átmeneti hibák korlátozott retry-ja, teljes-feed reconciliation és DST-biztos minimum 24 órás grace; egységes HUF formatter; explicit legacy/person pricing, gyermek ársávok, optimistic admin mentés, v3 éjszakánkénti snapshot és cancellation regresszió. A Phase 10 outbox retry külön elkészült. **DEPLOYMENT PENDING:** valós cPanel cron, provider smoke és tulajdonos által jóváhagyott production árkonfiguráció. **PLANNED:** tokenrotációs átfedés és online fizetés.

## Phase 6–7 teljesítési állapot

**IMPLEMENTED Phase 6:** confirmed booking módosítás aláírt előnézettel, stale/concurrency védelemmel, inventory lockkal, aktuális újraárazással, változatlan korábbi előleggel, history/audit/outbox és vendégértesítés támogatással. **IMPLEMENTED Phase 7 readiness:** owner UAT dokumentáció és GET-only read-only smoke. **PRODUCTION DISABLED/PENDING:** lifecycle és cron; Szallas.hu új feedre váltása provider cutover. **IMPLEMENTED Phase 10:** általános outbox retry/stale reclaim. **DEFERRED:** online fizetés.

## Phase 8 teljesítési állapot

**IMPLEMENTED:** teljes 2FA-val védett, read-only admin operatív dashboard. A négy kártya definíciója: minden `pending`; mai `confirmed` érkezés; mai `confirmed` távozás; valamint `confirmed`, ahol `arrival_date <= today < departure_date`. A mai és hét napon belüli listák nem mutatnak e-mailt vagy telefonszámot, és összesen négy, N+1 nélküli prepared lekérdezésből készülnek. A „ma” mindenhol `Europe/Budapest`; booking-, pricing-, lifecycle- és production-viselkedés változatlan.

## Phase 9 teljesítési állapot

**IMPLEMENTED:** admin kézi foglalás létrehozása `GET /admin/bookings/create`, `POST /admin/bookings/create/preview` és `POST /admin/bookings/create` útvonalakon. Az előnézet read-only availabilityt és a közös pricing engine-t használ; az aláírt mentés inventory lock alatt újraellenőrzi a foglaltságot és az árat. A booking normál `pending`, immutable pricing snapshotot és idempotenciarekordot kap, ezért külön read-model logika nélkül blokkol availabilityben, megjelenik a listában/dashboardon/havi nézetben, és iCalba kerül.

**IMPLEMENTED csatornaspecifikus határ:** a publikus minimum előfoglalási nap nem vonatkozik az adminra, de a múltbeli érkezés, stay/horizon/capacity/child-age szabály változatlan. Minden jogi acceptance mező `NULL`; acceptance audit és automatikus create outbox nincs. A létrehozó admin kezdeti status historyban és PII-mentes `booking.admin_created` auditban rögzül. Nincs új migráció, kézi ár vagy production művelet. Részletek: [Admin kézi foglalásrögzítés](28_ADMIN_MANUAL_BOOKING_CREATION.md).

## Phase 10 teljesítési állapot

**IMPLEMENTED:** cron-kompatibilis `bin/process-email-outbox.php` és `composer email:outbox`; nem blokkoló globális DB-lock; feltételes sor-claim; 25 rekordos szekvenciális batch; 5m/15m/1h/6h retry; öt teljes kísérletes korlát; 15 perces stale processing recovery; deterministic payload final failure; PII-mentes audit és CLI-summary. A worker a tíz meglévő stabil booking payload/renderert támogatja, a 2FA-t és ismeretlen típust nem érinti. Nincs új migráció és nincs retention/törlés.

**NOT YET ENABLED:** production cron. **DEFERRED:** bounce/complaint, provider message idempotency, új rekordos manuális resend. A következő termékfejlesztési fázis előtt a production jogi/pricing/SMTP és cron-monitorozási kapukat kell lezárni.
