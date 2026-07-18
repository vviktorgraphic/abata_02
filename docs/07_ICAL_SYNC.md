# iCal szinkron

**Állapot:** Sprint 7 alaphatókör IMPLEMENTED; az ütemezett és haladó reconciliation funkciók PLANNED
**Utolsó felülvizsgálat:** 2026-07-16

## Hatókör és alapelv

**IMPLEMENTED:** A belső foglalási modell naptári napokat és fél-nyitott `[arrival_date, departure_date)` intervallumot használ. A Sprint 7 RFC 5545 parsert és exportert, Google Calendar és Szallas.hu importforrást, külön külsőesemény/blocked-period leképezést, szinkronnaplót, admin forráskezelést és kézi szinkront, valamint tokenes publikus exportot ad. Külső esemény soha nem booking, és import nem módosít belső bookingot.

Az export URL `GET /calendar/export.ics?token=...`. Csak `confirmed` booking és aktív blocked period exportálódik; `pending`, `rejected`, `cancelled` és `invalidated` booking nem. A token capability secret, csak SHA-256 hashként tárolódik, rotálható, érvénytelen tokenre megkülönböztethetetlen `404` érkezik, a válasz pedig `private, no-store`. A feed nem tartalmaz vendégadatot.

Az import kézzel indítható. Azonos `(source_id, UID)` és változatlan payload idempotens; confirmed bookinggal való átfedés figyelmeztetésként naplózódik és nem hoz létre duplikált blocked periodot. A napló a kezdést/befejezést, import/export darabszámot, figyelmeztetéseket és hibákat tárolja. Minden üzleti nap `Europe/Budapest` szerint, a távozási nap exkluzívan értendő.

**PLANNED:** cron/automatikus időzítés, retry/backoff, eltűnt események türelmi ideje, manuális konfliktusfeloldás és export-token rotációs grace period. Ezekhez nincs elfogadott üzleti szabály, ezért a Sprint 7 nem talál ki alapértéket.

**IMPLEMENTED:** Az 1.0 alaphatókör külső RFC 5545 naptárakból kézi indítással foglaltságot importál, és tokennel védett, személyes adatot nem tartalmazó export feedet ad. A sync **nem valós idejű**, ezért mentéskor a belső foglalhatóságot mindig újra kell ellenőrizni. **PLANNED:** automatikus időzítés. A domainmodell részletei: [adatbázis- és domainmodell](02_DATABASE_AND_DOMAIN_MODEL.md), a kapcsolódó fenyegetések: [biztonság](09_SECURITY.md).

> **RESOLVED:** támogatott szolgáltatók: Google Calendar és Szallas.hu; `pending` nem exportálódik. **DECISION REQUIRED:** cron gyakoriság és eseményeltűrés türelmi ideje.

## RFC 5545 eseménymodell

### Egész napos időszak

**IMPLEMENTED export:** Minden exportált foglaltsági esemény egész napos `VEVENT`. Az érkezés `DTSTART;VALUE=DATE`, a távozás exkluzív `DTEND;VALUE=DATE`. A `2026-08-01`–`2026-08-05` tartózkodás négy éjszaka, és így jelenik meg:

```ical
BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//A Bata//Booking Calendar 1.0//HU
CALSCALE:GREGORIAN
METHOD:PUBLISH
BEGIN:VEVENT
UID:booking-public-id@example.invalid
DTSTAMP:20260716T120000Z
DTSTART;VALUE=DATE:20260801
DTEND;VALUE=DATE:20260805
SEQUENCE:0
STATUS:CONFIRMED
SUMMARY:Foglalt
TRANSP:OPAQUE
END:VEVENT
END:VCALENDAR
```

- `UID`: forráson belül tartós, nem újrahasznosítható eseményazonosító. Exportban véletlenszerű publikus azonosítóból és a rendszer által kezelt domainből képzendő; belső adatbázis-ID és vendégadat nem használható.
- `SEQUENCE`: tartalmi módosításkor monoton növekszik. Azonos vagy kisebb sequence önmagában nem írhat felül frissebb lokális verziót; hibás szolgáltató miatt a `DTSTAMP` és a tartalom hash is összevetendő.
- `DTSTAMP`: UTC időpont (`...Z`), amikor az eseményverzió létrejött. Nem a foglalás helyi dátuma.
- `DTSTART;VALUE=DATE`: inkluzív első foglalt naptári nap.
- `DTEND;VALUE=DATE`: exkluzív távozási nap; az aznap kezdődő következő foglalás nem ütközik.
- `STATUS:CONFIRMED`: aktív foglaltság. `TENTATIVE` kezelési módja szolgáltatónként konfigurálandó.
- `STATUS:CANCELLED`: az ismert `UID` törlésjelzése; nem aktív foglaltság, de az eseményt audit célból meg kell őrizni.
- `SUMMARY`: exportban általános szöveg, például `Foglalt`; vendégnév, e-mail, telefonszám, ár és admin megjegyzés tilos.

**IMPLEMENTED validáció:** teljes, szabályosan lezárt `VCALENDAR`/`VEVENT`, érvényes `DTSTART < DTEND`, CRLF/LF sortörés, line folding, escape-elés, feed-/esemény-/sorhosszlimit. A parser a `DATE` mellett `DATE-TIME` értéket is elfogad; UTC vagy `TZID` alapján `Europe/Budapest` időre váltja, az import pedig minden érintett helyi naptári napot blokkol.

> **SPECIFICATION CONFLICT:** a korábbi terv a `DATE-TIME` csendes naposítását tiltotta, a Sprint 7 tényleges kompatibilitási szabálya viszont Budapest-napokra alakítja. A kód szerinti viselkedés itt explicit dokumentált; további szolgáltatóspecifikus kivételhez új tulajdonosi döntés szükséges.

## Adatszétválasztás

**IMPLEMENTED alaphatókör:** A külső esemény nem `booking`. Külön iCal-forrás, importált esemény, sync log és exporttoken tábla létezik. Az alábbi lista egy része haladó **PLANNED** mezőket is tartalmaz:

- forrás: név, titkosított vagy web rooton kívüli URL, aktív állapot, timeout, utolsó próbálkozás, utolsó siker, következő retry, ETag/Last-Modified, hibaállapot;
- esemény: source ID, `UID`, `SEQUENCE`, `DTSTAMP`, `DTSTART`, `DTEND`, `STATUS`, `raw_hash`, `first_seen_at`, `last_seen_at`, `missing_since`, feldolgozási állapot;
- napló: futásazonosító, forrás, kezdet/vég, HTTP-eredmény, elemszámok, retry-szám, sanitizált hiba és konfliktusszám;
- export: belső rekordhoz rendelt stabil publikus `UID`, aktuális `SEQUENCE`, utolsó exportálható tartalom hash.

Minden sémabővítéshez új verziózott migráció és automatikus teszt kötelező. A foglalási napok adatbázisban `DATE` típusúak, a kezelési időzóna `Europe/Budapest`.

## Importfolyamat

**PLANNED folyamat:**

1. A cron egy globális zárral megakadályozza ugyanazon import párhuzamos futását; forrásonként külön idempotens feldolgozás történik.
2. A rendszer csak engedélyezett `https` URL-ről kér le, DNS/IP ellenőrzéssel, átirányítási korláttal, válaszméret- és időlimittel. Részletek: [biztonság](09_SECURITY.md).
3. Feltételes HTTP-kérés használható `ETag`/`If-Modified-Since` alapján. A hitelesítési adat és teljes feed URL nem kerülhet naplóba.
4. Sikertelen hálózati kérés vagy parse esetén az előző sikeres állapot változatlan marad; részleges feed nem írhatja felül.
5. Sikeres parse után az esemény kulcsa `(source_id, UID)`. A kanonizált releváns mezőkből `raw_hash` készül, így változatlan esemény újrafuttatása nem okoz írást vagy sequence-növelést.
6. Minden látott esemény `last_seen_at` értéke frissül. Új vagy módosult eseménynél újra lefut a dátum- és konfliktusellenőrzés.
7. `STATUS:CANCELLED` esetén az ismert rekord törölt állapotot kap; fizikailag nem törlődik azonnal. Ismeretlen cancelled UID naplózható, de foglaltságot nem hoz létre.
8. Csak a teljes forrás sikeres feldolgozása után rögzíthető az utolsó sikeres szinkron és az eltűnt események vizsgálata.

### Timeout, retry és backoff

**PLANNED:** A kapcsolódási és teljes kérési timeout külön konfigurálandó. Tranziens hálózati hiba, `429` vagy `5xx` válasz exponenciális backoffot és jittert kap; `Retry-After` tiszteletben tartandó. `4xx` konfigurációs hibánál – a `408`/`429` kivételével – automatikus sűrű retry helyett admin figyelmeztetés szükséges. Egy hibás forrás nem állíthatja meg a többit.

> **DECISION REQUIRED:** Konkrét timeout, maximum retry, backoff és cron értékek üzemeltetési mérés után rögzítendők. Kiinduló, nem végleges javaslat: 15 perces cron, 5/20 másodperces connect/total timeout, legfeljebb 4 retry.

### Eltűnt és törölt esemény

Az eltűnés nem azonos a törléssel: egy külső szolgáltató átmenetileg csonka feedet adhat.

- explicit `STATUS:CANCELLED`: az esemény azonnal inaktívvá tehető, de konfliktus és audit nyoma megmarad;
- sikeres teljes importból hiányzó korábbi UID: `missing_since` jelölést kap, de a türelmi idő alatt továbbra is blokkol;
- több egymást követő sikeres importból hiányzó esemény és letelt türelmi idő: inaktiválható, naplózva;
- sikertelen vagy részleges import: nem indíthat eltűnési számlálást;
- újra megjelenő UID: a hiányjelölés törlődik, és a verziószabályok szerint frissül.

Ez a konzervatív modell csökkenti a külső késleltetésből vagy ideiglenes feedhibából eredő dupla foglalás kockázatát.

## Export feed és tokenvédelem

**IMPLEMENTED:** Az export read-only, hosszú, kriptográfiailag véletlen tokennel elérhető feed. A token capability secret: nem kerül Gitbe, analyticsbe vagy általános adminnaplóba; adatbázisban csak SHA-256 hash formában tárolódik és rotálható. Query-token miatt a production webszerver access logjából a query stringet ki kell zárni. Rotációkor nincs átfedési idő.

Az admin-only rotációs válasz szándékos, egyszeri kivétel a „token ne kerüljön HTML-be” általános szabály alól: teljes 2FA session, CSRF és admin action guard után az új plaintext token egyetlen HTML-válaszban jelenik meg, később nem kérhető le újra. Általános oldalon, listában vagy naplóban nem jelenhet meg.

- HTTPS kötelező; CORS nem szükséges általánosan.
- Válasz `Content-Type: text/calendar; charset=utf-8` és `Content-Disposition: inline`.
- Feedben csak a blokkolt dátum, stabil publikus UID, verzió- és technikai mezők szerepelhetnek.
- Vendégnév, kapcsolati adat, létszám, ár, belső megjegyzés és növekvő belső ID tilos.
- Cache-elés csak tokenvédelmet és visszavonást figyelembe vevő rövid szabállyal engedhető; proxyban nyilvános cache tilos.
- Token gyanított kiszivárgásakor azonnali rotáció és audit szükséges.

> **RESOLVED:** az exporttoken query paraméterben van. **DECISION REQUIRED:** rotációs türelmi idő; jelenleg nincs átfedési idő implementálva.

## Loop prevention

**PLANNED:** A saját export visszaimportálását több, egymást erősítő kontroll akadályozza:

1. saját export URL/domain és token fingerprint nem vehető fel importforrásként;
2. export `PRODID` és UID namespace azonosítható;
3. a forrás konfiguráció tárolja az eredetet; az ismert saját UID namespace importja elutasítandó vagy karanténba helyezendő;
4. importált külső esemény alapértelmezetten nem kerül vissza az exportba;
5. ugyanazon külső esemény több feedből érkező másolata nem egyesíthető automatikusan pusztán dátum alapján.

> **SPECIFICATION CONFLICT:** a jelenlegi export repository minden aktív blocked periodot exportál, így az importból létrejött blocked periodot is. Ez ellentmond a fenti PLANNED loop-prevention 4. pontnak. A Sprint 7 nem tartalmaz eredet szerinti exportszűrést; ezt nem tekintjük implementáltnak, és külön specifikáció/megoldás szükséges.

Példa: ha egy partner a rendszer exportját visszaadja saját feedjében, a saját UID felismerése miatt nem jön létre újabb blokkolás és nincs végtelen tükröződés.

## Konfliktuskezelés

**IMPLEMENTED alap:** Import előtt a fél-nyitott overlap formula alkalmazandó: `incoming_start < existing_end AND incoming_end > existing_start`. A külső esemény és a belső foglalás külön rekord marad. Confirmed bookinggal ütközés figyelmeztetés és nem módosít bookingot; manuális konfliktusfeloldás **PLANNED**.

- külső esemény ütközik `confirmed` bookinggal: egyik sem törlődik; magas prioritású konfliktus készül és az admin értesítést kap;
- külső esemény ütközik `blocked_period` rekorddal: konfliktus naplózandó, a nap továbbra is blokkolt;
- két külső forrás eseménye ütközik: mindkettő megmarad, deduplikálás csak bizonyított közös azonosító alapján lehetséges;
- határnapos egymásutániság (`A.DTEND = B.DTSTART`) nem konfliktus;
- a publikus availability a bizonytalan konfliktus alatt konzervatívan blokkoljon.

Az admin manuálisan „elfogadott duplikátum”, „külső esemény téves”, „belső foglalás rendezendő” vagy „megoldva” döntést rögzíthet indoklással. A feloldás nem írhatja át észrevétlenül a külső nyers állapotot; adminazonosítóval, időponttal és előtte/utána állapottal auditálandó.

## Adminfelület és megfigyelhetőség

**IMPLEMENTED alaphatókör:** Az admin iCal modul forráslistát, maszkolt URL-t, engedélyezést, CRUD-ot, kézi syncet, sync logot és exporttoken-rotációt ad. **PLANNED:** retry-késleltetés, riasztás, konfliktusfeloldás és automatizált frissességi monitoring. A teljes célállapot:

- forrás nevét és engedélyezett állapotát, maszkolt URL-jét;
- utolsó próbálkozást, utolsó sikeres szinkront, következő retryt és késleltetést;
- utolsó HTTP/parse eredményt secret és nyers PII nélkül;
- új, módosult, törölt, hiányzó, figyelmen kívül hagyott és konfliktusos eseményszámot;
- kézi „szinkron most” műveletet rate limittel és ugyanazzal a zárolással;
- konfliktuslistát, feloldási műveletet és auditot;
- export token létrehozását, rotációját és visszavonását, a token egyszeri megjelenítésével.

Riasztás szükséges, ha egy aktív forrás a meghatározott küszöbnél régebben nem szinkronizált sikeresen, ismételten hibázik, vagy új konfliktust hoz létre. A külső szolgáltató frissítési gyakorisága ismeretlen lehet; a UI az adat korát mutassa, és ne ígérjen azonnali konzisztenciát.

## Cron cPanel környezetben

**PLANNED:** A production cron shell-semleges lényege egy PHP CLI belépési pont futtatása abszolút útvonalakkal:

```text
/usr/local/bin/php /home/CPANEL_USER/app/bin/ical-sync.php
```

A tényleges PHP bináris és home útvonal tárhelyenként eltér, ezért a cPanel „Cron Jobs” felületén az üzemeltető állítja be; titok nem szerepelhet a parancsban, azt a web rooton kívüli `.env` biztosítja. A CLI nem támaszkodhat PowerShellre, Dockerre, Node.js-re vagy interaktív shellre, relatív munkakönyvtárra sem. A cron kimenete ne tartalmazzon feed URL-t vagy tokent; strukturált alkalmazásnaplóba írjon, hibánál nem nulla exit kóddal.

Helyi fejlesztésben a későbbi Composer script PowerShell-kompatibilis hívása:

```powershell
docker compose exec app composer ical:sync
```

Ez a script jelenleg **PLANNED**, nem szerepel a `composer.json` fájlban.

## Elfogadási feltételek

Az iCal modul csak akkor tekinthető elkészültnek, ha:

1. RFC 5545 fixture tesztek igazolják a `DATE` alapú inkluzív/exkluzív értelmezést, line foldingot, módosítást és `CANCELLED` kezelést;
2. ismételt, változatlan import idempotens, a `raw_hash`, `last_seen` és eltűnési szabály tesztelt;
3. timeout/retry, hibás vagy túlméretes ICS és SSRF kontroll tesztelt;
4. saját export visszaimportja nem hoz létre eseményt, az importált esemény nem tükröződik vissza;
5. konfliktus nem ír felül automatikusan belső foglalást, manuális feloldása auditált;
6. export `DTEND` exkluzív, stabil UID/SEQUENCE értéket ad, cancellationt szabályosan jelez, és nem tartalmaz PII-t;
7. token rotálható és visszavonható, naplóban nem jelenik meg;
8. cron újrafutás, párhuzamos futás és részleges forráshiba integrációs teszttel igazolt;
9. stagingben szolgáltatói késleltetéssel és restore után is lefutott smoke teszt dokumentált.

## Kapcsolódó dokumentumok

- [Projektáttekintés](00_PROJECT_OVERVIEW.md)
- [Architektúra](01_ARCHITECTURE.md)
- [Adatbázis- és domainmodell](02_DATABASE_AND_DOMAIN_MODEL.md)
- [Admin és hitelesítés](04_ADMIN_AND_AUTHENTICATION.md)
- [API referencia](08_API_REFERENCE.md)
- [Biztonság](09_SECURITY.md)
- [Tesztelés és üzemeltetés](10_TESTING_AND_OPERATIONS.md)
- [Roadmap és döntési napló](11_ROADMAP_AND_DECISIONS.md)
