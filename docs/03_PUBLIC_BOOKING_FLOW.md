# Publikus foglalási folyamat

**Állapot:** IMPLEMENTED és PLANNED részekre bontva
**Utolsó ellenőrzött commit:** `9adc564`

Ez a dokumentum a publikus felület jelenlegi működését és a foglalás tényleges rögzítéséhez szükséges 1.0 tervet írja le. Az API pontos szerződése az [API-referenciában](08_API_REFERENCE.md), a dátum- és adatmodell az [adatbázis- és domainmodellben](02_DATABASE_AND_DOMAIN_MODEL.md), a biztonsági kontrollok a [biztonsági specifikációban](09_SECURITY.md) találhatók.

## IMPLEMENTED – jelenlegi oldal

### Oldalfelépítés

A `GET /` egy magyar nyelvű, kétpaneles oldalt ad vissza. Balra a naptár, a jelmagyarázat és a kiválasztott dátumok összegzése, jobbra a vendégadat-űrlap látható. Az oldal natív JavaScriptet és CSS-t használ; Node.js nem runtime-függőség.

A naptár egyszerre két egymást követő hónapot kér le és jelenít meg. Induláskor a kliens helyi dátuma szerinti aktuális hónaptól a két hónappal későbbi hónap első napjáig hívja a `GET /api/availability` végpontot. A lekérdezés `[from, to)` intervallumú. A vissza gomb nem engedi, hogy a megjelenített második hónap az aktuális hónapnál korábbi vagy azzal azonos kezdőhónap elé kerüljön. Az előre gomb legfeljebb az aktuális hónaphoz képest 12 hónapos kezdőhónapig léptet; ezt a felületi korlátot a szerveroldali, napban mért booking horizon is kiegészíti.

> **IMPLEMENTED korlát:** a böngésző a saját helyi dátumát használja a hónapléptetéshez, miközben a szerver `Europe/Budapest` szerint számítja a múltat és a horizontot. Eltérő kliens-időzónánál a hónaphatár különbözhet.

### Napállapotok és fél napos modell

| Státusz | Jelentés | Érkezésként | Távozásként | Megjelenés |
|---|---|---:|---:|---|
| `available` | nincs blokkoló foglalás vagy lezárás | igen | igen | zöld |
| `occupied` | egy megerősített foglalás foglalt éjszakája | nem | nem | piros |
| `arrival_only` | meglévő foglalás érkezési napja | nem | igen | átlós zöld/piros |
| `departure_only` | meglévő foglalás távozási napja | igen | igen | átlós piros/zöld |
| `turnover` | ugyanazon a napon egy foglalás távozik, másik érkezik | nem | igen | átlós zöld/piros |
| `blocked` | adminisztratív lezárás `[start_date, end_date)` szerint | nem | nem | szürke |
| `past` | a budapesti mai napnál korábbi nap | nem | nem | szürke |

Csak a konfigurált `confirmed` booking státusz blokkol; a `pending` és `cancelled` rekordok jelenleg nem. A lezárás elsőbbséget élvez a foglalási jelölésekkel szemben. A fél napos színezés CSS `linear-gradient`; a jelentés nem kizárólag színnel jelenik meg, mert minden nap `title` és `aria-label` szöveget is kap.

A modell fél-nyitott: az érkezés inkluzív, a távozás exkluzív. Példa: egy `[2026-08-01, 2026-08-03)` foglalás augusztus 1. és 2. éjszakáját foglalja; augusztus 3-án új vendég érkezhet.

### Dátumkiválasztás

1. Ha még nincs érkezés, vagy már teljes intervallum volt kijelölve, egy engedélyezett nap kattintása új érkezést kezd és törli a korábbi távozást.
2. Érkezés után csak `selectable_as_departure=true` napok aktívak.
3. A távozás csak akkor fogadható el, ha az éjszakák száma a szervertől kapott `minimum_nights` és `maximum_nights` között van, és az `[arrival, departure)` intervallumban nincs `occupied`, `blocked`, `arrival_only` vagy `turnover` nap.
4. Hibánál a választás változatlan marad, és megjelenik: `A távozás nem választható: 1–30 éjszaka engedélyezett, foglalt nap érintése nélkül.` (a számok konfigurációfüggők).
5. Sikeres választáskor az érkezés és távozás keretet kap, a köztes napok tartománykiemelést, a rejtett űrlapmezők pedig ISO dátumot.
6. A „Dátumok törlése” mindkét dátumot és a naptárhibát törli.

A jelenlegi szabályok: minimum 1, maximum 30 éjszaka, 365 napos booking horizon. Egy availability kérés legfeljebb 93 nap lehet. A szerver az űrlap validálásakor ezeket ismét ellenőrzi.

### Vendégadatok és gyermekmezők

Az űrlap mezői:

- kötelező `name`, `email`, `phone`;
- `adults` legördülő 1–6 értékkel, alapértéke 2;
- `children` legördülő 0–4 értékkel, alapértéke 0;
- gyermekenként dinamikusan létrehozott, kötelező `child_ages[]` számmező 0–17 tartománnyal;
- opcionális `notes`;
- kötelező `privacy` checkbox;
- rejtett `arrival_date` és `departure_date`.

> **IMPLEMENTED korlát:** a böngésző HTML-validációt végez az űrlapmezőkön, de a szerver jelenleg nem validálja az `adults`, `children`, `child_ages` és `notes` tartalmát vagy egymáshoz való konzisztenciáját. A privacy szövegben még nincs tényleges tájékoztató-link.

### Kliens- és szerveroldali validáció

Beküldéskor a kliens előbb megköveteli mindkét kiválasztott dátumot, majd meghívja a böngésző constraint validation API-ját. Ezután JSON-ként elküldi az adatokat a `POST /api/booking/validate` végpontra. A szerver:

- kötelező, nem üres stringként ellenőrzi a `name`, `email`, `phone`, `arrival_date`, `departure_date` mezőket;
- szintaktikailag ellenőrzi az e-mailt;
- csak a logikai `true` privacy értéket fogadja el;
- újra lekéri a teljes `[arrival_date, departure_date)` availability tartományt;
- elutasít múltbeli, foglalt, érkezési, turnover vagy lezárt napot, horizonton kívüli távozást, illetve a minimum/maximum éjszakaszabály megsértését.

Siker esetén is csak `valid: true`, `submission_enabled: false` érkezik: rekord, ár és e-mail nem keletkezik. A részletes payloadokat lásd az [API-referenciában](08_API_REFERENCE.md#post-apibookingvalidate).

### Loading, empty és error state

- Betöltés alatt a naptár `aria-busy="true"`; külön vizuális spinner vagy skeleton nincs.
- Sikeres, de napok nélküli payloadhoz nincs külön empty-state; a két hónap napjai letiltott `past` fallbackként renderelődnek.
- Availability hálózati, HTTP- vagy JSON-hibánál a naptár kiürül, és általános magyar hiba jelenik meg. Automatikus retry és külön retry gomb nincs.
- Űrlap hálózati/JSON-hibánál: `Az ellenőrzés most nem érhető el. Foglalás nem történt.`
- A szervermezőhibák közül a kliens csak az első `errors` értéket jeleníti meg; nincs mezőnkénti szerverhiba-kötés.

### Reszponzivitás és akadálymentesség

980 px alatt a naptár- és űrlappanel egymás alá kerül. 640 px alatt a két hónap, az összegzés és az űrlap is egyoszlopos lesz. A napok natív gombok, ezért billentyűzettel fókuszálhatók és aktiválhatók; látható `:focus-visible` körvonal van. A hónapgomboknak hozzáférhető neve van, a hónapperiódus és kiválasztás `aria-live`, a hiba `role=alert`, az űrlapüzenet `role=status`.

> **PLANNED:** automatizált WCAG 2.2 AA audit, rács-billentyűnavigáció (nyilak/Home/End), betöltési visszajelzés és fókuszkezelés még szükséges. A `role=grid` jelenleg nem tartalmaz teljes gridcell/row szemantikát.

## PLANNED – foglalásmentési folyamat

### Tervezett sikeres user journey

1. A vendég kiválasztja az intervallumot és kitölti az adatokat.
2. A kliens egy idempotenciakulccsal elküldi a létrehozási kérést.
3. A szerver egyetlen tranzakcióban normalizálja és validálja a payloadot, zárolás mellett újraellenőrzi az availabilityt, majd kiszámítja és pillanatképként eltárolja az árat.
4. A szerver egy nem találgatható publikus referenciával `pending` foglalást és kezdeti státusztörténetet hoz létre.
5. Commit után az e-mail események idempotensen sorba kerülnek; az e-mail-küldés sikertelensége nem vonja vissza a foglalást.
6. A válasz és a UI egyértelműen jelzi, hogy igény érkezett, nem automatikus visszaigazolás történt.

**Elfogadási feltételek – foglalás létrehozása:**

- ugyanazok a domain-dátumszabályok érvényesek a preview/validate és create műveletben;
- két párhuzamos, átfedő kérésből legfeljebb egy hozhat létre blokkoló foglalást;
- hiba esetén nincs részlegesen mentett booking, guest, history vagy price snapshot;
- a sikeres válasz nem tartalmaz belső adatbázis-azonosítót vagy más vendég PII-jét;
- automatizált feature és valódi MySQL concurrency teszt igazolja a folyamatot;
- az API a [tervezett create szerződést](08_API_REFERENCE.md#post-apibookings--planned) követi.

### Race condition és tranzakció

Az availability oldal csak tájékoztató; mentéskor kötelező az újraellenőrzés. A végleges megoldásnak adatbázis-tranzakcióval és egyetlen szálláshelyhez tartozó, zárolható erőforrás-sorral vagy azzal egyenértékű szerializációval kell megelőznie a check-then-insert versenyhelyzetet. A puszta `SELECT`, majd külön `INSERT` nem elfogadható. Konfliktuskor `409 Conflict` és stabil hibakód (`DATES_UNAVAILABLE`) szükséges.

**Elfogadási feltételek – versenyhelyzet-kezelés:**

- az ellenőrzés és insert ugyanabban a tranzakcióban fut;
- minden blokkoló booking és blocked period fél-nyitott overlap képlettel kerül vizsgálatra;
- deadlock esetén korlátozott, naplózott újrapróbálás történik, PII nélkül;
- terheléses párhuzamos tesztben nem jön létre double booking.

### Duplikált beküldés

A kliens a folyamat idejére letiltja a gombot, de ez önmagában nem garancia. A create API kérjen nagy entrópiájú `Idempotency-Key` fejlécet; a szerver a kulcsot, a canonical request hashét, állapotát és eredményét korlátozott ideig tárolja. Azonos kulcs és azonos payload ugyanazt az eredményt adja; azonos kulcs eltérő payloadhoz `409` jár.

**Elfogadási feltételek – idempotencia:**

- dupla kattintás és hálózati retry csak egy bookingot eredményez;
- kulcs-újrahasználat eltérő bodyval determinisztikusan elutasított;
- kulcs és request body nem kerül érzékeny adattal alkalmazáslogba;
- lejárat és takarítás dokumentált és tesztelt.

### Tervezett hibás user journey

- `422`: mező- vagy üzletiszabály-hiba; mezőhöz kötött, stabil hibakódokkal;
- `409`: a dátum az utolsó megjelenítés óta betelt, vagy idempotenciakonfliktus van; a UI frissíti a naptárt és megtartja a nem érzékeny mezőket;
- `429`: túl sok kérés; a UI a `Retry-After` szerint jelez;
- `500/503`: átmeneti szerverhiba; a UI nem állítja, hogy foglalás történt, és biztonságos újrapróbálást kínál ugyanazzal az idempotenciakulccsal.

### Adatvédelem és biztonság

A végleges beküldéshez CSRF-védelem (azonos originű böngészős flow), rate limit/spam kontroll, output escaping, szigorú mezőhosszok, napló-redakció, adatmegőrzési szabály és valós adatkezelési tájékoztató szükséges. Részletek: [biztonság](09_SECURITY.md), [e-mail folyamatok](06_EMAIL_WORKFLOWS.md), [tesztelés és üzemeltetés](10_TESTING_AND_OPERATIONS.md).

**Elfogadási feltételek – publikus 1.0 flow:**

- billentyűzettel és mobilon végigvihető;
- minden hiba szövegesen, fókuszolhatóan és mezőszinten azonosítható;
- nincs booking PII availability vagy más publikus read válaszban;
- a sikeroldal újratöltése nem küld új bookingot;
- a foglalásmentés, ár-pillanatkép és e-mail esemény integrációs teszttel igazolt.
