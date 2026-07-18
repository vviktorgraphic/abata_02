# Admin 2FA session redirect hibajavítás

**Állapot:** IMPLEMENTED és automatizáltan igazolt
**Dátum:** 2026-07-18
**Branch:** `fix/admin-2fa-session-redirect`

## Tünet

Helyes jelszó és sikeresen ellenőrzött 2FA-kód után a rendszer `/admin` helyett látszólag hiba nélkül ismét a login oldalra jutott.

## Bizonyított gyökérok

A sikeres 2FA biztonságosan új session ID-t generált, megőrizte a pending session eredeti abszolút kezdőidejét, létrehozta az `authenticated` adatbázis-sessiont, és a böngésző az új cookie-t visszaküldte. A hiba az ezt követő dashboard guard idle-touch műveletében volt.

Az `AdminSessionRepository::touch()` az UPDATE `rowCount() === 1` eredményét tekintette kizárólag sikernek. A 2FA utáni azonnali dashboard-kérés gyakran ugyanabban a másodpercben futott, mint az authenticated rekord létrehozása. A másodperc pontosságú `last_activity_at` és `expires_at` ilyenkor nem változott, ezért MySQL jogosan `0` módosított sort jelentett. A workflow ezt tévesen érvénytelen sessionként értelmezte, logoutolt, a dashboard guard pedig `/admin/login` oldalra irányított.

A javítás előtt hozzáadott célzott integrációs teszt igazolta a hibát: aktív, azonos másodpercben változatlan session touch esetén `false` érkezett.

## Javítás

- Egy ténylegesen módosított sor továbbra is azonnali siker.
- Nulla módosított sor esetén külön prepared SELECT igazolja, hogy ugyanaz a hash-elt token továbbra is nem visszavont, idle szempontból aktív és az abszolút lifetime-on belüli.
- Lejárt, visszavont vagy abszolút lejárt token továbbra is elutasított.
- A session ID a pending és authenticated határon továbbra is `session_regenerate_id(true)` hívással rotálódik.
- A sikeres privilege promotion után a CSRF token is rotálódik.
- Ha a kód sikeres ellenőrzése után az authenticated session mégsem hozható létre, a felhasználó általános 500-as hibaüzenetet kap; a szerverlog csak biztonságos hibakategóriát/osztályt rögzít, kódot, session ID-t és PII-t nem.

## Cookie- és környezeti viselkedés

- Név: PHP alapértelmezett `PHPSESSID`.
- Path: `/`; domain nincs kényszerítve.
- `HttpOnly=true`, `SameSite=Lax` minden környezetben.
- Development HTTP: `Secure=false`, így a böngésző visszaküldi a lokális cookie-t.
- Production: `SESSION_COOKIE_SECURE=true` továbbra is kötelező; a konfiguráció fail-fast elutasítja a gyengébb beállítást. A HSTS és trusted proxy szabályok változatlanok.

## Bizonyíték

- A javítás előtti no-op touch regressziós teszt 1 hibával bukott.
- A javítás után a session/2FA céltesztek és a teljes workflow integrációs teszt sikeres.
- A kézi Docker/Mailpit HTTP folyamatban a pending és authenticated cookie külön-külön rotálódott; a dashboard és frissítés `200`; logout után az admin oldal ismét loginra irányított.

Valódi production HTTPS cookie/proxy viselkedést továbbra is stagingen kell smoke tesztelni a [release checklist](16_RELEASE_CANDIDATE_RC1.md) szerint.
