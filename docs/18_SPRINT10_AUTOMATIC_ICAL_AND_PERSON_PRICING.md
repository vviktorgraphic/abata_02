# Sprint 10 – automatikus iCal és személyalapú árképzés

**Állapot:** IMPLEMENTED a `feature/automatic-ical-and-person-pricing` branch-en; production aktiválás és valós cPanel/provider smoke PENDING.

Ez a dokumentum a Sprint 10 aktuális szerződése. A korábbi sprintdokumentumok kézi iCalra és legacy árképzésre vonatkozó leírásai történeti állapotok.

## Automatikus iCal worker

Az aktív `import` és `bidirectional` forrásokat a következő, webkéréstől független CLI dolgozza fel:

```powershell
docker compose exec app composer ical:sync
```

Közvetlenül: `php bin/ical-sync.php`. A CLI kizárólag a process environmentet használja, `.env` fájlt nem tölt be. cPanelen ugyanazt a védett, webrooton kívüli environment/wrapper megoldást kell használni, mint a többi CLI-nél. A stdout forrásonként egy JSON sort kap; URL, token, credential és PII nem kerül bele. Exit kód: `0` minden feldolgozott forrás sikeres vagy warning; `1` legalább egy forrás failed; `2` legalább egy forrás lockolt és nincs failed forrás.

Forrásonként MySQL `GET_LOCK` advisory lock készül az adatbázis és source ID által névterezve. A lock ugyanahhoz a PDO-kapcsolathoz kötött, `finally` ágban felszabadul, process/kapcsolat megszűnésekor a MySQL automatikusan elengedi. Nincs időalapú locklopás. A lock tulajdonában talált `running` sync log megszakadt futásként `failed` állapotba kerül; ez a stale/interrupted run számlálóban látható.

Konfiguráció:

| Változó | Alapérték | Szerződés |
|---|---:|---|
| `ICAL_TIMEOUT_SECONDS` | `10` | pozitív HTTP timeout |
| `ICAL_MAX_RETRIES` | `2` | 0–5 újrapróbálkozás |
| `ICAL_BACKOFF_SECONDS` | `1` | 1–30 másodperces alap; exponenciális 1, 2, …, legfeljebb 60 s |
| `ICAL_MISSING_GRACE_SECONDS` | `86400` | legalább 24 óra |

Retry csak timeout/átmeneti hálózati vagy DNS-hibára, HTTP 429-re és 5xx-re történik. SSRF tiltás, parse/dátumhiba, konfigurációs hiba és a 429-en kívüli 4xx végleges. A várakozás cserélhető komponens, ezért a unit teszt nem alszik.

## Reconciliation és monitoring

Minden sikeresen látott UID – változatlan duplikátum is – frissíti a `last_seen_at` mezőt és törli a missing jelzőt. Reconciliation kizárólag teljesen letöltött és parse-olt feed után, eseményszintű persistence hiba nélkül indul. Az első eltűnés beállítja a budapesti megjelenítési időt és az abszolút epoch instantot; legalább 86 400 eltelt másodperc után a kapcsolt blocked period soft-inaktív lesz. Ez a DST 23/25 órás napjain is valódi 24 órát jelent. Régi, epoch nélküli missing marker biztonságosan újrakezdi a grace időt. `STATUS:CANCELLED` az aktív blokkot azonnal inaktiválja.

A sync log külön számlálja a létrehozott, frissített, duplikált, ténylegesen inaktivált, grace alapján inaktivált, retry és helyreállított futás mennyiségeket. Confirmed booking konfliktusa warning; a booking soha nem módosul. Importált blocked period továbbra sem exportálódik, így nincs visszacsatolási hurok.

Javasolt, de deployment során jóváhagyandó cPanel ütemezés: 15 percenként. Helyőrzős példa:

```text
*/15 * * * * cd /home/<account>/apps/foglalo/current && /usr/local/bin/php bin/ical-sync.php >> /home/<account>/logs/foglalo/ical-sync.log 2>&1
```

A pontos PHP-útvonal, release-könyvtár, logrotáció, alert címzett és SLA környezetfüggő. A JSON output/exit code monitorozandó; tartós `failed`, növekvő retry, recovered run vagy elöregedett `last_success_at` riasztást igényel.

## Személyalapú árképzés

A `person_pricing_configuration` singleton explicit `legacy` vagy `person` módot tartalmaz optimistic versionnel. A migráció alapértelmezése `legacy`, nem hoz létre valótlan production árat. `person` mód csak egész, nem negatív hétköznapi és hétvégi felnőttárral aktiválható.

A gyermek életkora a bookingban már tárolt egész életkor: gyermek `0..17`, felnőtt legalább `18`. Egy aktív sáv minimuma és maximuma inkluzív; a sávok nem fedhetik egymást. Az alkalmazási validáció mellett a `pricing_child_age_coverage.age` elsődleges kulcsa a tranzakciós repository-írások overlapjét adatbázisban is védi. Hiányzó aktív sávnál az admin preview és a publikus booking explicit konfigurációs hibával megáll; nincs 0 Ft-os vagy felnőttár fallback.

Egy éjszaka személyalapú szállásdíja:

```text
adult_count × adult_rate + Σ child_band_rate(child_age)
```

Péntek (ISO 5) és szombat (ISO 6) éjszaka hétvégi. `person` módban a legacy `base`, `stay_length` és `weekend` szabálytípusok helyét a személyár veszi át. A `seasonal`, `fixed_fee`, `tourism_tax` és `exemption` logika megmarad; fix díj és IFA nem olvad a lemondási `accommodation_fee` alapba. A legacy mód változatlanul olvassa a korábbi konfigurációt.

Az UX-javítás után az admin kanonikus `/admin/pricing` oldala a technikai módválasztás nélkül kezeli a felnőttárakat és a gyermek ársávok létrehozását, szerkesztését, illetve megerősített törlését. A felnőttár mentése person módra vált. A történeti booking snapshotnak nincs band FK-ja, ezért az ársáv fizikai törlése biztonságos; a coverage rekordok kaszkádolnak, a konfigurációverzió nő és `person_pricing.band_deleted` audit készül. Hiányos lefedettség megengedett konfigurációs állapot, de preview/booking fail-closed és az admin pontos figyelmeztetést kap.

## Snapshot, HUF és kompatibilitás

Az új booking `version=3` immutable snapshotja tartalmazza a configuration verziót, felnőttárakat, összes sávot, gyermekéletkorokat, péntek/szombat besorolást, éjszakánkénti felnőtt- és gyermekbontást, alkalmazott ancillary szabályok teljes paramétereit, accommodation fee-t, IFA-t, egyéb díjat és végösszeget. Az admin preview és a booking ugyanazt a PDO adaptert és domain engine-t használja. Régi v1/v2 JSON snapshotok nem módosulnak és továbbra is megjeleníthetők.

A DECIMAL oszlopok kompatibilitási okból megmaradnak, de az új HUF személyár-írások csak egész forintot fogadnak el. Százalékos legacy adjustment továbbra is lehet tört. A közös `HufFormatter` lebegőpontos művelet nélkül, matematikai HALF_UP megjelenítéssel, szóközös ezres tagolással ad például `20 000 Ft` értéket az adminban, publikus válaszban és e-mailben. Az adatbázis és immutable snapshot továbbra is kanonikus két tizedes DECIMAL-stringet tárolhat.

A lemondási szabály változatlan: legalább hét Budapest-naptári nappal érkezés előtt 0%, később az immutable snapshot kizárólagos `accommodation_fee` értékének 50%-a. IFA és egyéb díj nem része az alapnak; fizetés/beszedés nincs.

## Migráció és downgrade

- `017_add_ical_automation.sql`: missing marker és sync metrikák.
- `018_add_person_pricing.sql`: konfiguráció, gyermek ársáv és age coverage.
- `019_add_ical_missing_instant.sql`: DST-biztos elapsed-time marker.
- `020_backfill_legacy_pricing_amount.sql`: korábbi hibás writer által `amount=NULL` értékkel hagyott pricing sorok adatjavítása a változatlan `nightly_price` alapján.

A migrációk forward-only-k. Rollback előtt le kell tiltani az iCal cront, a pricing módot `legacy` értékre kell visszaállítani még az új kóddal, backupot kell készíteni, majd az előző release-re váltani. A 017–020 változásait kézzel visszafordítani tilos: a v3 snapshotok megőrzendő üzleti bizonyítékok, a 020 pedig csak a már létező kanonikus ármezőt tölti vissza. Inkompatibilis helyzetben maintenance + restore vagy forward-fix szükséges.

## Nyitott deployment döntések

Production felnőttárak, gyermek ársávok/összegek, IFA és jogi mentességek továbbra is OPEN; a rendszer nem seedeli őket. Szintén nyitott a cron tényleges telepítése, a konkrét PHP/path, monitor/SLA/escalation és logretention. A repository teszt nem bizonyít valós cPanel, Google/Szallas.hu hálózati vagy production pricing E2E-t.
