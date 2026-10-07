# Occupancy IFA, áradmin és díjbekérő

**IMPLEMENTED — 2026-10-05, release/rc2.** Ez a kiegészítés a korábbi person-admin és díjbekérő nélküli megerősítés leírását az alábbi pontokon felváltja. Production aktiválás, adatbázis-migráció és valós SMTP/böngészős ellenőrzés külön release lépés.

## Árképzés és IFA

A kanonikus `/admin/pricing` occupancy alapársávokat és egyedi időszakos árakat kezel. Az alapársáv dátumtól független; azonos létszám aktív tartózkodáshossz-sávjai nem fedhetik egymást. A felső korlát nélküli sáv a felületen `Korlátlan`. Éves/szezonális ár az egyedi időszakok alatt adható meg, például `2027-01-01`–`2027-12-31`, mind a négy létszám árával. Az időszakok inkluzívak, az aktív időszakok átfedése tiltott. Nincs automatikus sáv-inaktiválás vagy rejtett prioritás.

A sávok és időszakok külön, teljes szélességű lenyitható szerkesztőpanelt kapnak. Az aktiválás külön űrlap. A HUF megjelenítés például `22 000 Ft`, a pénzbeviteli érték `22 000`. A szerver elfogadja a `22000`, `22 000`, NBSP-vel tagolt `22 000`, illetve `22000.00` alakot. Tört, negatív, exponenciális és vesszős érték tiltott. Tárolás továbbra is DECIMAL-string, nincs lebegőpontos pénzfeldolgozás.

A `025_add_occupancy_tourism_tax.sql` forward migráció a konfigurációhoz `tourism_tax_per_person_per_night DECIMAL(12,2) NOT NULL DEFAULT 0.00` mezőt ad. A 024 változatlan. Az IFA mentése ugyanazt az optimistic verziót növeli, és `occupancy_pricing.tourism_tax_updated` auditot készít; stale verzióra 409 érkezik.

Minden támogatott pricing módban IFA = konfigurált egységár × felnőttek × éjszakák. A publikus modellben a felnőttek 18 évesek vagy idősebbek; a 0–17 évesként rögzített gyermekek nem növelik az adóköteles mennyiséget. A meglévő alkalmazható exemption adómentességet biztosít; ilyen esetben az adóköteles mennyiség és az adó nulla. A legacy `tourism_tax` összegek occupancy módban kimaradnak. A snapshot tárolja az IFA egységárát, a `tourism_tax_quantity` mennyiséget és a `taxes` összeget. Quote, booking, admin preview és e-mail ugyanazt az adaptert/engine-t használja. A korábbi snapshotok nem számolódnak újra.

**Üzemeltetési következmény:** migráció után az új occupancy IFA alapértéke nulla; nincs feltételezett production adóérték vagy automatikus legacy-IFA átvétel. A jóváhagyott összeget az admin külön beállítja az éles aktiválás során.

## Egyedi időszakos tartózkodási korlátok — IMPLEMENTED

A `027_add_occupancy_override_stay_limits.sql` forward migráció minden occupancy date override-hoz kötelező `min_nights` és opcionális `max_nights` mezőt ad. A meglévő sorok biztonságos alapértéke `min_nights=1`, `max_nights=NULL`, ezért a korábbi korlátlan működésük megmarad. A domain és az adatbázis 1–30 éjszaka közötti, egymással konzisztens értékeket fogad el.

A teljes foglalás tartózkodáshossz-szabályát kizárólag az érkezési nap választja ki. Ha az érkezés aktív egyedi időszakba esik, annak minimuma és maximuma irányadó akkor is, ha a távozás túlnyúlik az időszakon. Ha az érkezés nem esik override-ba, a normál occupancy alapársáv tartózkodáshossz-szabálya érvényes. Az éjszakánkénti ár ettől függetlenül minden napra külön oldódik fel: az override által fedett éj override árat, a többi éj a megfelelő alapárat kapja. Teljesen override-dal fedett, érvényes tartózkodás nem igényel alkalmazható alapársávot; fedetlen éjhez viszont hiányzó alapár esetén a kalkuláció fail-closed.

A publikus quote, a booking create, az admin preview és az immutable booking snapshot ugyanazt a közös engine-szabályt használja. Normál tartózkodáshossz-hibára magyar, mezőszintű `422` válasz érkezik. Az occupancy snapshot verziója 5; rögzíti az érkezési override azonosítóját, minimumát és maximumát, a governing stay rule-t, az éjszakánkénti forrást és az összes alkalmazott override azonosítóját. Korábbi snapshot nem módosul és nem számolódik újra.

Az admin **Egyedi időszakos árak** létrehozó és szerkesztő űrlapja kezeli a minimum és opcionális maximum éjszakát; az üres maximum korlátlant jelent. A mentés az occupancy konfiguráció meglévő optimistic verzió-, CSRF- és auditfolyamatát használja. A 2027-es működéshez nincs automatikus január 1-jei átállás: amikor az új standard ár válik aktuálissá, a tulajdonos módosítja az alapárakat, majd inaktiválja a teljes éves override-ot; ezután csak a valódi különleges időszakok maradnak aktív override-ként.

## Díjbekérő és megerősítés

Csak pending foglaláshoz indítható díjbekérő. A védett `POST /admin/bookings/{reference}/payment-request` teljes admin sessiont, CSRF-et, form Content-Type/body-limitet és rate limitet igényel. Ugyanez a végpont indítja a sikertelen levél újraküldését. Hiányzó foglalás 404, nem pending foglalás 409, hibás banki/előleg-konfiguráció 422; konfigurációs hibánál nincs SMTP-kísérlet.

A `booking_payment_request` a meglévő `email_outbox` táblába kerül. A `026` utáni egyediség booking/message-type/recipient alapú; mivel a díjbekérőnek egy vendég címzettje van, ebből továbbra is foglalásonként egy rekord készül. A foglalás zárolása, a payload létrehozása és a `processing` claim tranzakciós; SMTP csak commit után fut. A küldés nem vált foglalási státuszt és nem foglal kapacitást. A levél `sent` állapota SMTP-átvételt jelent, nem banki jóváírást vagy garantált inbox-kézbesítést.

A teljes, tárolt booking végösszeg az előleg alapja, nem csak a szállásdíj és nem az aktuális árlista. Az alapbeállítás 50%; páratlan egész-HUF végösszegből az előleg egész forintra HALF_UP kerekített. A kerekítés egész számokkal történik. A feladat konfigurációs követelményének megfelelően a százalék 1–100 között beállítható; az alapérték és a példafájl értéke 50. A levél a tényleges, payloadba rögzített százalékot mutatja.

Konfiguráció a `config/payment-request.php` fájlon keresztül:

- `PAYMENT_REQUEST_BENEFICIARY`: kedvezményezett;
- `PAYMENT_REQUEST_BANK_ACCOUNT`: bankszámlaszám;
- `PAYMENT_REQUEST_ADVANCE_PERCENT=50`.

A repository csak üres vagy placeholder bankadatokat tartalmaz a példafájlokban. Hiányzó bankadat nem akadályozza az admin bejelentkezést; a küldés ad konfigurációs hibát. Ismeretlen árú régi importból nem készül kitalált nulla összegű díjbekérő.

A TXT/HTML levél tartalmazza a vendégnevet, referenciát, dátumokat, teljes összeget, előleget, kedvezményezettet és bankszámlát. A közlemény kötelezően a booking referencia. A szöveg nem nevez számlának vagy hivatalos számviteli bizonylatnak semmit. A véglegességet az előleg jóváírásához és a későbbi visszaigazoláshoz köti.

A retry ugyanazt a payloadot használja: recipient, név, referencia, dátumok, pénznem, végösszeg, százalék, előleg és bankadatok az első kéréskor rögzülnek. Új ár vagy banki konfiguráció nem írja át. `sent` és `processing` rekord ismételt kattintása nem küld új levelet. SMTP-hiba `failed`, a foglalás pending marad. Sikeres SMTP utáni persistence/audithiba nem teszi automatikusan újraküldhetővé a levelet.

A `POST /admin/bookings/{reference}/confirm` csak `sent` díjbekérő mellett sikerülhet: a meglévő inventory/booking zároláson belül szerveroldali ellenőrzés fut. Közvetlen hamisított POST is 409, és `booking.confirm_blocked_payment_request_missing` audit készül. Elutasítás/érvénytelenítés nem függ a díjbekérőtől. A későbbi megerősítés a korábbi confirmed outboxot és státuszlevelet továbbra is létrehozza.

Az admin részleten a „Foglalás véglegesítése” panel mutatja az előleget, küldés/újraküldés állapotát és az elküldés időpontját. Megerősítés csak sikeres küldés után jelenik meg. A fizetés ellenőrzése kézi adminfeladat. Az e-mail listában a típus neve `Díjbekérő`.

Audit: `email.payment_request_sent`, `email.payment_request_failed`, `email.payment_request_retry`, `booking.confirm_blocked_payment_request_missing`. A metadata nem tartalmaz bankadatot vagy vendég PII-t.

**PLANNED / nem része:** automatikus banki egyeztetés, pénzbeszedés, számlázás, automatikus outbox retry és beragadt processing reclaim. SMTP-elfogadás utáni transport timeout esetén a meglévő általános duplikációs kockázat megmarad; nincs feltételezett provider-idempotencia.

## Ellenőrzés — PowerShell

Izolált fejlesztői/tesztkörnyezetben:

```powershell
git diff --check
docker compose exec app vendor/bin/phpunit tests/Unit
docker compose exec app vendor/bin/phpunit tests/Feature
docker compose exec app vendor/bin/phpunit
./tools/Invoke-MigrationCompatibility.ps1
./tools/New-ReleasePackage.ps1 -Commit (git rev-parse HEAD)
./tools/Verify-ReleasePackage.ps1 -ArchivePath ./release-packages/foglalo-<commit-short>.tar.gz
```

A PHP-/adatbázistesztek, MySQL 8 / MariaDB 10.6 migrációs próba és böngészős smoke eredménye külön release-bizonyíték; a tesztfájlok megléte önmagában nem igazol sikeres futást. A release source csomag külön dependency telepítést igényel. A publikus booking CSS/JS és fingerprint mapping változatlan; csak az admin CSS módosul.
