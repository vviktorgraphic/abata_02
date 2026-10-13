# Admin kézi foglalásrögzítés

**Állapot:** IMPLEMENTED Phase 9 + opcionális admin egyedi végösszeg; local/test validáció, production deployment nélkül
**Utolsó ellenőrzés:** 2026-10-13, `release/rc2` munkafa

## Cél

A teljes 2FA-val hitelesített admin a `/admin/bookings/create` oldalon telefonon, közvetlen e-mailben vagy más offline csatornán érkezett foglalási igényt rögzíthet. A létrejövő rekord normál `pending` foglalás: azonnal blokkolja a kapacitást, megjelenik a booking listában, a dashboard függő számlálójában és a havi foglaltságban, valamint bekerül a PII-mentes iCal exportba.

Ez nem külön foglalástípus és nem közvetlen megerősítési kerülőút. A `pending -> confirmed` átmenethez továbbra is a meglévő sikeres díjbekérő-feltétel és a normál admin workflow tartozik.

## Adatbekérés és dátumszabályok

A form az érkezést, távozást, kapcsolattartói nevet, e-mailt, telefonszámot, felnőtt- és gyermekszámot, gyermekéletkorokat, opcionális megjegyzést és opcionális `Egyedi végösszeg` mezőt kéri. Publikus jogi checkboxot nem jelenít meg. Az egyedi érték egész HUF, a foglalás IFA-t is tartalmazó végösszege, legfeljebb `9 999 999 999 Ft`; üresen a kalkulált ár marad érvényes. Az IFA-t mindig a közös engine számolja, és az egyedi végösszeg nem lehet ennél kisebb.

Az admin-validáció a publikus folyamattal közös normalizálást, minimum/maximum tartózkodást, booking horizontot, gyermekkor- és kapacitásszabályt használja. Az eltérés kizárólag az előfoglalási korlát: a publikus minimum-advance-days nem fut, ezért Budapest helyi dátuma szerinti mai vagy holnapi érkezés megengedett, ha minden más szabály teljesül. Múltbeli érkezés nem rögzíthető. Az intervallum mindenhol fél-nyitott: `[arrival_date, departure_date)`.

## Előnézet és végleges mentés

1. A `POST /admin/bookings/create/preview` szerveroldalon validál, read-only módon ellenőrzi a jelenlegi foglaltságot, majd ugyanazzal a közös pricing engine-nel számol, mint a publikus létrehozás.
2. Az előnézet mindig megmutatja a kalkulátor eredményét. Egyedi összeg esetén külön felirattal jelenik meg a kalkulált szállásdíj, IFA és végösszeg, továbbá az egyedi végösszeg és az effektív egyedi szállásdíj. Sem booking, idempotencia, history, audit, snapshot, sem outbox rekord nem készül.
3. A szerver HMAC-aláírása az összes booking adatot, az idempotenciakulcsot, a kalkulált snapshot hashét, az egyedi összeget vagy explicit `null` értékét, az effektív végösszeget és a pénznemet köti össze. Bármely adat vagy ár módosítása új előnézetet igényel.
4. A `POST /admin/bookings/create` ismét validál és ellenőrzi az aláírást, majd az egyetlen szálláshely `booking_inventory_locks` sorát `FOR UPDATE` zárolja. A tranzakció újraellenőrzi a `pending`/`confirmed` foglalásokat és az aktív blokkolt időszakokat, újraszámolja a kalkulátorárat és az IFA-t, újraalkalmazza az aláírt admin felülbírálást, és csak változatlan kalkulált és effektív snapshot esetén ment.
5. Siker után `303` redirect történik a normál `/admin/bookings/{reference}` részletoldalra, ahol az egyszer használható üzenet: `A foglalás rögzítve.`

A preview űrlap natív URL-fragmenttel a sikeres válasz `#booking-create-submit` mentési űrlapjára érkezik, ezért desktopon és mobilon a „Foglalás rögzítése” művelet azonnal elérhető. Hibás előnézetnél nem készül ilyen célpont vagy mentési gomb; a validációs hibák és a megőrzött mezőértékek a normál űrlapon maradnak.

Ha az időszak vagy az ár az előnézet óta megváltozott, a mentés `409` szemantikával elutasított, részleges adat nem marad, és új előnézet szükséges. Váratlan hiba nem jelenít meg exception-részletet.

## Tranzakció, idempotencia és árazás

A publikus és admin létrehozás ugyanazt a `TransactionalBookingRepository` magot használja. A `BookingCreationOptions` kizárólag a csatornaspecifikus mellékhatásokat választja szét; az inventory lock, overlap-lekérdezés, idempotencia, pricing, booking- és child-age insert, history és immutable pricing snapshot közös.

A formhoz a szerver nagy entrópiájú idempotenciakulcsot ad. A canonical admin payload az opcionális egyedi végösszeget is tartalmazza. Azonos kulcs és azonos canonical payload ugyanazt a foglalást adja vissza; azonos kulcs eltérő payload esetén determinisztikus konfliktus keletkezik. Időalapú cleanup nincs.

Az admin felülbírálási réteg nem része a publikus pricing engine-nek. Egyedi végösszegnél az effektív szállásdíj `egyedi végösszeg - kalkulált IFA`; az effektív immutable snapshot top-level `accommodation_fee`, `taxes` és `total` mezői ezt a fizetendő állapotot tartalmazzák. A `price_override` metadata változatlanul megőrzi a kalkulált szállásdíjat, IFA-t és végösszeget, valamint az egyedi szállásdíjat és végösszeget. Egyedi ár nélkül a kalkulátorsnapshot struktúrája változatlan marad.

## Jogi elfogadások és provenance

Az admin rögzítése nem jelenti azt, hogy a vendég a publikus oldalon elfogadta a dokumentumokat. Ezért az admin-created bookingon az alábbi mezők szándékosan `NULL` értékűek:

- privacy elfogadási idő, verzió és URL;
- booking-policy elfogadási idő, verzió és URL;
- house-rules elfogadási idő és URL.

Nem készül `privacy_policy.accepted`, `booking_policy.accepted` vagy `house_rules.accepted` audit. Ehelyett egyetlen `booking.admin_created` sikeres auditbejegyzés készül a létrehozó admin azonosítójával. Normál árnál kizárólag `booking_reference`, `status=pending`, `source=admin` metadata kerül bele; egyedi árnál ezen felül a biztonságos `pricing_mode=admin_custom_total`, kalkulált végösszeg, egyedi végösszeg és pénznem rögzül. Vendégnév, e-mail, telefon és megjegyzés nem kerül audit metadata-ba.

A kezdeti státusztörténet:

```text
old_status = NULL
new_status = pending
changed_by_admin_id = létrehozó admin
note = Admin által rögzített foglalás
```

## E-mail viselkedés

A kézi létrehozás nem tesz `booking_request_received` vagy `booking_request_admin_notification` elemet az outboxba, és nem végez SMTP I/O-t. A normál booking részletoldal meglévő, tudatos adminműveletei — díjbekérő, emlékeztető, státuszkommunikáció és érkezési tájékoztató — változatlanul használhatók. Új pending egyedi árú foglalás díjbekérő-előlege az effektív egyedi szállásdíjból készül; a levél csak tényleges felülbírálás esetén tartalmaz `Egyedi végösszeg` sort.

## HTTP- és biztonsági szerződés

| Metódus | Útvonal | Viselkedés |
|---|---|---|
| `GET` | `/admin/bookings/create` | Hitelesített admin form, no-store válasz |
| `POST` | `/admin/bookings/create/preview` | CSRF-, content-type-, body-size- és rate-limit-védett read-only előnézet |
| `POST` | `/admin/bookings/create` | Aláírt előnézethez kötött, tranzakciós mentés |

Mindhárom végpont teljes admin sessiont igényel. A POST műveletek a közös `AdminActionGuard` ellenőrzéseit használják. A dinamikus kimenet HTML-escaped; vendégadat nem kerül URL querybe, általános logba vagy audit metadata-ba. A sikeres URL kizárólag a nem érzékeny booking reference-t tartalmazza.

## Adatbázis és hatókör

Az admin egyedi végösszeg sem igényel új migrációt. A meglévő booking, idempotencia, history, audit, child age és JSON pricing snapshot táblák elegendők. Nincs új `source`, `custom_price` vagy `discount` oszlop, automatikus e-mail kapcsoló, production deployment vagy production adatmutáció.

## Automatizált bizonyíték

A tesztek rögzített Budapest-dátummal ellenőrzik a validációt, előnézetet, aláírást, XSS/CSRF/auth kontrollokat, magasabb/alacsonyabb és IFA-val egyenlő egyedi összeget, manipulált és stale preview-t, tranzakciós effektív snapshotot, legal `NULL` mezőket, admin audit/history provenance-t, outbox hiányát, idempotenciát, inventory és blocked-period konfliktust, turnover határt, valamint a booking list/dashboard/havi nézet/availability/iCal integrációt. A publikus létrehozás regressziója külön igazolja, hogy egy `custom_total`-szerű publikus mező nem hoz létre felülbírálást, és a publikus jogi elfogadás, audit és létrehozáskori e-mail outbox változatlan.
