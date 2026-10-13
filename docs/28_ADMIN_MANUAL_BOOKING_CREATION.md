# Admin kézi foglalásrögzítés

**Állapot:** IMPLEMENTED Phase 9; local/test validáció, production deployment nélkül
**Utolsó ellenőrzés:** 2026-10-12, `release/rc2` Phase 9 munkafa

## Cél

A teljes 2FA-val hitelesített admin a `/admin/bookings/create` oldalon telefonon, közvetlen e-mailben vagy más offline csatornán érkezett foglalási igényt rögzíthet. A létrejövő rekord normál `pending` foglalás: azonnal blokkolja a kapacitást, megjelenik a booking listában, a dashboard függő számlálójában és a havi foglaltságban, valamint bekerül a PII-mentes iCal exportba.

Ez nem külön foglalástípus és nem közvetlen megerősítési kerülőút. A `pending -> confirmed` átmenethez továbbra is a meglévő sikeres díjbekérő-feltétel és a normál admin workflow tartozik.

## Adatbekérés és dátumszabályok

A form az érkezést, távozást, kapcsolattartói nevet, e-mailt, telefonszámot, felnőtt- és gyermekszámot, gyermekéletkorokat, valamint opcionális megjegyzést kéri. Publikus jogi checkboxot nem jelenít meg.

Az admin-validáció a publikus folyamattal közös normalizálást, minimum/maximum tartózkodást, booking horizontot, gyermekkor- és kapacitásszabályt használja. Az eltérés kizárólag az előfoglalási korlát: a publikus minimum-advance-days nem fut, ezért Budapest helyi dátuma szerinti mai vagy holnapi érkezés megengedett, ha minden más szabály teljesül. Múltbeli érkezés nem rögzíthető. Az intervallum mindenhol fél-nyitott: `[arrival_date, departure_date)`.

## Előnézet és végleges mentés

1. A `POST /admin/bookings/create/preview` szerveroldalon validál, read-only módon ellenőrzi a jelenlegi foglaltságot, majd ugyanazzal a közös pricing engine-nel számol, mint a publikus létrehozás.
2. Az előnézet megmutatja az éjszakák és vendégek számát, a szállásdíjat, IFA/adó összeget, végösszeget és pénznemet. Sem booking, idempotencia, history, audit, snapshot, sem outbox rekord nem készül.
3. A szerver HMAC-aláírása az összes booking adatot, az idempotenciakulcsot, az előnézeti végösszeget és a pénznemet köti össze. Bármely adat vagy ár módosítása új előnézetet igényel.
4. A `POST /admin/bookings/create` ismét validál és ellenőrzi az aláírást, majd az egyetlen szálláshely `booking_inventory_locks` sorát `FOR UPDATE` zárolja. A tranzakció újraellenőrzi a `pending`/`confirmed` foglalásokat és az aktív blokkolt időszakokat, újraszámolja az árat, és csak változatlan összeg/pénznem esetén ment.
5. Siker után `303` redirect történik a normál `/admin/bookings/{reference}` részletoldalra, ahol az egyszer használható üzenet: `A foglalás rögzítve.`

A preview űrlap natív URL-fragmenttel a sikeres válasz `#booking-create-submit` mentési űrlapjára érkezik, ezért desktopon és mobilon a „Foglalás rögzítése” művelet azonnal elérhető. Hibás előnézetnél nem készül ilyen célpont vagy mentési gomb; a validációs hibák és a megőrzött mezőértékek a normál űrlapon maradnak.

Ha az időszak vagy az ár az előnézet óta megváltozott, a mentés `409` szemantikával elutasított, részleges adat nem marad, és új előnézet szükséges. Váratlan hiba nem jelenít meg exception-részletet.

## Tranzakció, idempotencia és árazás

A publikus és admin létrehozás ugyanazt a `TransactionalBookingRepository` magot használja. A `BookingCreationOptions` kizárólag a csatornaspecifikus mellékhatásokat választja szét; az inventory lock, overlap-lekérdezés, idempotencia, pricing, booking- és child-age insert, history és immutable pricing snapshot közös.

A formhoz a szerver nagy entrópiájú idempotenciakulcsot ad. Azonos kulcs és azonos canonical payload ugyanazt a foglalást adja vissza; azonos kulcs eltérő payload esetén determinisztikus konfliktus keletkezik. Időalapú cleanup nincs. Az admin nem írhat be vagy írhat felül kézi végösszeget: a mentett aktuális pricing snapshot a közös pricing engine eredménye.

## Jogi elfogadások és provenance

Az admin rögzítése nem jelenti azt, hogy a vendég a publikus oldalon elfogadta a dokumentumokat. Ezért az admin-created bookingon az alábbi mezők szándékosan `NULL` értékűek:

- privacy elfogadási idő, verzió és URL;
- booking-policy elfogadási idő, verzió és URL;
- house-rules elfogadási idő és URL.

Nem készül `privacy_policy.accepted`, `booking_policy.accepted` vagy `house_rules.accepted` audit. Ehelyett egyetlen `booking.admin_created` sikeres auditbejegyzés készül a létrehozó admin azonosítójával és kizárólag `booking_reference`, `status=pending`, `source=admin` metadata mezőkkel. Vendégnév, e-mail, telefon és megjegyzés nem kerül audit metadata-ba.

A kezdeti státusztörténet:

```text
old_status = NULL
new_status = pending
changed_by_admin_id = létrehozó admin
note = Admin által rögzített foglalás
```

## E-mail viselkedés

A kézi létrehozás nem tesz `booking_request_received` vagy `booking_request_admin_notification` elemet az outboxba, és nem végez SMTP I/O-t. A normál booking részletoldal meglévő, tudatos adminműveletei — díjbekérő, emlékeztető, státuszkommunikáció és érkezési tájékoztató — változatlanul használhatók.

## HTTP- és biztonsági szerződés

| Metódus | Útvonal | Viselkedés |
|---|---|---|
| `GET` | `/admin/bookings/create` | Hitelesített admin form, no-store válasz |
| `POST` | `/admin/bookings/create/preview` | CSRF-, content-type-, body-size- és rate-limit-védett read-only előnézet |
| `POST` | `/admin/bookings/create` | Aláírt előnézethez kötött, tranzakciós mentés |

Mindhárom végpont teljes admin sessiont igényel. A POST műveletek a közös `AdminActionGuard` ellenőrzéseit használják. A dinamikus kimenet HTML-escaped; vendégadat nem kerül URL querybe, általános logba vagy audit metadata-ba. A sikeres URL kizárólag a nem érzékeny booking reference-t tartalmazza.

## Adatbázis és hatókör

Phase 9 nem igényel új migrációt. A meglévő booking, idempotencia, history, audit, child age és pricing snapshot táblák elegendők. Nincs új `source` oszlop, automatikus e-mail kapcsoló, kézi ár, production deployment vagy production adatmutáció.

## Automatizált bizonyíték

A Phase 9 tesztek rögzített Budapest-dátummal ellenőrzik a validációt, előnézetet, aláírást, XSS/CSRF/auth kontrollokat, tranzakciós mentést, legal `NULL` mezőket, admin audit/history provenance-t, outbox hiányát, idempotenciát, stale árat, inventory és blocked-period konfliktust, turnover határt, valamint a booking list/dashboard/havi nézet/availability/iCal integrációt. A publikus létrehozás regressziója külön igazolja, hogy a publikus jogi elfogadás, audit és létrehozáskori e-mail outbox változatlan.
