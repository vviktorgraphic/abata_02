# Tulajdonosi döntések

**Állapot:** RESOLVED tulajdonosi döntések és részben nyitott konfiguráció
**Döntés dátuma:** 2026-07-16
**Ellenőrzött kódbázis:** Sprint 9 production-deployment munkafa, commit előtt

## Projekt és design

- **RESOLVED:** a felhasználó által látható név minden új felületen pontosan **A Bata**.
- **RESOLVED:** primary `#19194B`, accent `#F0A236`, base `#FFFFFF`.
- **RESOLVED:** legalább WCAG AA kontraszt szükséges.

## Admin hitelesítés

- **RESOLVED:** admin session inaktivitási ideje 15 perc.
- **RESOLVED:** e-mailes 2FA kód érvényessége 10 perc.
- **RESOLVED:** a 2FA-kód 6 számjegyű és legfeljebb 5 próbálkozást enged.
- **RESOLVED:** új 2FA-kód legkorábban 60 másodperc után kérhető.
- **OPEN:** az abszolút maximális session-élettartam konkrét production értéke.

**IMPLEMENTED Sprint 8:** az abszolút élettartam kötelezően konfigurálható productionben, és a jelszó utáni pending állapottól a 2FA utáni authenticated sessionig közös szerveroldali korlát. A fejlesztői `28800` másodperc nem tulajdonosi production döntés.

## SMTP

- **RESOLVED:** tulajdonosi SMTP hostérték: `s54.tarhely.com`. Ez hostnév, nem igazolt HTTP- vagy SMTP-endpoint.
- **OPEN:** SMTP port, titkosítás, felhasználónév, feladó e-mail és authentikáció.
- **IMPLEMENTED fejlesztési konfiguráció:** localhoston a Docker Mailpit host/port használható hitelesítés nélkül.
- Credential vagy más secret nem kerülhet repositoryba.
- **IMPLEMENTED Sprint 8:** productionben csak hitelesített TLS/SSL SMTP konfiguráció fogadható el, kötelező certificate- és hostnév-ellenőrzéssel; a konkrét port/user/feladó/credential továbbra is OPEN/deployment secret.

## Árképzés

**RESOLVED követelmény:** adminfelületen szerkeszthető a konkrét ársáv, az árazási alapegység, a szezonális ár, a hétvégi ár, valamint az idegenforgalmi adó és mentességei. A hétvégi alapértelmezés péntek és szombat éjszaka. Az IFA-t az admin konfigurálja, a rendszer kizárólag számolja; production adóértéket vagy jogi mentességet nem talál ki. A további konkrét értékek és kombinációs szabályok **OPEN**; lásd [árképzés](05_PRICING.md).

## iCal

- **RESOLVED:** Szallas.hu és Google Calendar import/export támogatandó.
- **RESOLVED:** csak confirmed booking és aktív blocked period exportálódik; pending/rejected/cancelled/invalidated booking nem.
- **RESOLVED:** az exporttoken query paraméterben van: `/calendar/export.ics?token=...`.
- **RESOLVED:** Sprint 7-ben kézi sync van; cron/retry/grace nincs feltételezve.

## Backup

- **RESOLVED:** RPO 4 óra.
- **RESOLVED cél:** RTO 5 perc.
- **Korlát:** az 5 perces RTO cPanelen csak automatizált, előre tesztelt restore-folyamattal reális; stagingben méréssel kell igazolni, enélkül nem tekinthető teljesített SLA-nak.

## Sprint 4 booking persistence, pricing és e-mail

- **RESOLVED:** minden publikus igény `pending`; pending nem blokkol másik igényt és admin beavatkozásig marad, automatikus lejárat vagy cleanup cron nélkül.
- **RESOLVED:** a `confirmed` booking és a blocked period blokkol; mentéskor tranzakcióban újraellenőrzendő. A későbbi admin megerősítéskor ugyanez az invariáns kötelező.
- **RESOLVED:** további vendégnevek nem szükségesek. A kapcsolattartó a bookingon, a gyermekek életkora külön rekordokban tárolódik; mesterséges név és életkorból születési dátum nem készül.
- **RESOLVED:** az idempotenciakulcs és request hash hash-elve, a bookinghoz kötve, időalapú automatikus törlés nélkül marad meg.
- **RESOLVED:** bookingonként immutable ár-pillanatkép készül, és sikeres commit után foglalásiigény-e-mail küldése indul. SMTP-hiba nem törli a bookingot.
- **HISTORICAL Sprint 4 ármodell:** konfigurált `person_night`, minden személy azonos egységáron. **IMPLEMENTED Sprint 6:** három base unit és admin-szerkeszthető komponensmodell; production értékek, hétvégi napok és jogi IFA-mentességek továbbra is OPEN.
- **RESOLVED branding:** felhasználói név **A Bata**, fő design tokenek `#19194B`, `#F0A236`, `#FFFFFF`.

## Kapcsolódó dokumentumok

- [Nyitott döntések](98_OPEN_DECISIONS.md)
- [Admin és hitelesítés](04_ADMIN_AND_AUTHENTICATION.md)
- [E-mail folyamatok](06_EMAIL_WORKFLOWS.md)
- [Roadmap](11_ROADMAP_AND_DECISIONS.md)

## Sprint 6 pricing, policy és cancellation

- **RESOLVED:** a booking-policy elfogadás az adatkezeléstől külön, kötelező és előre ki nem jelölt; URL-je és verziója konfigurált és bookingonként snapshotolt.
- **RESOLVED:** támogatott base unit: `per_person_per_night`, `per_night`, `per_booking`; a pricing engine sorrendje determinisztikus, azonos nyertes prioritás konfliktus.
- **RESOLVED:** legalább 7 Europe/Budapest naptári nappal érkezés előtt a confirmed booking kötbérmentesen lemondható; később a booking immutable pricing snapshot `accommodation_fee` értékének 50%-a a HUF kötbér.
- **RESOLVED:** a cancellation snapshot és vendéglevél nem jelent automatikus terhelést; online beszedés nincs implementálva.
- **RESOLVED:** booking-policy URL `/foglalasi-szabalyzat`; privacy URL `/adatkezelesi_tajekoztato`; hétvégi default péntek/szombat éjszaka; IFA admin-konfigurált és a rendszer számolja.
- **OPEN:** production ársávok/összegek, szezonális/fix díjak, IFA érték és jogilag jóváhagyott exemption kategóriák.

## Sprint 5 admin booking management

- **RESOLVED:** az öt explicit státuszátmenet; minden más tiltott.
- **RESOLVED:** confirmkor confirmed és aktív blocked overlap recheck; más pending változatlan.
- **RESOLVED:** confirmed/rejected/cancelled levél commit után; invalidated nem küld.
- **RESOLVED:** blocked period confirmed bookinggal nem ütközhet, pendinggel figyelmeztetéssel igen; eltávolítása soft delete.
- **OPEN:** automatikus retry/max-attempt/stale reclaim paraméterei.

## Sprint 7 iCal sync

- **RESOLVED:** külső esemény külön entitás és blocked period, soha nem booking; confirmed konfliktus bookingot nem módosít.
- **RESOLVED:** admin forráskezelés és kézi sync; automatikus cron nincs implementálva.
- **OPEN:** cron gyakoriság, retry/backoff, eltűnési grace és tokenrotációs átfedés.

## Sprint 8 production hardening

- **IMPLEMENTED:** privacy elfogadási időpont, URL és verzió immutable snapshotként, ugyanabban a booking tranzakcióban auditálva; történeti rekordokra nem készül visszamenőleges elfogadási bizonyíték.
- **IMPLEMENTED:** `/foglalasi-szabalyzat` és `/adatkezelesi_tajekoztato` technikai route, biztonságos `noindex` fejlesztői placeholderrel.
- **OPEN / RELEASE BLOCKER:** a két jogi dokumentum jóváhagyott szövege és végleges verziója.
- **IMPLEMENTED:** HTTPS-feltételes HSTS, common security headerek és exact, fail-fast trusted proxy lista.
- **OPEN / DEPLOYMENT:** tanúsítvány, HTTP→HTTPS redirect és staging security smoke.

## Sprint 9 production deployment readiness

- **IMPLEMENTED:** cPanel/Apache deployment és rollback runbook, külön production HTTPS redirect sablonnal.
- **IMPLEMENTED:** checksumolt, secretet parancssorban nem továbbító backup/restore CLI, explicit céladatbázis-megerősítéssel.
- **IMPLEMENTED:** adatbázis-readiness health endpoint és monitoring/alerting runbook.
- **OPEN / OWNER-OPERATIONS:** backup gyakoriság és retention; monitoring szolgáltató/SLA/riasztási küszöb; iCal/outbox/cleanup worker szabályai és ütemezése.
- **OPEN / ENVIRONMENT:** HTTPS, SMTP, restore és RPO/RTO staging smoke; ezek dokumentációja nem bizonyítja a környezetbeli teljesülést.
