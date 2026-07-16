# API-referencia

**Állapot:** IMPLEMENTED és PLANNED részekre bontva
**Utolsó ellenőrzött commit:** `9adc564`

A jelenlegi route-ok auth nélkül érhetők el. A JSON-válaszok `Content-Type: application/json; charset=utf-8` fejlécet kapnak. Verziózott API-prefix, CORS-konfiguráció, cache-fejléc, rate limit, CSRF-védelem és egységes hibaboríték jelenleg nincs. A domain jelentéseket lásd a [publikus foglalási folyamatban](03_PUBLIC_BOOKING_FLOW.md) és az [adatmodellben](02_DATABASE_AND_DOMAIN_MODEL.md).

## IMPLEMENTED – jelenlegi végpontok

### `GET /api/availability`

**Auth:** nincs. **PII:** sem requestben, sem response-ban nincs. **Cache:** a kód nem állít cache-fejlécet; köztes cache alkalmazása jelenleg nem specifikált.

Query paraméterek:

| Név | Kötelező | Formátum | Szabály |
|---|---:|---|---|
| `from` | igen | pontos `YYYY-MM-DD` | inkluzív első nap |
| `to` | igen | pontos `YYYY-MM-DD` | exkluzív végdátum; későbbi legyen `from`-nál |

Legfeljebb 93 nap kérhető. Hiányzó vagy tömbként átadott paraméter üres értékként kerül validálásra. A dátumok `Europe/Budapest` időzónájú naptári napok.

Példa:

```http
GET /api/availability?from=2026-08-01&to=2026-08-04
Accept: application/json
```

`200 OK`:

```json
{
  "from": "2026-08-01",
  "to": "2026-08-04",
  "timezone": "Europe/Budapest",
  "rules": {
    "minimum_nights": 1,
    "maximum_nights": 30,
    "booking_horizon_days": 365
  },
  "days": [
    {
      "date": "2026-08-01",
      "status": "available",
      "selectable_as_arrival": true,
      "selectable_as_departure": true
    }
  ]
}
```

A `days` minden napot tartalmaz `[from,to)` között. Lehetséges státusz: `available`, `occupied`, `arrival_only`, `departure_only`, `turnover`, `blocked`, `past`. A horizonton túli nap mindkét `selectable_*` értéke `false`, státuszától függetlenül. Csak `confirmed` booking blokkol a jelenlegi konfigurációban.

`422 Unprocessable Entity` payloadja `{"error":"..."}`. A tényleges üzenetek:

| Eset | `error` |
|---|---|
| hibás/hiányzó `from` | `The from parameter must be a valid YYYY-MM-DD date.` |
| hibás/hiányzó `to` | `The to parameter must be a valid YYYY-MM-DD date.` |
| `to <= from` | `The to date must be later than the from date.` |
| 93 napnál hosszabb tartomány | `The requested range cannot exceed 93 days.` |

`500 Internal Server Error`:

```json
{"error":"Availability is temporarily unavailable."}
```

Adatbázis- és nem várt hibák részlete nem kerül a válaszba. Dokumentált PII-mező nem jelenik meg, amit feature és integrációs teszt is ellenőriz.

### `POST /api/booking/validate`

**Auth:** nincs. **Content-Type:** a kliens `application/json`-t küld, de a szerver nem ellenőrzi a fejlécet. **PII input:** `name`, `email`, `phone`, `notes`; a válasz ezeket nem tükrözi vissza. **Cache:** nincs explicit `Cache-Control`; PII miatt köztes tárolás nem megengedhető.

> **PLANNED – P1 release-kapu:** a publikus, PII-t fogadó végponton bevezetendő a `Cache-Control: no-store`, a kizárólagos `application/json` ellenőrzés (`415` hibával), szigorú body-méret- és mezőhosszlimit, valamint IP/session alapú rate limit. Ezek nélkül a végpont productionre nem tekinthető hardeningeltnek.

Ez a végpont kizárólag validál, nem ment foglalást és nem küld e-mailt.

Példa request:

```json
{
  "name": "Minta Vendég",
  "email": "vendeg@example.invalid",
  "phone": "+36 30 000 0000",
  "adults": "2",
  "children": "1",
  "child_ages": ["8"],
  "notes": "Opcionális megjegyzés",
  "privacy": true,
  "arrival_date": "2026-08-01",
  "departure_date": "2026-08-04"
}
```

Kötelező nem üres string: `name`, `email`, `phone`, `arrival_date`, `departure_date`. Az `email` legyen PHP `FILTER_VALIDATE_EMAIL` szerint érvényes, a `privacy` értéke pontosan boolean `true`. Az `adults`, `children`, `child_ages`, `notes` szerveroldali szabálya jelenleg nincs. Ismeretlen mezők figyelmen kívül maradnak.

A dátumtartomány valid, ha pontos ISO dátumú, pozitív és maximum 93 napos, 1–30 éjszakás, a távozás nincs a 365 napos horizonton túl, továbbá az `[arrival_date,departure_date)` napok között nincs `occupied`, `arrival_only`, `turnover`, `blocked` vagy `past`. A `departure_only` nem blokkolja az intervallumot.

`200 OK`:

```json
{
  "valid": true,
  "submission_enabled": false,
  "message": "Az adatok érvényesek, de a foglalásküldés a következő sprintben készül el. Foglalás nem történt."
}
```

Kötelező mezőhibák esetén `422`:

```json
{
  "valid": false,
  "errors": {
    "name": "A mező kitöltése kötelező.",
    "email": "Érvényes e-mail-cím szükséges.",
    "privacy": "Az adatkezelési hozzájárulás kötelező."
  }
}
```

Az egyes hiányzó `name`, `email`, `phone`, `arrival_date`, `departure_date` mezők üzenete `A mező kitöltése kötelező.`. Hibás, de nem üres emailnél `Érvényes e-mail-cím szükséges.`; hiányzó/hamis privacy esetén a fenti privacy üzenet érkezik.

Nem foglalható, de formailag értelmezhető időszakhoz `422`:

```json
{"valid":false,"errors":{"dates":"A kiválasztott időszak nem foglalható."}}
```

Dátumparse/range hibánál `422`, és a `dates` értéke az availability végpont angol hibaüzeneteinek egyike:

```json
{"valid":false,"errors":{"dates":"The to date must be later than the from date."}}
```

Érvényes JSON, de nem objektum/tömb (például `null` vagy string) esetén `422`:

```json
{"valid":false,"error":"Invalid request body."}
```

> **IMPLEMENTED eltérés:** malformed JSON a `JSON_THROW_ON_ERROR` miatt a külső hibakezelőbe jut, ezért jelenleg `500`, nem `422` választ ad:

```json
{"valid":false,"error":"Az ellenőrzés átmenetileg nem érhető el."}
```

Ugyanez a `500` payload érkezik adatbázis- vagy más nem várt hibánál. A válaszoknak nincs stabil gépi hibakódja.

### `GET /health`

**Auth:** nincs. **PII:** nincs. **Cache:** nincs explicit cache-fejléc.

`200 OK` példa:

```json
{"status":"ok","time":"2026-07-16T12:34:56+02:00"}
```

Az idő `DATE_ATOM` alakú az alkalmazás időzónájában. A végpont nem ellenőrzi az adatbázist vagy más függőséget, ezért liveness jelzés, nem teljes readiness probe.

### `GET /`

**Auth:** nincs. **Válasz:** `200 text/html` publikus foglalási oldal. **PII:** a response nem tartalmaz vendégadatot; a felhasználó az űrlapba adhat meg PII-t. **Cache:** nincs explicit szabály.

Az oldal a [publikus flow](03_PUBLIC_BOOKING_FLOW.md) szerint működik. Query paramétereket nem használ. A HTML önmagában nem tartalmaz availability adatot; azt JavaScript tölti le.

### `GET /admin/login`

**Auth:** nincs. **PII:** nincs. **Cache:** nincs explicit szabály.

Jelenleg csak placeholder, `200 OK`:

```json
{"message":"Admin login endpoint placeholder"}
```

Nem jelenít meg login formot, nem ellenőriz credentialt és nem hoz létre sessiont.

### Nem található route vagy nem támogatott metódus

A router a path és HTTP-metódus pontos párosára illeszt. Eltérésnél `404` (nem `405`) érkezik:

```json
{"error":"Not found"}
```

## PLANNED – 1.0 API

Az alábbi szerződések céltervek; route-jaik nincsenek implementálva. Minden JSON API egységes `error: {code, message, fields?, request_id?}` formátumot, tartalomtípus-ellenőrzést, biztonságos `Cache-Control` értéket és a [biztonsági specifikáció](09_SECURITY.md) kontrolljait használja. A pontos admin jogosultsági modell az [admin és hitelesítés](04_ADMIN_AND_AUTHENTICATION.md) dokumentumban szerepel.

### `POST /api/bookings` – PLANNED

Publikus foglalási igény létrehozása. Body: a validate mezői, később elfogadott price quote/snapshot azonosítóval; fejléc: kötelező `Idempotency-Key`. Siker: `201` és nem találgatható `reference`, `status: pending`, az intervallum és publikus összegzés. Hibák: `400/415`, `422`, availability/idempotenciakonfliktus `409`, rate limit `429`, átmeneti `503`. PII nem tükrözhető szükségtelenül.

**Elfogadási feltételek:** tranzakciós availability recheck megakadályozza a double bookingot; retry nem duplikál; booking, vendégek, kezdeti history és price snapshot atomikusan mentődik; nincs e-mail-hiba miatti rollback; feature és concurrency teszt készül. Részletes flow: [foglalásmentés](03_PUBLIC_BOOKING_FLOW.md#planned--foglalásmentési-folyamat).

### Admin login és 2FA – PLANNED

- `POST /api/admin/auth/login`: e-mail+jelszó; sikeres első faktor után rövid életű challenge, általános válasz credential enumeration nélkül.
- `POST /api/admin/auth/2fa/verify`: challenge és hatjegyű kód; sikerre session rotation és `204` vagy admin összegzés.
- `POST /api/admin/auth/2fa/resend`: rate limit mellett új kód; a korábbi érvénytelenítése dokumentált.
- `POST /api/admin/auth/logout`: CSRF-védetten visszavonja a szerveroldali sessiont és törli a cookie-t.

**Elfogadási feltételek:** jelszó hashből ellenőrzött; login/kód rate limit és lockout tesztelt; kód nem naplózott és hashként tárolt; session ID belépéskor rotálódik; cookie `Secure`, `HttpOnly`, megfelelő `SameSite`; logout után a session nem használható. Lásd [admin és hitelesítés](04_ADMIN_AND_AUTHENTICATION.md).

### Booking admin API – PLANNED

- `GET /api/admin/bookings`: lapozott, szűrhető lista; PII csak jogosult adminnak; alapértelmezett rendezés dokumentált.
- `GET /api/admin/bookings/{id}`: booking, vendégek, ár-pillanatkép, státusztörténet és kapcsolódó naplók.
- `PATCH /api/admin/bookings/{id}`: explicit engedélyezett mezők és státuszátmenetek; optimistic version vagy `If-Match` a lost update ellen.

**Elfogadási feltételek:** minden route session-auth és CSRF-védett; invalid státuszváltás `409/422`; minden módosítás auditált; listában nincs szükségtelen érzékeny adat; jogosulatlan hozzáférés tesztje elkészül. Lásd [adatmodell](02_DATABASE_AND_DOMAIN_MODEL.md) és [admin](04_ADMIN_AND_AUTHENTICATION.md).

### Pricing API – PLANNED

- publikus `POST /api/pricing/quote`: valid intervallum és vendégösszetétel alapján időkorlátos quote;
- admin `GET/POST/PATCH /api/admin/pricing-rules...`: verziózott árszabály-kezelés.

**Elfogadási feltételek:** HUF és kerekítés determinisztikus; minden tétel visszakövethető; bookinghoz változtathatatlan snapshot készül; szabályütközés determinisztikusan kezelt; példatesztek lefedik a [pricing specifikációt](05_PRICING.md).

### Blocked periods API – PLANNED

`GET/POST/PATCH/DELETE /api/admin/blocked-periods...` fél-nyitott DATE intervallumok admin kezelésére. Törlés helyett auditálható inaktiválás választandó, ha adatmegőrzés szükséges.

**Elfogadási feltételek:** dátuminvariánsok és átfedési viselkedés tesztelt; változás auditált; ütköző booking esetén explicit `409`; módosítás után availability azonnal konzisztens; kizárólag admin érheti el.

### iCal sources és sync API – PLANNED

- `GET/POST/PATCH/DELETE /api/admin/ical/sources...`: források kezelése, secret URL visszaadása nélkül;
- `POST /api/admin/ical/sources/{id}/sync`: kézi importindítás;
- `GET /api/admin/ical/sources/{id}/runs`: eredmény- és hibanapló;
- tokenes, külön publikus export feed a később véglegesített útvonalon.

**Elfogadási feltételek:** SSRF-védelem és ICS limitek érvényesek; sync idempotens; saját feed loopja kizárt; timeout nem tart nyitva webkérést korlátlanul; napló nem szivárogtat tokent vagy PII-t. Lásd [iCal specifikáció](07_ICAL_SYNC.md).

### Settings API – PLANNED

`GET/PATCH /api/admin/settings` allowlistelt, típusos beállításokhoz; secret értékek külön kezelendők és read válaszban maszkolandók vagy teljesen kihagyandók.

**Elfogadási feltételek:** ismeretlen kulcs elutasított; típus és tartomány validált; módosítás auditált; konkurens frissítés nem ír felül észrevétlenül; biztonsági titok nem kerül response-ba vagy alkalmazáslogba. Lásd [biztonság](09_SECURITY.md).

### Általános planned API elfogadási feltételek

- stabil, dokumentált hibakód és HTTP-státusz minden hibához;
- JSON schema/contract és feature teszt minden végponthoz;
- admin write műveleteknél auth, authorization, CSRF és audit;
- publikus write műveleteknél rate limit, spam kontroll és idempotencia;
- minden PII-választ `Cache-Control: no-store` védi, availability cache-politikája külön döntés;
- verziózási stratégia és breaking-change szabály kiadás előtt rögzített;
- minden dátum `YYYY-MM-DD`, `Europe/Budapest`, fél-nyitott intervallum szerint értelmezett.
