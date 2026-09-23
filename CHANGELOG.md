# Changelog

A projekt változásai ebben a fájlban követik a release-eket. A formátum a Keep a Changelog elveit használja; a verziózás célja Semantic Versioning.

## [Unreleased]

### Added

- Automatikus, forrásonként lockolt iCal CLI worker korlátozott retry/backoff, 24 órás eltűnési grace és bővített sync metrikák mellett.
- Explicit legacy/személyalapú pricing mód, felnőtt hétköznapi/hétvégi személyár, adminisztrálható gyermek ársávok és teljes v3 immutable snapshot.
- Egységes, lebegőpontos számítást nem használó HUF formatter az admin, publikus és e-mail felületeken.

### Fixed

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
