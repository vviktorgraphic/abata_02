# Megerősített foglalás módosítása — IMPLEMENTED

## Hatókör

A teljes 2FA-val hitelesített admin kizárólag `confirmed` foglalás érkezési és távozási dátumát, felnőttszámát, gyermekszámát, gyermekéletkorait és opcionális egyedi végösszegét módosíthatja. A kapcsolattartó neve, e-mail-címe, telefonszáma és megjegyzése ezen a folyamaton nem szerkeszthető. Más státusz módosítása fail-closed HTTP 409 eredmény.

## Előnézet és validáció

Az admin előbb az „Új ár előnézete” műveletet futtatja. A szerver a publikus foglalással közös kapacitás- és gyermekéletkor-szabályokat, a 30 éjszakás és 365 napos határt, az aktív ársáv/érkezési override minimum–maximum tartózkodást, valamint a teljes aktuális pricing konfigurációt ellenőrzi. A publikus két napos előfoglalási korlát az admin módosításra nem vonatkozik; ezen túl új dátumszabály nincs bevezetve.

Az előnézeti űrlap sikeres és hibás válasz után is a stabil `#booking-modification` szakaszra pozicionál. A szerveroldali válasz megőrzi a beküldött mezőértékeket, és ugyanebben a blokkban mutatja az előnézetet vagy a validációs, foglaltsági és árképzési hibát; ehhez nem szükséges AJAX. Az admin foglalási összegzések a gyermekek számát és életkorát természetes magyar felsorolással jelenítik meg, például `3 (2, 6 és 11 éves)`.

Az `Egyedi végösszeg (opcionális)` egész HUF, az IFA-t is tartalmazó teljes foglalási összeg. Üresen a módosítás teljesen kalkulátoralapú. Kitöltve az IFA továbbra is a közös engine eredménye, az effektív szállásdíj pedig `egyedi végösszeg - kalkulált IFA`; IFA alatti összeg érvénytelen, IFA-val azonos összeg 0 Ft szállásdíjat jelent. Az előnézet mindig mutatja a kalkulált árat, felülbíráláskor pedig külön az egyedi szállásdíjat és végösszeget is.

Az előnézet aláírása a foglalási referenciához, a `modification_version` értékhez, az egyedi összeggel bővített kanonikus mezőkhöz és az effektív pricing hashhez kötött. A mentés minden ellenőrzést és a kalkulátorárazást megismétli, majd újraalkalmazza az aláírt admin felülbírálást. A publikus vagy aláírás nélküli kliensösszeg továbbra sem fogadható el. Stale verzió, megváltozott kalkulátor/IFA, effektív ár vagy foglaltság esetén új előnézet szükséges.

## Tranzakció, foglaltság és idempotencia

A mentés ugyanazt a `booking_inventory_locks` singleton sort zárolja, mint a publikus létrehozás, a megerősítés és a blokkolt időszak létrehozása. Az overlap `[arrival, departure)`; más `pending` vagy `confirmed` foglalás és minden aktív blocked period blokkol, a módosított foglalás saját azonosítója kizárt.

Egyetlen adatbázis-tranzakció frissíti a dátumokat, éjszakaszámot eredményező intervallumot, létszámot, gyermekéletkorokat, aktuális effektív végösszeget és a teljes pricing snapshotot; ugyanitt készül a `booking_modifications` before/after rekord és a `booking_modified` audit. Az idempotencia/request hash az opcionális egyedi összeget is köti. A `before_snapshot` a korábbi teljes pricing snapshotot és annak esetleges korábbi `price_override` provenance-ét változatlanul megőrzi; az `after_snapshot` az új effektív top-level összegeket és a kalkulált/egyedi metadata-t tartalmazza. A mentés nem készít e-mail outbox rekordot és nem végez SMTP-műveletet. A státusz nem változik, ezért státusztörténet nem készül. A művelet nagy entrópiájú, bookinghoz kötött idempotenciakulcsot használ időalapú takarítás nélkül.

## Árazás, előleg és lemondás

A módosítás a közös pricing engine aktuális konfigurációjával teljesen újraszámol: occupancy/person alap, dátumoverride, minimum/maximum tartózkodás, egyéjszakás felár, IFA, kapacitás és gyermek fizetőképesség mind ugyanabból a motorból jön. Az admin-only override réteg csak ezután alkalmazza az opcionális egyedi végösszeget; a publikus pricing engine változatlan. A régi és új dátum/létszám/ár, valamint minden korábbi és új felülbírálási provenance az auditált before/after snapshotban megmarad.

A már elküldött díjbekérő payloadja, százaléka és előlegösszege egyedi módosításnál is változatlan; nincs új 50%-os számítás, díjbekérő, automatikus pótfizetés vagy visszatérítés. Díjbekérő nélküli megerősítés után az előleg továbbra is `null` / „nem áll rendelkezésre”, nem 0 Ft. A frissített pricing snapshot effektív `accommodation_fee` értéke lesz egy későbbi lemondás változatlan 7 napos/50%-os szabályának alapja.

## Vendégértesítés

A mentés után a vendég nem kap automatikus levelet; a részletoldal ezt külön visszajelzi. Az admin kizárólag a legutóbbi módosításhoz indíthatja el a `booking_modified` értesítést a `POST /admin/bookings/{reference}/modifications/{modificationId}/send-notification` művelettel. A szerver a bookinghoz tartozást és a legutolsó verziót is ellenőrzi; régebbi módosításra küldött crafted kérés `409` eredményű.

Az első explicit küldési szándék az immutable `after_snapshot` alapján, egyetlen tranzakcióban hozza létre és rögtön `processing` állapotba claimeli a `modification:{id}` kulcsú outbox rekordot. Kettős vagy párhuzamos kérésből csak egy rekord és egy első SMTP-kísérlet lehet. A plain text és HTML levél az új időszakot, létszámot, effektív szállásdíjat, IFA-t, végösszeget és a változatlan korábbi előleget tartalmazza. Az `Egyedi végösszeg` sor kizárólag tényleges felülbírálásnál jelenik meg; normál kalkulátoralapú módosítás leveléből teljesen hiányzik. Sikertelen explicit kísérlet `failed` állapotban a közös Phase 10 retry policy szerint automatikusan újrapróbálható; öt kísérletnél lezárul. Már elküldött történelmi rekord nem változik.

## Séma és üzemeltetés

A `028_add_confirmed_booking_modifications.sql` hozzáadta a booking optimista verzióját, a módosítási naplót és az outbox `deduplication_key` mezőjét. Az opcionális egyedi végösszeghez nincs új migráció: a meglévő JSON snapshotok és DECIMAL végösszeg elegendő. Lifecycle worker, cronaktiválás, production környezet, élő adat és deploy nem változik.

PowerShell ellenőrzés:

```powershell
docker compose exec app composer migrate
docker compose exec app vendor/bin/phpunit --testsuite Unit
docker compose exec app vendor/bin/phpunit --testsuite Feature
docker compose exec app vendor/bin/phpunit
.\tools\Invoke-MigrationCompatibility.ps1
git diff --check
```
