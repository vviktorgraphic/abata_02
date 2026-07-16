# Tulajdonosi döntések

**Állapot:** RESOLVED tulajdonosi döntések és részben nyitott konfiguráció
**Döntés dátuma:** 2026-07-16
**Ellenőrzött kódbázis:** Sprint 3 munkafa, commit előtt

## Projekt és design

- **RESOLVED:** a felhasználó által látható név minden új felületen pontosan **A Bata**.
- **RESOLVED:** primary `#19194B`, accent `#F0A236`, base `#FFFFFF`.
- **RESOLVED:** legalább WCAG AA kontraszt szükséges.

## Admin hitelesítés

- **RESOLVED:** admin session inaktivitási ideje 15 perc.
- **RESOLVED:** e-mailes 2FA kód érvényessége 10 perc.
- **RESOLVED:** a 2FA-kód 6 számjegyű és legfeljebb 5 próbálkozást enged.
- **RESOLVED:** új 2FA-kód legkorábban 60 másodperc után kérhető.
- **OPEN:** abszolút maximális session-élettartam; ebben a sprintben nem kerül feltételezett limit a kódba.

Az implementáció a 15 perces idle határt csúszó lejáratként kezeli. Ez nem értelmezhető abszolút maximumnak.

## SMTP

- **RESOLVED:** tulajdonosi SMTP hostérték: `s54.tarhely.com`. Ez hostnév, nem igazolt HTTP- vagy SMTP-endpoint.
- **OPEN:** SMTP port, titkosítás, felhasználónév, feladó e-mail és authentikáció.
- **IMPLEMENTED fejlesztési konfiguráció:** localhoston a Docker Mailpit host/port használható hitelesítés nélkül.
- Credential vagy más secret nem kerülhet repositoryba.

## Árképzés

**RESOLVED követelmény:** adminfelületen szerkeszthető lesz a konkrét ársáv, az árazási alapegység, a szezonális ár, a hétvégi ár, valamint az idegenforgalmi adó és mentességei. A konkrét értékek és kombinációs szabályok továbbra is **OPEN**; lásd [árképzés](05_PRICING.md).

## iCal

- **RESOLVED:** Szallas.hu és Google Calendar import/export támogatandó.
- **OPEN:** `pending` foglalások exportjának szabálya.

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
- **OPEN:** production ársávok/összegek, hétvégi napok, szezonális/fix díjak, IFA érték és jogilag jóváhagyott exemption kategóriák.

## Sprint 5 admin booking management

- **RESOLVED:** az öt explicit státuszátmenet; minden más tiltott.
- **RESOLVED:** confirmkor confirmed és aktív blocked overlap recheck; más pending változatlan.
- **RESOLVED:** confirmed/rejected/cancelled levél commit után; invalidated nem küld.
- **RESOLVED:** blocked period confirmed bookinggal nem ütközhet, pendinggel figyelmeztetéssel igen; eltávolítása soft delete.
- **OPEN:** automatikus retry/max-attempt/stale reclaim paraméterei.
