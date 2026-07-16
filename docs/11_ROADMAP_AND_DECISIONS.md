# Roadmap és döntési napló

**Állapot:** PLANNED, a felsorolt alapdöntések egy része IMPLEMENTED
**Utolsó ellenőrzött commit:** `9adc564`

## Kiindulási helyzet

**IMPLEMENTED:** technikai alap, MySQL séma, migrációfuttató, publikus két hónapos naptár, read-only availability API, mentés nélküli booking-validáció és demo seeder.
**IMPLEMENTED:** admin authentication foundation és 2FA SMTP adapter; tranzakciós publikus booking persistence; minimális `person_night` ár és snapshot; booking-request outbox és e-mail. **PLANNED:** teljes admin üzleti felület/jóváhagyás, pricing CRUD és összetett komponensek, e-mail retry/admin resend, iCal és production hardening.

## Tervezett sprintek

1. **Admin authentication és e-mailes 2FA.** Előfeltétel az admin session- és auditmodell. Elfogadás: rate limitelt jelszó + egyszer használatos kód, session rotation, biztonságos logout, auditált hibafolyamatok. Lásd [admin és hitelesítés](04_ADMIN_AND_AUTHENTICATION.md).
2. **Read-only admin dashboard és foglaláslista.** Elfogadás: hitelesített, lapozható, szűrhető lista; PII kizárólag jogosult adminnak; minden lekérdezés prepared statement.
3. **Publikus foglalásmentés — IMPLEMENTED.** Tranzakcióban újraellenőrzi a confirmed/blocked rendelkezésre állást és idempotenciakulcsot használ. Több pending átfedhet; azonos kulcs csak egy bookingot eredményez. Lásd [publikus flow](03_PUBLIC_BOOKING_FLOW.md).
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
| ADR-008 | IMPLEMENTED alap | SMTP transport és booking e-mail outbox/log | Közvetlen `mail()` nincs; atomi egyszeri claim működik, retry/admin resend és production SMTP-paraméterek még nyitottak. |
| ADR-009 | PLANNED | E-mailes 2FA az 1.0-ban | TOTP bővíthetőség megmarad, de TOTP **DEFERRED**. |
| ADR-010 | PLANNED | Tokenes iCal export feed | A token secretként kezelendő és rotálható; feed nem tartalmazhat PII-t. |
| ADR-011 | PLANNED | Importált iCal esemény külön entitás | Nem keverhető belső bookinggal; forrás, UID, sequence és last-seen szükséges. |
| ADR-012 | IMPLEMENTED | Megváltoztathatatlan ár-pillanatkép | Későbbi árszabály-változás nem írja át a korábbi booking árát. |
| ADR-013 | IMPLEMENTED | Pending nem blokkol és nem jár le automatikusan | Több átfedő pending lehet; confirmed és blocked period blokkol. |
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
7. **RESOLVED:** a `pending` nem blokkol és admin beavatkozásig, automatikus lejárat nélkül megmarad.
8. **DECISION REQUIRED:** adatmegőrzési és törlési idők booking, vendég, audit, e-mail és backup adatokra.

### P1 – modul előtt lezárandó

1. **DECISION REQUIRED:** szezonális és hétvégi felár együttalkalmazási sorrendje.
2. **DECISION REQUIRED:** takarítási és más fix díjak feltételei.
3. **DECISION REQUIRED:** kedvezménytípusok és admin felülírás korlátai.
4. **DECISION REQUIRED:** elsődleges iCal források és szolgáltatóspecifikus kompatibilitási célok.
5. **DECISION REQUIRED:** exportáljuk-e a `pending` foglalásokat; alapjavaslat: nem.
6. **DECISION REQUIRED:** admin session abszolút lejárata. A 15 perces idle lejárat RESOLVED és implementált.
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

A következő funkcionális sprintbe nem tartozik bele automatikusan a teljes admin booking CRUD, pricing admin/összetett pricing, iCal vagy általános e-mail retry rendszer. Ezek továbbra is **PLANNED**.

Nyitott kapuk: abszolút session maximum; production SMTP port/TLS/auth/feladó; végleges rate-limit küszöbök és retention.

## Sprint 4 teljesítési állapot

**IMPLEMENTED:** `POST /api/bookings`, tranzakciós készletzár és confirmed/blocked recheck, pending overlap, bookinghoz kötött idempotencia, gyermekéletkorok, configured `person_night` HUF kalkuláció és immutable snapshot, booking-request outbox és commit utáni SMTP-kísérlet.

**PLANNED:** admin approval/list/detail; pricing admin CRUD; gyermekár, IFA, hétvége/szezon kombináció és más árelemek; outbox retry/admin resend/stale claim recovery; iCal és online fizetés.

## Sprint 5 teljesítési állapot

**IMPLEMENTED:** admin lista/részlet, explicit state machine, tranzakciós státuszváltás/history/audit/outbox, concurrency lock, blocked-period kezelés, státusz-email és security guard. **NEXT:** Pricing Administration. **PLANNED:** automatikus outbox retry/stale reclaim, iCal és payment.
