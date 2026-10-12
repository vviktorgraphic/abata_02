# RC2 tulajdonosi elfogadás és go-live readiness

**Állapot:** OWNER ACCEPTANCE READY; környezet- és szolgáltatófüggő kapuk nyitottak

**Audit alap:** `release/rc2` @ `21d15ea544c05d5f6cad693067d553fe1d04fd7f`

**Felülvizsgálat:** 2026-10-12

Ez a dokumentum az RC2 kontrollált tulajdonosi elfogadását készíti elő. Nem engedélyez deployt, production konfiguráció-, árazás-, cron- vagy feedváltást, és nem igazol olyan környezeti állapotot, amelyet a repositoryból nem lehet bizonyítani. A handoff szerint a fenti baseline productionben valós foglalásokat fogad; ezért minden meglévő booking védett adat, teszteléshez kizárólag külön TEST rekord használható.

Phase 7 kizárólag dokumentációt, read-only operátori eszközt és annak szerződéses tesztjét adja hozzá. Runtime PHP, booking üzleti szabály, adatbázisséma és migráció nem változik.

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
| iCal production cron | IMPLEMENTED + PRODUCTION DISABLED/PENDING | Útvonal, monitorozás, kézi ismételt smoke és cutover szükséges. |
| Lifecycle review/completed | IMPLEMENTED + PRODUCTION DISABLED/PENDING | `BOOKING_LIFECYCLE_ENABLED=false`, üres start date, cron nincs engedélyezve. |
| SMTP alkalmazási út | IMPLEMENTED + EXTERNAL/PROVIDER BLOCKER | Hitelesített TLS/SSL transport és outbox működik; a kézbesíthetőségi/RBL kapu külső szolgáltatói blokk. |
| Általános automatikus outbox retry | DEFERRED / OUT OF SCOPE | Nincs worker; attempts/backoff/stale-claim policy owner döntést igényel. |
| Online fizetés és automatikus beszedés | DEFERRED / OUT OF SCOPE | Nincs implementálva. |
| Jogi linkek és verziók | OWNER DECISION REQUIRED | A runtime értékeket és a ténylegesen publikált dokumentumokat külön ellenőrizni kell. |
| Backup/rollback | IMPLEMENTED | Tooling és runbook kész; friss operátori backup/restore bizonyíték szükséges. |
| Admin idle timeout | OWNER / PRODUCTION VERIFICATION REQUIRED | A handoff 900 másodpercet mond, a kód minimuma és alapértéke 1800 másodperc. |
| Korai RC1/RC2 release-dokumentáció | STALE DOCUMENTATION — RECONCILED | Sprint 10, havi occupancy és Phase 6 állapota az aktuális kódhoz igazítva. |
| Alapértelmezett lokális Compose web bootstrap | STALE DOCUMENTATION / CONFIGURATION DEFECT | A Compose `app` nem továbbít minden, `.env.example` által dokumentált kötelező webkonfigurációt; külön javítás szükséges. |
| Main merge | DEFERRED / OUT OF SCOPE | Ebben a fázisban nem történik meg; külön release-döntés. |

## Ismert eltérések és kötelező ellenőrzések

### Admin idle timeout — konfliktus

A handoff 900 másodperces effektív production értéket állít, miközben a `config/auth.php` és a `.env.production.example` 1800 másodperces minimumot/alapértéket ír elő. A kód 900-zal fail-closed konfigurációs hibát adna. Ebben a fázisban egyik oldal sem lett átírva.

Az operátor az aktív release gyökerében, a production environment betöltésével kizárólag az effektív értéket olvassa ki:

```text
php -r '$c=require "config/auth.php"; echo "ADMIN_SESSION_IDLE_TIMEOUT_SECONDS=", $c["session_idle_timeout_seconds"], PHP_EOL;'
```

A parancs nem ír ki más environment változót vagy secretet. Az eredményt a release naplóban kell rögzíteni. Ha 900-at várnak, új tulajdonosi/security döntés és külön kódváltozás szükséges; Phase 7 nem csökkenti a minimumot.

### Jogi URL-ek/verziók — runtime ellenőrzés

A döntési dokumentumok belső technikai route-okat is rögzítenek, míg a production környezetminta külső, publikált jogi URL-eket mutat. A kód mindkettőt konfigurációként kezeli. A tényleges production `BOOKING_POLICY_URL`, `BOOKING_POLICY_VERSION`, `PRIVACY_POLICY_URL` és `PRIVACY_POLICY_VERSION` értékét, valamint a publikált tartalom egyezését secret vagy teljes `.env` kiírása nélkül kell ellenőrizni; repositorypéldából nem következtethető effektív érték.

### Lokális Compose bootstrap — feltárt konfigurációs rés

A standard `docker compose up -d` után az `app` konténer nem kapja meg az összes, `.env.example` fájlban dokumentált kötelező webes environment változót. A jelenlegi auditban az első fail-fast hiba a hiányzó `HOUSE_RULES_URL` volt; a Compose mappingből a booking notification és payment-request mezők is hiányoznak. A GET-only smoke emiatt egy izolált, átmeneti fejlesztői konténerben, explicit tesztértékekkel futott sikeresen. Phase 7 ezt nem javítja hallgatólagosan; külön bootstrap hotfix és regressziós ellenőrzés szükséges.

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
| Lifecycle code | IMPLEMENTED / DISABLED | Aktiválási dátum és cutover döntés szükséges. |
| Cron | OFF | Kézi smoke, útvonal, monitor és cutover döntés szükséges. |
| SMTP application path | IMPLEMENTED | Provider/RBL és kézbesítési smoke függő. |
| Legal links/versioning | VERIFY | Effektív runtime érték és publikált dokumentum egyezése szükséges. |
| Backup/rollback | IMPLEMENTED | Friss operational backup/restore bizonyíték szükséges. |
| Session timeout | CONFLICT | Effektív production érték read-only ellenőrzése kötelező. |
| Generic outbox retry | DEFERRED / BLOCKED | Owner retry/stale-claim policy szükséges. |
| Main merge | NOT PERFORMED | Külön release döntés. |

## Owner input required before next phase

### REQUIRED BEFORE MORE FEATURE WORK

- Konkrét acceptance hibát vagy új UX-kérést külön, szűk következő fázisként kell jóváhagyni.
- A lokális Compose environment továbbítási rését külön hotfixben kell javítani, hogy a dokumentált alapindítás ismét önmagában működjön.
- Automatikus outbox retry előtt maximum attempts, backoff és stale-processing reclaim policy szükséges.

### REQUIRED BEFORE GO-LIVE/CUTOVER

- Effektív production admin idle timeout, jogi URL/verzió, backup/restore bizonyíték és owner UAT eredmény.
- Lifecycle start date/engedélyezés, cron, iCal feedváltás és main merge külön GO/NO-GO döntése.

### EXTERNAL HOSTING/PROVIDER ACTION

- RBL/outbound relay rendezése, majd TLS/SPF/DKIM/DMARC és tesztkézbesítés igazolása.
- Production cron/PHP/release útvonal és monitorozási/escalation felelős rögzítése.

### OPTIONAL / DEFERRED

- Általános outbox retry worker, bounce/complaint feldolgozás, tokenrotációs átfedés és online fizetés.
