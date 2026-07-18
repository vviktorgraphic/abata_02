# Nyitott döntések

**Állapot:** DECISION REQUIRED
**Utolsó felülvizsgálat:** 2026-07-16
**Ellenőrzött kódbázis:** Sprint 3 munkafa, commit előtt

A lezárt döntések forrása a [tulajdonosi döntési napló](99_OWNER_DECISIONS.md).

## P0 – production előtt kötelező

1. **SMTP:** port, TLS mód, authentikáció, felhasználónév és feladó e-mail.
2. **Admin session:** abszolút maximális élettartam; a 15 perces idle timeout már RESOLVED.
3. **Rate limit:** login IP/fiók végleges küszöbei, időablak és lockout idő. A Sprint 3 konfigurálható **IMPLEMENTED DEVELOPMENT DEFAULT** értékeket használ (`10/IP`, `5/fiók`, 15 perces ablak és 15 perces lockout); ezek nem production üzleti döntések.
4. **Adatmegőrzés:** login attempt, audit, session és 2FA rekordok konkrét retentionje.
5. **Backup:** az 5 perces RTO-t teljesítő cPanel restore automatizálás és staging mérési eljárás.

## P1 – kapcsolódó modul előtt

1. Production ársávértékek és prioritások. A három alapegység és az admin szerkeszthetőség IMPLEMENTED; konkrét production érték továbbra sincs feltételezve.
2. Gyermekkor-kategóriák és szorzók.
3. Maximális vendégszám és csecsemőszabály.
4. IFA konkrét értéke és a jogilag alkalmazható mentességi kategóriák. A szerkeszthető exemption modell és HALF_UP egész-HUF kerekítés RESOLVED/IMPLEMENTED.
5. Előleg és online beszedés. A lemondási szabály RESOLVED: legalább 7 nappal érkezés előtt 0, később az immutable accommodation fee 50%-a.
6. **RESOLVED:** a `pending` booking nem kerül iCal exportba.
7. Szallas.hu és Google Calendar production interoperabilitási smoke és szinkrongyakoriság; a két szolgáltató támogatása RESOLVED/IMPLEMENTED.

## RESOLVED hivatkozások

- Projekt neve és színek: [99 – Projekt és design](99_OWNER_DECISIONS.md#projekt-és-design).
- 15 perces idle session és 10 perces 2FA: [99 – Admin hitelesítés](99_OWNER_DECISIONS.md#admin-hitelesítés).
- SMTP host: [99 – SMTP](99_OWNER_DECISIONS.md#smtp).
- RPO/RTO célok: [99 – Backup](99_OWNER_DECISIONS.md#backup).
- Pending/confirmed, vendégadat, idempotencia, snapshot és request e-mail: [99 – Sprint 4 booking](99_OWNER_DECISIONS.md#sprint-4-booking-persistence-pricing-és-e-mail).

## Sprint 4 után nyitott

- Production árak; gyermekár/kedvezmény; IFA és mentességek; hétvégi/szezonális kombináció; fix díjak és kerekítési üzleti szabályok.
- Outbox retry ütemezés, maximális próbálkozás, stale `processing` reclaim, admin resend és e-mail retention.
- Publikus booking rate-limit production küszöbök és trusted originlista. Az adatkezelési tájékoztató URL-je RESOLVED: `/adatkezelesi_tajekoztato`.
- Booking, gyermekéletkor, idempotencia és e-mail rekordok retention/törlési szabálya; az idempotencia automatikus időalapú cleanupja a jelenlegi döntés szerint nincs.

## Sprint 5 után nyitott

- Automatikus státusz-email retry ütemezés, maximális attempts és stale `processing` reclaim.
- Production SMTP/TLS és e-mail retention.
- Pricing Administration konkrét értékei és kombinációs szabályai.

**RESOLVED Sprint 5:** `pending -> confirmed|rejected|invalidated`, `confirmed -> cancelled|invalidated`.

## Sprint 6 után nyitott

- Konkrét production ársávok és összegek, seasonal/fixed-fee értékek. A hétvégi default RESOLVED: péntek és szombat éjszaka.
- IFA production összege és jogilag jóváhagyott exemption kategóriák/kulcsok.
- Előleg és bármilyen tényleges fizetési/beszedési folyamat.

**RESOLVED Sprint 6:** külön booking-policy checkbox, konfigurált URL/verzió snapshot; mindhárom base unit; determinisztikus pricing precedencia és konfliktusleállás; 7 napos/50%-os cancellation formula immutable snapshotból.

## Sprint 7 után nyitott

- iCal cron gyakoriság, retry/backoff és eltűnt esemény grace.
- Exporttoken-rotáció esetleges átfedési ideje.
- Google Calendar és Szallas.hu production fixture/smoke.

**RESOLVED Sprint 7:** query exporttoken; pending kizárása; manuális sync; policy `/foglalasi-szabalyzat`; privacy `/adatkezelesi_tajekoztato`; hétvége péntek/szombat éjszaka; IFA admin-konfigurált, a rendszer számolja.
