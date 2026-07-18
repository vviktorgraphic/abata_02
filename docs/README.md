# A Bata foglalási rendszer – rendszerspecifikáció

**Állapot:** IMPLEMENTED dokumentációs index; az 1.0 célállapot PLANNED
**Utolsó ellenőrzés:** 2026-07-16, Sprint 3 munkafa (commit előtt)

## Cél és igazságforrás

A `docs/` könyvtár az 1.0 rendszerterv elsődleges specifikációja. A repository ténylegesen elkészült állapotát mindig a kóddal, migrációkkal és tesztekkel együtt kell értelmezni. Ellentmondás esetén a fejlesztést meg kell állítani, és döntést kell kérni.

A jelölések jelentése:

- **IMPLEMENTED:** a kód vagy migráció jelenleg tartalmazza, és ahol lehetséges teszt igazolja.
- **PLANNED:** az 1.0 célállapot része, de még nincs implementálva.
- **DECISION REQUIRED:** tulajdonosi vagy műszaki döntés nélkül nem implementálható biztonságosan.
- **DEFERRED:** későbbi verzióra halasztott tétel.
- **OUT OF SCOPE:** nem része az 1.0 rendszernek.

## Dokumentumok

| Dokumentum | Tárgy |
|---|---|
| [00 – Projektáttekintés](00_PROJECT_OVERVIEW.md) | Üzleti cél, szerepkörök, scope és mérföldkövek |
| [01 – Architektúra](01_ARCHITECTURE.md) | Rétegek, request flow, Docker és cPanel célkörnyezet |
| [02 – Adatbázis és domain](02_DATABASE_AND_DOMAIN_MODEL.md) | Tényleges séma, intervallummodell és tervezett táblák |
| [03 – Publikus foglalási folyamat](03_PUBLIC_BOOKING_FLOW.md) | Naptár, dátumválasztás és tervezett mentési folyamat |
| [04 – Admin és hitelesítés](04_ADMIN_AND_AUTHENTICATION.md) | Adminmodulok, jelszó, e-mailes 2FA és sessionök |
| [05 – Árképzés](05_PRICING.md) | Kalkulációs modell, pillanatkép és nyitott üzleti értékek |
| [06 – E-mail folyamatok](06_EMAIL_WORKFLOWS.md) | SMTP, sablonok, idempotencia és retry |
| [07 – iCal-szinkron](07_ICAL_SYNC.md) | RFC 5545 import/export és konfliktuskezelés |
| [08 – API referencia](08_API_REFERENCE.md) | Jelenlegi és tervezett HTTP végpontok |
| [09 – Biztonság](09_SECURITY.md) | Threat model és kötelező kontrollok |
| [10 – Tesztelés és üzemeltetés](10_TESTING_AND_OPERATIONS.md) | Tesztpiramis, cPanel telepítés, backup és monitoring |
| [11 – Roadmap és döntések](11_ROADMAP_AND_DECISIONS.md) | Sprintterv, ADR-ek és nyitott kérdések |
| [98 – Nyitott döntések](98_OPEN_DECISIONS.md) | Prioritásos, még tulajdonosi vagy architekturális döntést igénylő kérdések |
| [99 – Tulajdonosi döntések](99_OWNER_DECISIONS.md) | Dátummal rögzített, lezárt tulajdonosi döntések |

## Gyorshivatkozások

- Legfontosabb üzleti szabály: [fél-nyitott `[arrival_date, departure_date)` intervallum](02_DATABASE_AND_DOMAIN_MODEL.md#3-fél-nyitott-dátumintervallum--implemented).
- Jelenlegi API-k: [API referencia – IMPLEMENTED](08_API_REFERENCE.md#implemented--jelenlegi-végpontok).
- Foglalási napállapotok: [publikus foglalási folyamat](03_PUBLIC_BOOKING_FLOW.md#napállapotok-és-fél-napos-modell).
- Biztonsági minimum: [threat model és kontrollok](09_SECURITY.md).
- Nyitott tulajdonosi kérdések: [priorizált döntési lista](11_ROADMAP_AND_DECISIONS.md#nyitott-üzleti-kérdések).
- Sprint 3 admin-auth állapot: [admin és hitelesítés](04_ADMIN_AND_AUTHENTICATION.md#sprint-3-implementációs-leltár).

## Sprint 3 dokumentációs állapot

**IMPLEMENTED komponensek:** admin credential ellenőrzés, e-mailes 2FA domain/application logika, csúszó idle session, CSRF, rate limiting, audit port és PDO adapterek, SMTP mailer absztrakció, admin HTTP controllerek és sablonok, valamint a `008_create_admin_authentication_tables.sql` migráció.

**IMPLEMENTED integráció:** a front controller regisztrálja a login, 2FA verify/resend, dashboard és logout route-okat. A release elfogadásához az automatizált tesztek mellett Docker/Mailpit HTTP smoke is szükséges.

## Sprint 4 dokumentációs állapot

**IMPLEMENTED:** tranzakciós `POST /api/bookings`, confirmed/blocked mentéskori újraellenőrzés, egymást nem blokkoló `pending` igények, bookinghoz kötött idempotencia, gyermekéletkor-tárolás, közös összetett HUF pricing engine, immutable JSON snapshot, valamint ugyanabban a tranzakcióban létrejövő booking-request outbox. A commit után indított SMTP-kísérlet hibája a bookingot nem törli.

**IMPLEMENTED:** admin jóváhagyás és booking CRUD, pricing admin CRUD/preview és összetett pricing komponensek, továbbá Sprint 7 iCal parser/exporter, Google Calendar és Szallas.hu kézi import, forrás/sync-log persistence és tokenvédett export. **PLANNED:** automatikus outbox retry és stale `processing` helyreállítás, iCal cron/retry/grace és online fizetés.

## Frissítési szabály

1. Új üzleti szabály csak teszttel és ugyanabban a PR-ban frissített dokumentációval kerülhet be.
2. Sémaváltozásnál új migráció és a [sémadokumentáció](02_DATABASE_AND_DOMAIN_MODEL.md) módosítása kötelező.
3. API-változásnál az [API referenciát](08_API_REFERENCE.md), felhasználói viselkedésnél a kapcsolódó flow dokumentumot is frissíteni kell.
4. Minden dokumentum fejlécében frissíteni kell az utolsó ellenőrzött commitot.
5. Tervezett funkciót tilos **IMPLEMENTED** állapotúnak jelölni pusztán azért, mert a séma tartalmaz előkészítő mezőt vagy táblát.

## Használat későbbi Codex sprintekben

A fejlesztő agent először ezt az indexet, majd az érintett modul dokumentumait és az [AGENTS.md](../AGENTS.md) szabályait olvassa el. Implementáció előtt összeveti a tervet a kóddal és migrációkkal. Eltérésnél nem választ önkényesen: rögzíti az ellentmondást, és **DECISION REQUIRED** kérdést tesz fel.

## Sprint 5 – IMPLEMENTED

Admin booking lista/részlet, explicit state machine, tranzakciós history/audit/outbox, kétprocesszes confirm race teszt, blocked-period kezelés, státuszlevelek és védett A Bata admin UI. A production SMTP és az automatikus retry továbbra is **PLANNED**.

## Sprint 7 – IMPLEMENTED alaphatókör

RFC 5545 import/export, provider-validáció, fél-nyitott Budapest-napok, külső eseményből blocked period, duplikáció- és confirmed-konfliktus kezelés, admin forráskezelés/kézi sync/sync log és query-tokenes PII-mentes export. Cron, automatikus retry és eltűnési grace **PLANNED**.
