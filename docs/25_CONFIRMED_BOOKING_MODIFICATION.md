# Megerősített foglalás módosítása — IMPLEMENTED

## Hatókör

A teljes 2FA-val hitelesített admin kizárólag `confirmed` foglalás érkezési és távozási dátumát, felnőttszámát, gyermekszámát és gyermekéletkorait módosíthatja. A kapcsolattartó neve, e-mail-címe, telefonszáma és megjegyzése ezen a folyamaton nem szerkeszthető. Más státusz módosítása fail-closed HTTP 409 eredmény.

## Előnézet és validáció

Az admin előbb az „Új ár előnézete” műveletet futtatja. A szerver a publikus foglalással közös kapacitás- és gyermekéletkor-szabályokat, a 30 éjszakás és 365 napos határt, az aktív ársáv/érkezési override minimum–maximum tartózkodást, valamint a teljes aktuális pricing konfigurációt ellenőrzi. A publikus két napos előfoglalási korlát az admin módosításra nem vonatkozik; ezen túl új dátumszabály nincs bevezetve.

Az előnézet aláírása a foglalási referenciához, a `modification_version` értékhez és a kanonikus mezőkhöz kötött. A mentés minden ellenőrzést és az árazást megismétli; kliensről érkező összeget nem fogad el. Stale verzió, megváltozott adatok vagy foglaltság esetén új előnézet szükséges.

## Tranzakció, foglaltság és idempotencia

A mentés ugyanazt a `booking_inventory_locks` singleton sort zárolja, mint a publikus létrehozás, a megerősítés és a blokkolt időszak létrehozása. Az overlap `[arrival, departure)`; más `pending` vagy `confirmed` foglalás és minden aktív blocked period blokkol, a módosított foglalás saját azonosítója kizárt.

Egyetlen adatbázis-tranzakció frissíti a dátumokat, éjszakaszámot eredményező intervallumot, létszámot, gyermekéletkorokat, aktuális végösszeget és a teljes pricing snapshotot; ugyanitt készül a `booking_modifications` before/after rekord, a `booking_modified` audit és a deduplikált e-mail outbox. A státusz nem változik, ezért státusztörténet nem készül. A művelet nagy entrópiájú, bookinghoz kötött idempotenciakulcsot használ időalapú takarítás nélkül.

## Árazás, előleg és lemondás

A módosítás a közös pricing engine aktuális konfigurációjával teljesen újraszámol: occupancy/person alap, dátumoverride, minimum/maximum tartózkodás, egyéjszakás felár, IFA, kapacitás és gyermek fizetőképesség mind ugyanabból a motorból jön. A régi és új dátum/létszám/ár az auditált before/after snapshotban megmarad.

A már elküldött díjbekérő payloadja, százaléka és előlegösszege változatlan; nincs új 50%-os számítás, díjbekérő, automatikus pótfizetés vagy visszatérítés. A frissített pricing snapshot `accommodation_fee` értéke lesz egy későbbi lemondás változatlan 7 napos/50%-os szabályának alapja.

## Vendégértesítés

Commit után a rendszer `booking_modified` levelet próbál küldeni `Foglalásának adatai módosultak` tárggyal, a központi From/Reply-To konfigurációval. A plain text és HTML levél az új időszakot, létszámot, szállásdíjat, IFA-t, végösszeget és a változatlan korábbi előleget tartalmazza. SMTP-hiba nem görgeti vissza a módosítást; az outbox `failed` marad és az admin részletoldalról újraküldhető. Egy sikeres módosítási levél ugyanahhoz a módosításazonosítóhoz nem küldhető kétszer.

## Séma és üzemeltetés

A `028_add_confirmed_booking_modifications.sql` hozzáadja a booking optimista verzióját, a módosítási naplót és az outbox `deduplication_key` mezőjét. A migráció additív, MySQL 8 és MariaDB 10.6 kompatibilis. Lifecycle worker, cronaktiválás, production környezet, élő adat és deploy nem változik.

PowerShell ellenőrzés:

```powershell
docker compose exec app composer migrate
docker compose exec app vendor/bin/phpunit --testsuite Unit
docker compose exec app vendor/bin/phpunit --testsuite Feature
docker compose exec app vendor/bin/phpunit
.\tools\Invoke-MigrationCompatibility.ps1
git diff --check
```
