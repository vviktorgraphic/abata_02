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

1. Konkrét ársávok, alapegység-értékek és árszabály-prioritás; a szerkeszthetőség RESOLVED.
2. Gyermekkor-kategóriák és szorzók.
3. Maximális vendégszám és csecsemőszabály.
4. IFA konkrét értékek, mentességek és kerekítés; a szerkeszthetőség RESOLVED.
5. Előleg és lemondási szabály.
6. `pending` foglalás tartási ideje és iCal exportja.
7. Szallas.hu és Google Calendar interoperabilitási tesztfixture-ek és szinkrongyakoriság; a két szolgáltató támogatása RESOLVED.

## RESOLVED hivatkozások

- Projekt neve és színek: [99 – Projekt és design](99_OWNER_DECISIONS.md#projekt-és-design).
- 15 perces idle session és 10 perces 2FA: [99 – Admin hitelesítés](99_OWNER_DECISIONS.md#admin-hitelesítés).
- SMTP host: [99 – SMTP](99_OWNER_DECISIONS.md#smtp).
- RPO/RTO célok: [99 – Backup](99_OWNER_DECISIONS.md#backup).
