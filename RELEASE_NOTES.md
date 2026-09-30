# A Bata 1.0.0-rc2 release notes

## RC2 Sprint 11 hardening

- RC2 integration branch created without merging to `main`.
- SMTP timeout is environment-configurable; PDO MySQL is declared as a runtime extension.
- iCal private URLs are no longer rendered in admin edit forms; session strict mode is enforced.
- Production GO remains blocked on owner/provider decisions and cPanel/browser/restore smoke evidence.

**Állapot:** release candidate forráskód; production kiadásra jelenleg **NO-GO**
**Dátum:** 2026-07-18

> **RC1 hotfix:** a sikeres admin 2FA utáni same-second session touch téves logoutját a `fix/admin-2fa-session-redirect` branch javítja. A javítás részletei: [docs/17_ADMIN_2FA_SESSION_REDIRECT_FIX.md](docs/17_ADMIN_2FA_SESSION_REDIRECT_FIX.md).

> **iCal hotfix:** a Szallas.hu paraméter nélküli `YYYYMMDD` DATE eseményei időzónaeltolás nélkül, exkluzív `DTEND` végponttal importálhatók; az idempotencia, availability és export-loop védelem regressziós teszttel igazolt.

> **Sprint 10 feature branch:** elkészült az automatikus iCal worker, a személyalapú/gyermek ársávos árképzés és az egységes egész-HUF megjelenítés. Ez még nincs automatikusan merge-elve az RC1-be; részletek: [Sprint 10](docs/18_SPRINT10_AUTOMATIC_ICAL_AND_PERSON_PRICING.md).

> **Pricing admin UX-javítás:** az egyetlen `Árképzés` oldal tulajdonosi nyelven kezeli a felnőtt- és gyermekárakat, támogatja az auditált ársávtörlést, javítja a legacy HUF runtime hibát és a keskeny nézetek horizontális túlcsordulását. Részletek: [árképzési admin javítás](docs/19_PRICING_ADMIN_UX_FIX.md).

## Fő funkciók

- publikus availability naptár és tranzakciós, idempotens booking request;
- külön privacy- és booking-policy elfogadási snapshot;
- e-mailes kétfaktoros admin hitelesítés, CSRF, session és rate limit;
- admin foglaláslista, részlet, auditált státuszváltások és blocked periodok;
- közös HUF pricing engine, admin pricing CRUD/preview és immutable ár-snapshot;
- 7 Budapest-naptári napos/50%-os lemondási szabály;
- booking- és státuszlevél outbox, SMTP commit után;
- kézi Google Calendar/Szallas.hu iCal import és tokenes, PII-mentes export;
- cPanel/Apache deployment, backup/restore és monitoring runbook.

## Ismert korlátozások

- Nincs automatikus outbox retry/stale-claim worker.
- Az iCal worker futtatható, de production cPanel cronja és provider smoke-ja még nincs környezetben igazolva.
- Production személyárak és gyermek ársávok nincsenek feltételezve vagy seedelve; owner konfiguráció szükséges.
- Nincs jóváhagyott cleanup/retention worker; booking-idempotencia időalapú törlése tilos.
- Nincs online fizetés vagy automatikus kötbérbeszedés.
- A jogi oldalak fejlesztői `noindex` placeholderek.
- A release artefaktum és automatizált suite nem helyettesíti a valós cPanel, HTTPS, SMTP/DNS és restore smoke-ot.

## Nyitott owner döntések

A teljes, prioritásos lista: [docs/98_OPEN_DECISIONS.md](docs/98_OPEN_DECISIONS.md). Production előtt különösen szükséges a jogi tartalom és verzió, SMTP-konfiguráció, admin abszolút session limit, rate-limit értékek, retention, production pricing/IFA adatok, backup ütemezés és monitoring/escalation jóváhagyása.

## Production előfeltételek

1. Az [RC1 staging checklist](docs/16_RELEASE_CANDIDATE_RC1.md) minden kötelező pontja bizonyítottan PASS.
2. A [production deployment runbook](docs/15_DEPLOYMENT.md) szerinti cPanel release és HTTPS smoke sikeres.
3. A production SMTP, SPF, DKIM és DMARC ellenőrzött.
4. A checksumolt backup eltérő staging adatbázisba visszaállítható; az RPO/RTO mérés dokumentált.
5. A jogi és tulajdonosi P0 kapuk lezártak.

## Release recommendation

**GO** a staging RC1 validációra. **NO-GO** production release-re mindaddig, amíg a fenti környezetfüggő és owner/legal kapuk nincsenek igazoltan lezárva.
