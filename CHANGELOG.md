# Changelog

A projekt változásai ebben a fájlban követik a release-eket. A formátum a Keep a Changelog elveit használja; a verziózás célja Semantic Versioning.

## [Unreleased]

### RC2 hardening

- Added configurable SMTP timeout, PDO MySQL dependency declaration, strict session mode enforcement and masked iCal edit URLs.
- Added one shared `.env` bootstrap for web and CLI entry points with process-environment precedence and CRLF-safe parsing.
- Added MySQL 8.0/MariaDB 10.6 migration compatibility coverage and corrected portable check-constraint removal.
- Added a deterministic PowerShell release package with commit pinning, runtime-only defaults, environment-file exclusion and SHA-256 manifest.
- Added disposable backup/restore drill and production SMTP/2FA and iCal cron go-live guidance.
- Added admin-side WP Booking System CSV preview/import with provenance, idempotency and no-email historical migration semantics.

### Added

- Added Phase 3 guest communications: snapshot-safe payment reminders, manual arrival information with four CID JPEGs, exact-day review worker, completed booking lifecycle and shared CSRF logout icon.
- Added v2 payment request snapshots and deterministic short payment references; advance now excludes tourism tax and is calculated from immutable accommodation fee.
- Automatikus, forrásonként lockolt iCal CLI worker korlátozott retry/backoff, 24 órás eltűnési grace és bővített sync metrikák mellett.
- Explicit legacy/személyalapú pricing mód, felnőtt hétköznapi/hétvégi személyár, adminisztrálható gyermek ársávok és teljes v3 immutable snapshot.
- Egységes, lebegőpontos számítást nem használó HUF formatter az admin, publikus és e-mail felületeken.

### Fixed

- A MIME `From` fejléc ténylegesen használja az `A Bata` feladónevet, minden vendég booking levél konfigurált `info@abata.hu` Reply-To címet kap, miközben a 2FA és admin értesítések guest Reply-To nélkül maradnak.
- Az érkezési tájékoztató négy inline CID képe desktopon egységes, explicit 600 px szélességet kapott, miközben mobilon aránytartóan zsugorodik.
- Az admin fejléc bejelentkezés előtt már nem renderel védett menüpontokat vagy logoutot; autentikált mobilnézetben hozzáférhető, bezárható hamburger navigáció jelenik meg.
- A teljesült foglalások megmaradnak az admin havi történeti nézetben anélkül, hogy publikus kapacitást blokkolnának vagy iCalba kerülnének; a státuszlevél-retry csak a kapcsolódó failed levélre reagál, a lemondási indok pedig egyértelműen vendégnek szóló mezőként jelenik meg.
- Az admin `Árképzés` most egyetlen, nem technikai tulajdonosi workflow: felnőttárak, létrehozható/szerkeszthető/törölhető gyermek ársávok és üzleti előnézet.
- A legacy `amount=NULL` pricing sorok többé nem okoznak HufFormatter TypeErrort; adatjavító migráció, repository-normalizálás és biztonságos production 500 hibahatár készült.
- Az admin intrinsic flex/grid/table szélességei nem okoznak dokumentumszintű horizontális túlcsordulást; a széles táblák saját wrapperben görgethetők.
- A Szallas.hu bare `YYYYMMDD` iCal DATE eseményei most szabványos, fél-nyitott Blocked Periodként importálhatók.
- A sikeres admin 2FA után az azonos másodpercben változatlan MySQL session touch többé nem okoz téves logoutot és login redirectet.
- Session-létrehozási hiba esetén néma redirect helyett biztonságos felhasználói hiba jelenik meg.
- A reconciliation 24 órája DST-váltáskor is abszolút eltelt idő, a tényleges blokk-inaktiválási metrika pedig konfliktus és ismételt CANCELLED esetén is pontos.

### Security

- A session fixation elleni ID-rotáció és az immutable abszolút lifetime-origin megmaradt; sikeres 2FA után a CSRF token is rotálódik.

## [1.0.0-rc1] - 2026-07-18

### Added

- Publikus booking persistence, immutable pricing/policy/privacy snapshot és outbox.
- E-mailes 2FA-val védett admin foglalás-, pricing- és iCal-kezelés.
- Auditált booking state machine, blocked period és cancellation snapshot.
- Kézi iCal import és tokenes, PII-mentes export.
- Production hardening, cPanel deployment, backup/restore és monitoring runbook.

### Security

- CSRF, rate limiting, session rotation/timeout, Secure-cookie és HSTS támogatás.
- Exact trusted proxy lista, production SMTP TLS guard és biztonsági response headerek.
- Külön privacy/policy elfogadási bizonyíték és PII/secretmentes operations útmutató.

### Known limitations

- A production owner/legal és staging smoke kapuk nyitottak; lásd [RELEASE_NOTES.md](RELEASE_NOTES.md).
- Automatikus iCal/outbox/cleanup workerek és online fizetés nincsenek implementálva.
