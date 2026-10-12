# RC2 tulajdonosi elfogadás és go-live readiness

**Állapot:** OWNER ACCEPTANCE READY; környezet- és szolgáltatófüggő kapuk nyitottak

**Audit alap:** `release/rc2` @ `21d15ea544c05d5f6cad693067d553fe1d04fd7f`

**Felülvizsgálat:** 2026-10-12

Ez a dokumentum az RC2 kontrollált tulajdonosi elfogadását készíti elő. Nem engedélyez deployt, production konfiguráció-, árazás-, cron- vagy feedváltást, és nem igazol olyan környezeti állapotot, amelyet a repositoryból nem lehet bizonyítani. A handoff szerint a fenti baseline productionben valós foglalásokat fogad; ezért minden meglévő booking védett adat, teszteléshez kizárólag külön TEST rekord használható.

Phase 7 kizárólag dokumentációt, read-only operátori eszközt és annak szerződéses tesztjét adta hozzá. A Phase 7.1 hotfix az auditban feltárt lokális Compose environment továbbítási rést explicit, legkisebb jogosultságú mappinggel és repository-szerződéses teszttel zárja le. Runtime PHP, booking üzleti szabály, adatbázisséma és migráció nem változik.

## RC2 állapota — belső auditmátrix

| Terület | Állapot | Bizonyíték / következő lépés |
|---|---|---|
| Publikus booking, availability és blocking | IMPLEMENTED + PRODUCTION ACTIVE | Tranzakciós `pending` létrehozás, inventory lock, idempotencia és tesztek; kontrollált owner UAT szükséges. |
| Admin auth és e-mailes 2FA | IMPLEMENTED | CSRF, rate limit, session rotation és 2FA tesztelt; production owner smoke szükséges. |
| Pricing, IFA és immutable snapshot | IMPLEMENTED | Közös engine; production értékeket ebben a fázisban tilos módosítani. |
| Admin státuszátmenetek és blocked period | IMPLEMENTED | Auditált, korlátozott állapotgép és inventory lock; kizárólag TEST rekorddal ellenőrizhető. |
| Havi foglaltság | IMPLEMENTED | Védett, read-only Budapest-hónapnézet; owner UAT szükséges. |
| Díjbekérő, emlékeztető és érkezési tájékoztató | IMPLEMENTED | Commit utáni SMTP és workflow-specifikus kézi retry; csak tesztpostafiókkal ellenőrizhető. |
| Confirmed booking módosítás | IMPLEMENTED | Aláírt preview, stale-védelem, újraárazás, változatlan előleg, history/outbox és stabil UI-horgony. |
| iCal import/export alkalmazáslogika | IMPLEMENTED | Kézi sync és worker elkészült; az új export provider-oldali használata még nincs aktiválva. |
| iCal production cron | IMPLEMENTED + PRODUCTION DISABLED/PENDING | Phase 7.3 read-only audit: `ical-sync.php` cron nincs jelen; monitorozás és cutover döntés szükséges. |
| Lifecycle review/completed | IMPLEMENTED + PRODUCTION DISABLED/PENDING | Phase 7.3 production bootstrap: `enabled=false`, üres start date; lifecycle cron nincs jelen. |
| SMTP alkalmazási út | IMPLEMENTED + EXTERNAL/PROVIDER BLOCKER | Productionön hitelesített `tls`/587 konfiguráció van; a kézbesíthetőségi/RBL kapu kontrollált küldés vagy provider-bizonyíték nélkül nyitott. |
| Általános automatikus outbox retry | DEFERRED / OUT OF SCOPE | Nincs worker; attempts/backoff/stale-claim policy owner döntést igényel. |
| Online fizetés és automatikus beszedés | DEFERRED / OUT OF SCOPE | Nincs implementálva. |
| Jogi linkek és verziók | RUNTIME PASS / OWNER CONTENT CONFIRMATION REQUIRED | A Phase 7.3-ban az öt runtime érték megfelelt; a publikált dokumentumok tartalmi/jogi jóváhagyása továbbra is owner action. |
| Backup/rollback | PARTIAL EVIDENCE / BLOCKED | A 2026-10-12-i DB backup checksumja PASS; a kapcsolódó state-snapshot könyvtár üres, restore drill bizonyíték nincs. |
| Admin idle timeout | PASS / STALE HANDOFF RESOLVED | Phase 7.3 production bootstrap: idle 1800, absolute 28800 másodperc; a handoff 900 másodperces állítása elavult. |
| Korai RC1/RC2 release-dokumentáció | STALE DOCUMENTATION — RECONCILED | Sprint 10, havi occupancy és Phase 6 állapota az aktuális kódhoz igazítva. |
| Alapértelmezett lokális Compose web bootstrap | IMPLEMENTED / RESOLVED IN PHASE 7.1 | A Compose az auditált alkalmazásváltozókat explicit továbbítja, a DB root credentialt nem; a `.env.example` hamis fejlesztői payment adatokkal és regressziós teszttel használható. |
| Main merge | DEFERRED / OUT OF SCOPE | Ebben a fázisban nem történik meg; külön release-döntés. |

## Ismert eltérések és kötelező ellenőrzések

### Admin idle timeout — Phase 7.3-ban megfigyeléssel lezárva

A handoff 900 másodperces effektív production értéket állított, miközben a `config/auth.php` és a `.env.production.example` 1800 másodperces minimumot/alapértéket ír elő. A Phase 7.3 read-only production bootstrap az effektív idle értéket 1800, az absolute értéket 28800 másodpercnek mérte. Ezért a 900 másodperces handoff-állítás elavult dokumentáció, production konfigurációs konfliktus nincs.

Az operátor az aktív release gyökerében, a production environment betöltésével kizárólag az effektív értéket olvassa ki:

```text
php -r '$c=require "config/auth.php"; echo "ADMIN_SESSION_IDLE_TIMEOUT_SECONDS=", $c["session_idle_timeout_seconds"], PHP_EOL;'
```

A parancs nem írt ki más environment változót vagy secretet. A Phase 7.3 nem módosított timeoutot vagy production `.env` fájlt.

### Jogi URL-ek/verziók — Phase 7.3 runtime ellenőrzés PASS

A Phase 7.3 production bootstrap az allow-listelt `BOOKING_POLICY_URL`, `BOOKING_POLICY_VERSION`, `PRIVACY_POLICY_URL`, `PRIVACY_POLICY_VERSION` és `HOUSE_RULES_URL` értékeket közvetlenül ellenőrizte; mind megfelelt a deployment szerződésnek. Ez technikai runtime PASS, nem helyettesíti a publikált dokumentumok tartalmi/jogi owner jóváhagyását.

### Lokális Compose bootstrap — Phase 7.1-ben lezárt konfigurációs rés

A Phase 7 auditban az első fail-fast hiba a hiányzó `HOUSE_RULES_URL` volt; a Compose mappingből a guest Reply-To, booking notification, payment-request és lifecycle mezők is hiányoztak. A Phase 7.1 hotfix ezeket explicit továbbítja az `app` szolgáltatásnak, miközben a `DB_ROOT_PASSWORD` továbbra is kizárólag a `db` szolgáltatásban érhető el. A `.env.example` lifecycle állapota változatlanul kikapcsolt, payment adatai pedig egyértelműen hamis fejlesztői placeholderek. A standard `Copy-Item .env.example .env`, majd `docker compose up -d --build` indítás így kézi environment override nélkül használható.

## A. Automatizált és lokális regressziós ellenőrzések

Ezek fejlesztői vagy elkülönített candidate környezetben futnak, production adatbázison nem:

```powershell
git diff --check
docker compose exec app vendor/bin/phpunit tests/Unit
docker compose exec app vendor/bin/phpunit tests/Feature
docker compose exec app vendor/bin/phpunit
docker compose exec app composer validate --strict
docker compose exec app composer audit --locked
```

A confirmed-booking módosítás stale preview, párhuzamos inventory, idempotencia, deposit-megőrzés, monthly occupancy és iCal identity eseteit automatizált integrációs teszt fedi. Ezeket nem kell valós production versenyhelyzettel előidézni.

## B. Read-only production smoke

A `tools/Invoke-Rc2ReadOnlySmoke.ps1` kizárólag anonim `GET /health`, `GET /` és `GET /admin/login` kéréseket küld. Nem kér credentialt, nem jelentkezik be, nem hoz létre bookingot, nem használ iCal tokent, nem hív lifecycle workert, nem tart fenn web sessiont, és nem ír ki bodyt, cookie-t vagy headert.

```powershell
.\tools\Invoke-Rc2ReadOnlySmoke.ps1 -BaseUrl 'http://localhost:8080'
```

Production URL-re Codex nem futtatja automatikusan. Operátori futtatáskor a script ellenőrzi a három HTTP 200 választ, a `/health` `status=ok` JSON-ját, a content type-okat, a determinisztikus `nosniff` és frame-védelmet, továbbá az admin `no-store` és CSP védelmét. Kötelező hiba esetén nem nulla exit kóddal áll le. A HTTPS/HSTS/tanúsítvány külön deployment smoke része.

## C. Kontrollált owner acceptance — TEST adatot hozó vizsgálatok

### Tesztadat-biztonság

- Kapcsolattartó: `Teszt Viktor – Phase 7`.
- Megjegyzés: `technikai próba, nem valódi vendégfoglalás`.
- Meglévő valós bookingot teszt céljából megnyitni lehet, módosítani tilos.
- Valódi vendég e-mail-címe helyett kijelölt tesztpostafiókot kell használni.
- Minden létrehozott tesztbooking referenciáját és minden mutáció előtt az aktuális állapotát fel kell jegyezni.
- Takarítás kizárólag támogatott admin státuszátmenettel történhet; közvetlen SQL törlés, destruktív cleanup és legacy import újrafuttatás tilos.
- Production pricing nem szerkeszthető külön, előzetes tulajdonosi jóváhagyás nélkül.

### Publikus booking UAT

- [ ] Az oldal és a két hónapos naptár desktopon és mobilon helyesen renderel.
- [ ] A foglaltság és a fél-nyitott `[arrival, departure)` turnover viselkedés helyes.
- [ ] Az érkezés legalább 2 Budapest szerinti naptári nappal későbbi; korábbi dátum közérthető hibát ad.
- [ ] Legalább egy felnőtt szükséges; maximum 5 fizikai és maximum 4 fizető vendég érvényesül, a legalább 4 éves gyermek fizetőnek számít.
- [ ] A gyermekszám és a 0–17 éves gyermekkorok darabszáma/értéke validált.
- [ ] Az adatkezelési és foglalási szabályzat checkbox külön, előre ki nem jelölt és kötelező; URL/verzió snapshot ellenőrizhető.
- [ ] Az ár-előnézet és összegzés, IFA és egyéjszakás felár a jóváhagyott aktuális konfiguráció szerint jelenik meg.
- [ ] A sikeres kérés `pending` állapotú, azonnal blokkolja ugyanazt az intervallumot és iCal-exportképes.
- [ ] Azonos idempotenciakulcs és payload ismétlése nem készít második bookingot; eltérő payload konfliktust ad.
- [ ] Hibaválaszban nincs stack trace, credential, token vagy belső kivételszöveg.

### Admin UAT

- [ ] Login, e-mailes 2FA és CSRF-védett logout működik a kijelölt admin tesztfiókkal.
- [ ] A desktop navigáció és mobil hamburger menü használható billentyűzettel is.
- [ ] A booking lista, szűrés és részletoldal helyes.
- [ ] Külön TEST bookingokon ellenőrzött: `pending -> confirmed`, `pending -> rejected`, `pending -> invalidated`.
- [ ] Külön TEST bookingokon ellenőrzött: `confirmed -> cancelled`, `confirmed -> invalidated`.
- [ ] A havi foglaltság mutatja a megfelelő érkezési, foglalt és távozási napokat.
- [ ] Kontrollált, üres tesztintervallumon blocked period létrehozható és támogatott soft remove művelettel eltávolítható.
- [ ] Az Árképzés oldal megtekinthető; production ár módosítása ebben a fázisban tilos.
- [ ] A Felhasználók oldal és az egységes admin jogosultságok láthatók.
- [ ] A Naptárszinkron oldal, forráslista és napló látható; token/privát feed nem kerül jegyzetbe vagy képernyőképbe.

### Vendégkommunikáció UAT

Kizárólag TEST bookinggal és kijelölt tesztpostafiókkal ellenőrizendő:

- [ ] booking request vendéglevél és admin új-booking értesítés;
- [ ] díjbekérő;
- [ ] confirmed, rejected vagy cancelled levél az adott külön tesztfolyamathoz;
- [ ] kézi payment reminder;
- [ ] kézi érkezési tájékoztató;
- [ ] booking modification értesítés.

Minden érintett levélnél ellenőrizendő a From megjelenítési név, vendéglevélnél a Reply-To, a HTML/plain-text tartalom ahol alkalmazható, valamint hogy nincs secret vagy stack trace. Szimulált kézbesítési hiba nem rollbackelheti a booking állapotát. Production lifecycle review levelet ebben a fázisban tilos futtatni.

### Confirmed-booking módosítás UAT

1. Külön TEST bookingot erősíts meg, majd nyisd meg a módosító űrlapot.
2. Készíts előnézetet egy érvényes új intervallummal és létszámmal.
3. Ellenőrizd, hogy a böngésző a `#booking-modification` szakaszra kerül.
4. Adj meg egyszer hibás adatot; az űrlapérték és a hiba maradjon látható ugyanott.
5. Ments egy érvényes módosítást.
6. Ellenőrizd az új dátumot és létszámot.
7. Ellenőrizd, hogy az aktuális pricing konfigurációval újraszámolt snapshot készült.
8. Ellenőrizd, hogy a korábban rögzített előleg változatlan.
9. Ellenőrizd a módosítástörténetet.
10. Ellenőrizd a módosítási outbox/e-mail aktuális viselkedését.
11. Ellenőrizd az új intervallumot a havi foglaltságban.
12. Ellenőrizd, hogy a publikus availability csak az új intervallumot blokkolja.
13. Ellenőrizd, hogy az iCal ugyanazzal a booking identityvel csak az új intervallumot tartalmazza.

### iCal UAT

- **Új rendszer export:** kijelölt, titkosan kezelt tokennel ellenőrizd a PII-mentes, fél-nyitott pending/confirmed és belső blocked-period eseményeket. Tokent tilos dokumentációba, logba vagy képernyőképbe tenni.
- **Kézi import/sync:** kontrollált tesztforrással ellenőrizd az idempotenciát, a conflict jelzést és a sync logot.
- **Szallas.hu feedváltás:** provider-oldali, cutover-only művelet; Phase 7-ben tilos végrehajtani.

### Lifecycle UAT

A handoff szerinti production állapot:

```text
BOOKING_LIFECYCLE_ENABLED=false
BOOKING_LIFECYCLE_START_DATE=
Cron: disabled
```

Production aktiválás és review e-mail futtatása out of scope. A jelenlegi biztonságos állapot a dokumentált disabled/no-op szerződés; ezt Codex nem futtatja production ellen.

## D. Külső/szolgáltatói ellenőrzések

Az ismert RBL elutasítás hosting/provider reputációs kérdés, amíg kódbizonyíték mást nem mutat. Az alkalmazás mail routingja és szemantikája emiatt nem változik.

1. A hosting igazolja, hogy az outbound SMTP/MTA IP reputációs probléma megszűnt, vagy tiszta, hitelesített relayt biztosít.
2. Kijelölt tesztpostafiókba kontrollált kézbesítési próba történik.
3. A fogadott fejlécben TLS, SPF, DKIM és DMARC eredmény ellenőrzött.
4. Egy 2FA és egy vendég tranzakciós levél smoke sikeres.
5. Sikertelen próba után az outbox/failed állapot visszagörgetés nélkül ellenőrzött.
6. A levelezés csak ezek után kaphat GO minősítést.

## E. Kizárólag cutoverkor végezhető műveletek

- production Document Root/release váltás és rollback pointer kezelése;
- production `.env`, lifecycle flag vagy start date módosítása;
- iCal vagy lifecycle cron engedélyezése;
- Szallas.hu feed URL átállítása az új export feedre;
- production pricing vagy jogi runtime konfiguráció módosítása;
- main merge/tag/release döntés.

## Go-live gate tábla

| Gate | Állapot | Bizonyíték / következő lépés |
|---|---|---|
| Core booking | PASS / IMPLEMENTED | Automatizált regresszió + owner UAT. |
| Admin auth | IMPLEMENTED | Owner 2FA/logout UAT. |
| Pricing | IMPLEMENTED | Nincs nem jóváhagyott pricing változás. |
| Phase 6 modification | PASS / IMPLEMENTED | Automatizált regresszió + külön TEST owner UAT. |
| iCal application logic | IMPLEMENTED | Provider cutover még függő. |
| Lifecycle code | PASS / DISABLED | Production bootstrap szerint kikapcsolt, start date üres; aktiválás továbbra is cutover-only. |
| Cron | PASS / OFF | Lifecycle- és iCal-cron közvetlen crontab audit szerint nincs jelen. |
| SMTP application path | PASS / PROVIDER BLOCKER | Hitelesített TLS konfiguráció megvan; RBL és kontrollált kézbesítési smoke nyitott. |
| Legal links/versioning | PASS | Az öt allow-listelt runtime érték megfelel a production szerződésnek. |
| Backup/rollback | BLOCKED | DB checksum PASS, de a legfrissebb state-snapshot üres és restore drill nincs igazolva. |
| Session timeout | PASS | Effektív production idle/absolute érték: 1800/28800 másodperc. |
| Generic outbox retry | DEFERRED / BLOCKED | Owner retry/stale-claim policy szükséges. |
| Main merge | NOT PERFORMED | Külön release döntés. |

## Owner input required before next phase

### REQUIRED BEFORE MORE FEATURE WORK

- Konkrét acceptance hibát vagy új UX-kérést külön, szűk következő fázisként kell jóváhagyni.
- A lezárt Phase 7.1 Compose hotfix regressziós szerződését fenn kell tartani; ez nem old fel production vagy owner kaput.
- Automatikus outbox retry előtt maximum attempts, backoff és stale-processing reclaim policy szükséges.

### REQUIRED BEFORE GO-LIVE/CUTOVER

- Aktív WePanel Document Root owner megerősítés, jogi tartalomjóváhagyás, teljes state snapshot/restore bizonyíték és owner UAT eredmény.
- Lifecycle start date/engedélyezés, cron, iCal feedváltás és main merge külön GO/NO-GO döntése.

### EXTERNAL HOSTING/PROVIDER ACTION

- RBL/outbound relay rendezése, majd TLS/SPF/DKIM/DMARC és tesztkézbesítés igazolása.
- Production cron/PHP/release útvonal és monitorozási/escalation felelős rögzítése.

### OPTIONAL / DEFERRED

- Általános outbox retry worker, bounce/complaint feldolgozás, tokenrotációs átfedés és online fizetés.

## Phase 7.2 read-only production audit — 2026-10-12

Az ellenőrzött `c2eed7d` és `82077dd` commitok felkerültek az `origin/release/rc2` ágra; a remote és a lokális ág egyaránt `82077dd18687b5e939c939c1d255a8b962f59912` állapotot mutatott. Ez kizárólag branch-szinkron volt, deployment, tag, `main` merge vagy production Document Root váltás nem történt.

A credential nélküli production GET smoke a `https://foglalas.abata.hu` címen sikeresen ellenőrizte a `/health`, `/` és `/admin/login` végpontot. A `/health` implementációja read-only `SELECT 1` readiness checket végez, ezért az audit időpontjában a publikus alkalmazás és az adatbázis-kapcsolat elérhető volt. A főoldal által renderelt foglalási szabályzat, adatkezelési tájékoztató és házirend URL-je megegyezett az elvárt owner-facing célokkal, és mindhárom cél read-only HEAD kérése sikeres volt.

A dokumentált SSH kulcsot a production szerver felismerte és elfogadta, de a kulcs nem volt nem interaktívan feloldható, és betöltött SSH-agent identity sem állt rendelkezésre. Jelszó- vagy passphrase-kérést, alternatív hitelesítési próbát és szerveroldali módosítást az audit nem végzett. Emiatt a következők továbbra is közvetlen production megfigyelésre várnak:

- az aktív WePanel Document Root és a `21d15ea` release/public könyvtár metaadata;
- az effektív admin idle/absolute timeout;
- a legal version értékek;
- a lifecycle flag/start date és a lifecycle/iCal crontab;
- az SMTP konfiguráció biztonságosan megjeleníthető mezői;
- az aktuális backup/checksum/state-snapshot metaadatok és a restore drill bizonyítéka.

A handoff szerinti `21d15ea`, kikapcsolt lifecycle/cron, legacy Szallas.hu feed és korábbi backup csak dokumentált utolsó ismert állapot; közvetlen SSH-bizonyíték nélkül ebben az auditban nem lett újra PASS-ra minősítve. Az RBL/deliverability blokk kontrollált levélküldés nélkül nem tekinthető megoldottnak. Owner UAT sign-off nem áll rendelkezésre, friss pre-cutover backup továbbra is kötelező.

## Phase 7.3 befejezett read-only production audit — 2026-10-12

Az előzetesen feloldott SSH-agenttel a nem interaktív, jelszavas fallbacket tiltó kapcsolatpróba sikeres volt. A `21d15ea` release és annak `public/` könyvtára létezik, de a tárhelyen nem található olvasható current-release symlink vagy Document Root metadata. Emiatt az aktív release SSH-ból továbbra sem bizonyítható; a WePanel owner megerősítése szükséges. `82077dd` nem lett deployolva.

A Phase 7 read-only smoke ismét PASS eredményt adott a `/health`, `/` és `/admin/login` végpontokra. A production bootstrap és az allow-listelt konfigurációk közvetlen eredménye:

- admin idle timeout: `1800` másodperc — PASS, a handoff `900` értéke elavult;
- admin absolute timeout: `28800` másodperc — PASS;
- `BOOKING_POLICY_URL=https://abata.hu/abata_foglalasi_szabalyzat.pdf` — PASS;
- `BOOKING_POLICY_VERSION=sha256:835f39e0695bbe125654516965543bfabf09ed1c0cc82a87407136ab8a94e06e` — PASS;
- `PRIVACY_POLICY_URL=https://abata.hu/adatkezelesi_tajekoztato/` — PASS;
- `PRIVACY_POLICY_VERSION=2018-09-10` — PASS;
- `HOUSE_RULES_URL=https://abata.hu/abata_hazirend.pdf` — PASS;
- lifecycle `enabled=false`, start date üres — PASS / DISABLED;
- lifecycle cron: ABSENT; iCal cron: ABSENT;
- SMTP: `we035.tarhely.com`, port `587`, `tls`, feladó `foglalas@abata.hu` / `A Bata`, konfigurált username és password, guest Reply-To `info@abata.hu` / `A Bata` — CONFIGURATION PASS.

Az SMTP/RBL kézbesíthetőség továbbra is PROVIDER ACTION / UNVERIFIED: e-mail nem lett küldve, kontrollált TLS/SPF/DKIM/DMARC delivery evidence nem áll rendelkezésre. A közvetlen, kizárólag `SELECT 1` production adatbázis-ellenőrzés PASS. Read-only migration-status eszköz nincs, ezért a migration state `UNKNOWN — PRE-DEPLOY CHECK REQUIRED`; migráció nem futott.

A backup audit közvetlen bizonyítéka:

- legfrissebb DB backup: `abatahu_abookbata_20261012_095730_f376e475.sql`;
- timestamp: `2026-10-12 09:57:30 +02:00`;
- méret: `345603` byte;
- kapcsolódó checksum fájl jelen van, `sha256sum -c --status` eredménye PASS;
- legfrissebb snapshot könyvtár: `20261012_095730_21d15ea`, de teljesen üres;
- ezért DB SQL/checksum, `production.env`, release archive, `STATE.txt` és `SNAPSHOT.sha256` snapshot-bizonyíték nincs;
- restore drill dokumentum vagy log nem található: RESTORE DRILL NOT VERIFIED.

Owner UAT továbbra sincs aláírva. A Szallas.hu legacy feed használata dokumentált feltételezés; a feedváltás és tokenrotáció CUTOVER ONLY / OWNER-PROVIDER CONFIRMATION REQUIRED. Friss, teljes és ellenőrzött pre-cutover state snapshot, valamint restore drill bizonyíték nélkül go-live nem minősíthető késznek.
