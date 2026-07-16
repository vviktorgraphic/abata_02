# Árképzés

**Állapot:** minimális Sprint 4 kalkuláció és snapshot IMPLEMENTED + bővített 1.0 modell PLANNED
**Utolsó ellenőrzött commit:** `9adc564`

Ez a dokumentum az 1.0 árkalkuláció implementálható keretét rögzíti. Nem határoz meg végleges üzleti árakat. Kapcsolódó specifikációk: [adatbázis- és domainmodell](02_DATABASE_AND_DOMAIN_MODEL.md), [publikus foglalási folyamat](03_PUBLIC_BOOKING_FLOW.md), [API referencia](08_API_REFERENCE.md), [biztonság](09_SECURITY.md), [roadmap és döntések](11_ROADMAP_AND_DECISIONS.md).

## 1. Jelenlegi állapot — IMPLEMENTED

- A `pricing_rules` tábla dátumtartományt (`valid_from` inkluzív, `valid_until` exkluzív), `nightly_price` értéket, `minimum_nights`, `priority` és `is_active` mezőket tárol.
- A `bookings` táblában `total_amount DECIMAL(12,2)` és alapértelmezetten `HUF` pénznem található.
- A publikus validáció dátumot és vendégszámot ellenőriz, de nem számol és nem ment árat.
- Nincs pricing repository, kalkulátor, admin pricing UI, ár-pillanatkép, kedvezmény-, díj-, adó- vagy override-modell.
- A `pricing_rules.nightly_price` jelentése a sémából nem dönthető el egyértelműen: lehet szállás/éj vagy személy/éj. A tábla nincs bekötve futásidejű kódba.

> **Eltérés / DECISION REQUIRED:** a jelenlegi `nightly_price` egyetlen összeg, miközben a tervezett modell személyenkénti, életkor-, szezon-, hétvége- és díjalapú komponenseket kíván. A meglévő táblát nem szabad végleges modellként kezelni; bővítése vagy kiváltása kizárólag verziózott migrációval történhet.

## 2. Tervezett számítási modell — PLANNED

### 2.1 Alapfogalmak és invariánsok

- `nights = departure_date - arrival_date` naptári napokban, a fél-nyitott `[arrival_date, departure_date)` intervallum szerint.
- Minden foglalt éjszakához annak kezdőnapján hatályos szabály tartozik.
- A pénznem 1.0-ban `HUF`; eltérő pénznem és devizaátváltás **OUT OF SCOPE**.
- Belső részösszegek `DECIMAL` értékként számolandók; bináris lebegőpontos szám használata tilos.
- Negatív alapár, szorzó, díj vagy adó érvénytelen. A végösszeg nem lehet negatív.
- Ugyanazon bemenet és szabályverzió mindig ugyanazt a tételes eredményt adja.
- Az availability és az ár két külön kérdés: az árkalkuláció nem jelent foglalást vagy kapacitás-zárat.

> **DECISION REQUIRED:** minimum és maximum éjszakaszám, minimum és maximum összlétszám, valamint hogy a csecsemő beleszámít-e a kapacitásba.

### 2.2 Éjszakaszám-alapú ársáv

Az érvényes ársáv kiválasztása a teljes `nights` alapján történjen, például `[min_nights, max_nights]`. Egy éjszakára pontosan egy tartózkodáshossz-sáv lehet alkalmazható. Átfedő szabályoknál determinisztikus prioritás, majd egyedi azonosító szerinti tie-break szükséges; azonos prioritású, egyformán specifikus aktív szabályt konfigurációs hibaként kell visszautasítani, nem önkényesen kiválasztani.

> **DECISION REQUIRED:** a konkrét sávhatárok, a hosszabb tartózkodás kedvezményének formája és az ársávok egymásra épülése.

### 2.3 Személyenkénti és gyermekkor-alapú ár

Tervezett alapképlet egy éjszakára:

```text
guest_nightly = adult_count × adult_unit_price
              + Σ(child_category_multiplier(child_age) × adult_unit_price)
```

- A felnőtt egységár személy/éj érték.
- Minden gyermek az érkezés napján betöltött életkora alapján pontosan egy kategóriába tartozik.
- A kategóriahatárok ne fedjék egymást, és ne hagyjanak rést.
- Hiányzó vagy nem egész gyermekéletkor mellett nem készülhet végleges ajánlat.
- A kliens által küldött `children` darabszámnak egyeznie kell az életkorok számával.

> **DECISION REQUIRED:** felnőttkor alsó határa, gyermek- és csecsemőkategóriák, szorzók, ingyenes kategóriák, valamint hogy életkort vagy születési dátumot kell-e megőrizni. Adatminimalizálási alapjavaslat: csak a kalkulációhoz szükséges életkor/kategória pillanatképe maradjon meg.

### 2.4 Szezon, hétvége és egyedi dátum

Tervezett éjszakánkénti sorrend:

1. dátumhoz illő alap/szezon szabály kiválasztása;
2. tartózkodáshossz-sáv alkalmazása;
3. hétvégi módosító alkalmazása az érintett éjszakákra;
4. egyedi dátum override alkalmazása, ha van;
5. vendégtípusonkénti részösszeg képzése.

Az egyedi dátum override vagy teljesen helyettesíti az adott napi árat, vagy módosítóként halmozódik; a kettő egyszerre nem megengedett.

> **DECISION REQUIRED:** szezonhatárok, a hétvége napjai (például péntek/szombat éjszaka), százalékos vagy fix módosítás, kombinálhatóság és prioritás. Ünnepnapkezeléshez külön, verziózott naptárforrás szükséges.

### 2.5 Fix díj, takarítás, IFA és kedvezmény

Tervezett összesítés:

```text
accommodation_subtotal = Σ(nightly_guest_total)
discount_total         = eligible_discount(accommodation_subtotal)
fee_total              = booking_fixed_fee + cleaning_fee
tax_total               = tourist_tax(taxable_guests, taxable_nights)
grand_total             = round_HUF(accommodation_subtotal - discount_total
                                    + fee_total + tax_total)
```

- A foglalásonkénti fix díj egyszer, a takarítási díj a meghatározott feltétellel egyszer számítandó.
- A kedvezmény alapja, felső korlátja, kombinálhatósága és alkalmazási sorrendje explicit szabály legyen.
- Az idegenforgalmi adó (IFA) külön tétel; mentességhez ok és a kalkuláció idején érvényes szabály szükséges.
- Adót vagy díjat nem szabad az alapárba rejtve és egyidejűleg külön is felszámítani.

> **DECISION REQUIRED:** fix és takarítási díj összege/feltétele; IFA jogalapja, egysége, életkori és egyéb mentessége, felső éjszakakorlátja; kedvezménytípusok és kombinálási sorrend.

### 2.6 Kerekítés

Javasolt technikai szabály: minden tétel nagy pontosságú decimális értékkel készül, a vendégnek megjelenített és snapshotba mentett HUF tételek matematikai `HALF_UP` móddal egész forintra kerekülnek, majd ezek összege adja a végösszeget. A részösszegek és a végösszeg közti egyezést tesztelni kell.

> **DECISION REQUIRED:** a HUF egész forintra kerekítés és a `HALF_UP` mód üzleti/számviteli jóváhagyása, illetve hogy tételenként vagy csak végösszegben történjen kerekítés.

## 3. Ár-pillanatkép és életciklus — PLANNED

Árajánlat vagy foglalás létrehozásakor megváltoztathatatlan snapshot készüljön legalább ezekkel:

- kalkuláció verziója és időpontja (`Europe/Budapest`);
- arrival/departure, éjszakaszám, felnőttszám és gyermek-kategóriák;
- alkalmazott szabályok stabil azonosítója és verziója;
- napi, személytípusonkénti tételek;
- kedvezmények, fix díjak, takarítás és IFA külön soron;
- kerekítési mód, `HUF`, nettó/bruttó jelentés;
- eredeti számított összeg, manuális override és végösszeg;
- override-ot végző admin, időpont és kötelező indok.

A szabály későbbi szerkesztése nem módosíthat korábbi snapshotot. Draft/pending rekord újraszámítása csak explicit művelettel, aktuális szabályokkal, új snapshot-verzióként történhet; az előző verzió auditálható marad. Elfogadott/confirmed ár automatikusan nem számítható újra.

> **DECISION REQUIRED:** mely státusznál fagy be az ár; meddig érvényes egy ajánlat; admin milyen jogosultsággal és mekkora korláttal írhat felül; az override megváltoztatja-e az adóalapot.

## 4. Szemléltető kalkulációk — nem végleges értékek

Az alábbi `P = 10 000 HUF/felnőtt/éj`, gyermek-szorzók, díjak és százalékok kizárólag a képletek bemutatását szolgálják; **nem üzleti árak és nem implementálandó konstansok**.

1. **Két felnőtt, két éj:** `2 éj × (2 × P) = 2 × 20 000 = 40 000 HUF`. **DECISION REQUIRED:** valódi felnőtt egységár.
2. **Egymás utáni dátumok éjszakaszáma:** `[aug. 10., aug. 13.) = 3 éj`; `3 × (1 × P) = 30 000 HUF`. A távozási nap nem árazott. **DECISION REQUIRED:** minimum éjszaka.
3. **Gyermek kategóriaszorzóval:** `2 éj × (2 × P + 1 × 0,5P) = 2 × 25 000 = 50 000 HUF`. **DECISION REQUIRED:** korhatár és `0,5` helyetti végleges szorzó.
4. **Ingyenes csecsemő szemléltetése:** `3 éj × (2 × P + 1 × 0P) = 60 000 HUF`. **DECISION REQUIRED:** ingyenesség, korhatár és kapacitásba számítás.
5. **Hétvégi felár egyetlen érintett éjre:** két normál és egy szemléltető `+20%` éj: `2 × 20 000 + 1 × 20 000 × 1,20 = 64 000 HUF`. **DECISION REQUIRED:** hétvégi napok és felár.
6. **Szezonváltáson át:** két alacsony szezonú éj `P`, egy magas szezonú éj szemléltető `1,3P`: `2 × 2P + 1 × 2 × 1,3P = 66 000 HUF`. **DECISION REQUIRED:** szezonok, szorzó és napi szabályválasztás.
7. **Hosszú tartózkodás kedvezménye:** `7 × 2P = 140 000`; szemléltető `10%` kedvezmény: `140 000 × 0,10 = 14 000`; részösszeg `126 000 HUF`. **DECISION REQUIRED:** sávhatár, százalék és kedvezményalap.
8. **Takarítási díj:** `accommodation 40 000 + cleaning 8 000 = 48 000 HUF`. A `8 000` csak példa. **DECISION REQUIRED:** összeg, kötelezőség és adókezelés.
9. **IFA külön tétel:** szemléltető `500 HUF/fő/éj`, két adóköteles felnőtt, három éj: `2 × 3 × 500 = 3 000`; `60 000 + 3 000 = 63 000 HUF`. **DECISION REQUIRED:** aktuális IFA és mentességi szabályok.
10. **Admin override:** számított `80 000`, dokumentált fix végösszeg `75 000`; eltérés `-5 000 HUF`, külön auditált override-tételként. **DECISION REQUIRED:** jogosultság és limit.
11. **Kerekítési példa:** szemléltető tétel `10 000 × 0,333 = 3 330,00`, más paraméternél `3 330,50 → 3 331 HUF` `HALF_UP` esetén. **DECISION REQUIRED:** elfogadott kerekítési mód.
12. **Nem kombinálható kedvezmények:** `100 000` alapra szemléltető `10%` vagy fix `15 000`; ha csak a nagyobb választható, `100 000 - 15 000 = 85 000 HUF`. **DECISION REQUIRED:** kombinálási politika.

## 5. Hibák és válaszviselkedés — PLANNED

Kalkuláció nem készülhet végleges eredménnyel, ha:

- dátumformátum hibás, departure nem későbbi, vagy a tartózkodás kívül esik a korlátokon;
- nincs minden éjszakára pontosan egy feloldható alapárszabály;
- átfedő szabályok prioritása nem dönthető el;
- vendégszám/életkor hiányos, inkonzisztens vagy kapacitáson kívüli;
- pénznem nem `HUF`, érték negatív, vagy decimális túlcsordulás történne;
- IFA/mentesség eldöntéséhez szükséges adat hiányzik;
- a hivatkozott szabályverzió törölt vagy nem rekonstruálható.

A publikus API mezőszintű, PII-mentes validációs hibát adjon; belső konfigurációs hiba általános publikus üzenetet és korrelációs azonosítót kapjon. Stack trace, szabálybelső vagy személyes adat nem kerülhet válaszba/logba.

## 6. Admin szerkesztési igények — PLANNED

- Lista, létrehozás, jövőbeli hatályú verzió létrehozása és inaktiválás; felhasznált szabály hard delete-je tilos.
- Dátum-, tartózkodáshossz- és prioritásátfedések azonnali jelzése.
- Előnézet megadott dátumokra és vendégösszetételre, teljes számítási bontással.
- Pénzértékek decimális validációja, explicit `HUF`, CSRF-védelem és jogosultság-ellenőrzés.
- Minden módosítás auditálása régi/új értékkel; tömeges módosítás előtt megerősítés.
- Manuális booking-árfelülírás külön jogosultság, kötelező indok és az eredeti összeg megtartása mellett.

## 7. Elfogadási feltételek — PLANNED

1. Unit tesztek fedik a fél-nyitott éjszakaszámot, sávhatárokat, gyermek-kategóriákat, szezonváltást, hétvégét, díjat, IFA-t, kedvezményt és kerekítést.
2. Integrációs teszt igazolja a szabályverziók determinisztikus kiválasztását és a snapshot változtathatatlanságát.
3. Minden publikus ár tételesen összeadódik a megjelenített végösszegre; pénzszámítás nem használ `float` típust.
4. Korábbi foglalás ára szabálymódosítás után változatlan; explicit újraszámítás új verziót hoz létre.
5. Manuális override jogosultság- és CSRF-védett, indokolt és auditált.
6. Nincs sémamódosítás új migráció nélkül, és minden új üzleti szabály ugyanabban a PR-ban tesztet és dokumentációt kap.

## 8. Sprint 4 minimális ármodell — IMPLEMENTED

Az egyetlen implementált alapegység a `pricing_rules` táblában konfigurált `person_night`. A nyertes aktív szabályt az időszak, a minimum éjszaka és a prioritás választja ki. A képlet: `éjszakák × (felnőttek + gyermekek) × konfigurált személy/éj ár`; minden személy azonos egységárral számít. Az eredmény egész fillérben készül, majd két tizedes HUF stringként kerül a bookingba.

Hiányzó szabály vagy azonos nyertes prioritás konfigurációs hiba; booking nem jön létre hamis `0.00` árral. Az immutable snapshot tartalmazza a számítás időpontját, intervallumot, éjszakaszámot, vendégadatokat, szabályazonosítót, `person_night` alapot, line itemet, subtotal/total értéket és `HUF` pénznemet.

**PLANNED, döntés szükséges:** gyermekár/kedvezmény, IFA és mentességek, hétvégi és szezonális szabályok együttalkalmazása, fix díj, kedvezmény és admin felülírás. Ezekből a kód nem talál ki üzleti értéket. A demo seed kizárólag szemléltető fejlesztési adat, nem production ár.
