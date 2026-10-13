# E-mail folyamatok

> **IMPLEMENTED RC2 alap:** [díjbekérő a megerősítés előtt](22_OCCUPANCY_IFA_AND_PAYMENT_REQUEST.md). A `booking_payment_request` manuális retry ugyanazt az immutable outbox rekordot használja; a lentebb PLANNED-ként leírt új rekordos resend erre nem vonatkozik. A levélszövegek és az előlegalap aktuális Phase 3 szerződését a következő bekezdés hivatkozása írja le.

> **IMPLEMENTED Phase 3:** [vendégkommunikáció és életciklus](24_GUEST_COMMUNICATIONS_LIFECYCLE.md): accommodation-fee alapú előleg, kézi payment reminder és érkezési tájékoztató biztonságos inline képekkel, napi review worker és completed státusz. A production cron nincs engedélyezve.

> A production SMTP paraméterezés, SPF/DKIM/DMARC előkészítés és staging kézbesítési smoke végrehajtható lépéseit a [production deployment runbook](15_DEPLOYMENT.md#production-smtp) tartalmazza. Provider rekordot, credentialt vagy enforcement policyt a rendszer nem feltételez.

**Állapot:** 2FA, booking e-mailek, tranzakciós outbox, manuális failed retry, automatikus retry worker és stale reclaim IMPLEMENTED; production cron aktiválása NOT YET ENABLED
**Utolsó ellenőrzés:** 2026-10-12, `release/rc2` Phase 10 munkafa

Ez a dokumentum az 1.0 tranzakciós e-mail folyamatait tervezi. Kapcsolódó dokumentumok: [admin és hitelesítés](04_ADMIN_AND_AUTHENTICATION.md), [adatbázis- és domainmodell](02_DATABASE_AND_DOMAIN_MODEL.md), [árképzés](05_PRICING.md), [iCal](07_ICAL_SYNC.md), [biztonság](09_SECURITY.md), [tesztelés és üzemeltetés](10_TESTING_AND_OPERATIONS.md).

## 1. Jelenlegi állapot — IMPLEMENTED / hiányzó

**IMPLEMENTED Sprint 3:** `Mailer` port, strukturált `Message`, HTML/plain-text 2FA sablonrenderer, tesztelhető in-memory mailer és socket-alapú SMTP adapter. Az adapter Mailpithez plain SMTP-t, production konfigurációhoz titkosítást és opcionális authentikációt támogat; a PHP `mail()` függvényét nem használja. Transporthiba nem adja vissza a provider nyers válaszát, így credential vagy PII nem kerül kivételszövegbe.

**IMPLEMENTED:** booking request és confirmed/rejected/cancelled outbox, commit utáni SMTP, sikertelen státuszlevél védett admin újraküldése, valamint a Phase 10 automatikus retry/stale-recovery rétege. Az új public booking pontosan egy `booking_request_received` üzenetet queue-z a vendégnek (`A Bata – Foglalását megkaptuk`), továbbá címzettenként egy `booking_request_admin_notification` sort (`Új foglalási igény érkezett`). A címzettek az aktív, adminfelületen kijelölt felhasználók normalizált, egyedi címei; ha ilyen nincs, kizárólag a `BOOKING_NOTIFICATION_EMAIL` fallback használatos. A második levél a konfigurált `BOOKING_ADMIN_BASE_URL` alatt védett admin booking-linket tartalmazza. Minden címzett külön claim-, attempts-, hiba- és sent állapotot kap; egy admin kézbesítési hibája nem állítja meg a többit, és nem módosítja a publikus API vendéglevélből származó `email_status` értékét. Replay/idempotens újraküldés nem hoz létre és nem küld új példányt. **DEFERRED:** provider-idempotencia és bounce/complaint kezelés.

**DECISION REQUIRED:** a production host tulajdonosi értéke `s54.tarhely.com`, de a port, TLS mód, authentikáció, felhasználónév, feladó cím és reply-to továbbra is nyitott. Ezek hiányában production SMTP smoke nem tekinthető teljesítettnek.

**IMPLEMENTED:** Docker fejlesztésben Mailpit szolgáltatás elérhető, és a repository szabálya tiltja a PHP `mail()` közvetlen használatát.

## Production SMTP runbook – IMPLEMENTED configuration guard, OPEN owner values

**IMPLEMENTED:** `APP_ENV=production` esetén az alkalmazás induláskor elutasítja a hiányos levelezési konfigurációt. Kötelező az érvényes `MAIL_HOST`, `MAIL_PORT`, `MAIL_TIMEOUT_SECONDS`, `MAIL_FROM_EMAIL`, `MAIL_FROM_NAME`, `GUEST_REPLY_TO_EMAIL`, `GUEST_REPLY_TO_NAME`, továbbá az együtt megadott `MAIL_USERNAME` és `MAIL_PASSWORD`. A MIME `From:` fejléc a konfigurált megjelenítési nevet és feladói címet tartalmazza, miközben az SMTP envelope sender továbbra is kizárólag a `MAIL_FROM_EMAIL`. Minden vendégnek címzett booking e-mail `Reply-To: A Bata <info@abata.hu>` fejlécet kap a production konfigurációból; a 2FA-, admin booking notification- és belső rendszerlevelek nem kapnak automatikus guest Reply-To-t. A transport csak `MAIL_ENCRYPTION=tls` (STARTTLS) vagy `MAIL_ENCRYPTION=ssl` lehet. A TLS peer- és hostnév-ellenőrzés, illetve az önaláírt tanúsítvány tiltása kódszintű, nem kapcsolható ki environment változóval. Developmentben a Compose/Mailpit `none`, credential nélküli beállítása továbbra is támogatott.

**OPEN – owner/deployment:** a production SMTP-szolgáltató, host, port, titkosítási mód, feladó domain/név és credential konkrét értékei. A secretet a cPanel/hosting titkos environment-kezelésében kell beállítani; tilos Gitbe, webrootba, shell argumentumba, képernyőképbe vagy deployment naplóba írni.

PowerShell-kompatibilis release ellenőrzés (az értékek kiírása nélkül):

```powershell
docker compose exec app php -r "require 'vendor/autoload.php'; `$c=require 'config/mail.php'; new App\Infrastructure\Mail\SmtpConfiguration(`$c['host'], `$c['port'], `$c['encryption'], `$c['username'] ?: null, `$c['password'] ?: null, production: `$c['production']); echo 'SMTP configuration valid', PHP_EOL;"
```

Ezután staging címzettel valós TLS-kézfogást és kézbesítést kell smoke tesztelni. A parancs sikeressége csak konfiguráció-validáció, nem igazolja a szolgáltató elérhetőségét vagy a DNS/SPF/DKIM/DMARC beállítást.

**IMPLEMENTED:** mailer absztrakció, SMTP klienskonfiguráció, HTML/plain-text 2FA és booking sablonok, tranzakciós outbox, commit utáni küldés, same-record manuális retry öt próbáig, automatikus retry worker és stale claim recovery. **NOT YET ENABLED:** production cron. **DEFERRED:** bounce/complaint kezelés, provider-idempotencia, új rekordos manuális resend és teljes e-mail adminmodul.

## 2. Transport és komponensek — IMPLEMENTED alap, PLANNED worker/provider elemek

- Kizárólag hitelesített SMTP transport használható TLS-sel. A PHP `mail()` közvetlen és közvetett fallbackként is **tiltott**.
- Az alkalmazási réteg üzleti eseményt és stabil sablonazonosítót ad át egy `EmailSender`/outbox absztrakciónak; domainkód nem ismeri az SMTP-t.
- SMTP host, port, felhasználó, jelszó, titkosítás, feladó cím és reply-to környezeti konfiguráció. Credential nem kerül repositoryba, adatbázisba, válaszba vagy naplóba.
- Fejlesztésben Mailpit használható SMTP célként; staging és production külön credentialt és feladódomaint kap.
- cPanelen cron futtatja az outbox feldolgozót PHP CLI-vel; Node.js nem runtime-függőség.

> **DECISION REQUIRED:** SMTP szolgáltató/library, port és TLS mód, feladó név/cím, reply-to, bounce/complaint feldolgozás és szolgáltatói timeout.

## 3. Eseménykatalógus és sablonok — PLANNED

Minden sablonnak verziózott HTML és szemantikailag azonos plain-text változata van. A tárgy változóit ugyanaz az escaping/validáció védi; CR/LF karakter tárgyban és címmezőben tiltott.

| Esemény | Sablonazonosító | Címzett | Szemléltető tárgy | Kötelező változók |
|---|---|---|---|---|
| Foglalási igény beérkezett | `booking_request_received_guest` | foglaló vendég | `Foglalási igény beérkezett – {{booking_reference}}` | `guest_name`, `booking_reference`, `arrival_date`, `departure_date`, `nights`, `adults`, `children_summary`, `status`, `contact_email` |
| Új foglalási igény | `booking_request_received_admin` | konfigurált admin címzett(ek) | `Új foglalási igény – {{booking_reference}}` | `booking_reference`, `arrival_date`, `departure_date`, `nights`, `guest_name`, `guest_email`, `guest_phone_optional`, `adults`, `children_summary`, `admin_booking_url` |
| Árajánlat | `booking_quote_guest` | foglaló vendég | `Árajánlat – {{booking_reference}}` | `guest_name`, `booking_reference`, dátumok, `price_lines`, `total_amount`, `currency`, `quote_valid_until`, `acceptance_instructions`, `contact_email` |
| Elfogadva | `booking_accepted_guest` | foglaló vendég | `Foglalás elfogadva – {{booking_reference}}` | `guest_name`, `booking_reference`, dátumok, létszám, `total_amount`, `currency`, `arrival_information_optional`, `contact_email` |
| Elutasítva | `booking_rejected_guest` | foglaló vendég | `Foglalási igény elutasítva – {{booking_reference}}` | `guest_name`, `booking_reference`, dátumok, `rejection_message_optional`, `contact_email` |
| Lemondva | `booking_cancelled_guest` | foglaló vendég | `Foglalás lemondva – {{booking_reference}}` | `guest_name`, `booking_reference`, dátumok, `cancelled_at`, `refund_or_fee_message_optional`, `contact_email` |
| 2FA kód | `admin_login_code` | hitelesítendő admin | `Admin belépési kód` | `admin_name`, `code`, `expires_in_minutes`, `requested_at`, `support_contact` |
| iCal szinkronhiba | `ical_sync_failed_admin` | üzemeltetési/admin címzett | `iCal szinkronhiba – {{source_name}}` | `source_name`, `failed_at`, `correlation_id`, `failure_summary`, `admin_ical_url` |
| Érkezés előtti emlékeztető | `pre_arrival_reminder_guest` | foglaló vendég | `Érkezési emlékeztető – {{booking_reference}}` | `guest_name`, `booking_reference`, `arrival_date`, `departure_date`, `arrival_information`, `contact_email` |

Az URL-ek csak konfigurált HTTPS alkalmazásalapról és útvonalból épülhetnek; a 2FA kód nem kerül URL-be. Dátumok vendégnek magyar, egyértelmű formában jelennek meg, számításuk `Europe/Budapest` időzónában történik.

> **DECISION REQUIRED:** levélnyelv(ek), végleges tárgysorok/szövegek, admin címzettlista, vendég reply-to, elutasítási ok láthatósága és érkezési információ tartalma.

### 3.1 Eseményindítási szabályok

- A két „igény beérkezett” üzenet csak sikeresen commitolt, idempotens foglalásmentés után kerül outboxba.
- Az árajánlat az új, befagyasztott ár-snapshot verzióhoz kötődik; ugyanaz a snapshot nem küldhető automatikusan kétszer.
- Elfogadás, elutasítás és lemondás csak sikeres, engedélyezett státuszváltás után keletkezik. Sikertelen tranzakció nem küld levelet.
- A 2FA levél új, hash-elve tárolt, érvényes kód létrehozása után készül; korábbi kód újraküldés helyett visszavonandó a hitelesítési specifikáció szerint.
- iCal hiba csak tartós/küszöböt elérő hiba esetén riasztson, ne minden egyes átmeneti próbálkozáskor.
- Érkezés előtti emlékeztető **DEFERRED/opcionális**, kizárólag megfelelő státuszú, nem lemondott foglalásra egyszer küldhető.

> **DECISION REQUIRED:** emlékeztető engedélyezése és hány nappal érkezés előtt fusson; iCal riasztási küszöb; mely booking státuszok indítják az egyes leveleket.

## 4. Sablonkezelés és renderelés — PLANNED

- Stabil template ID és változatlan sablonverzió kerüljön az outbox rekordba; későbbi szerkesztés ne változtassa meg a már sorba állított levél jelentését.
- Kötelező változó hiánya renderelési hiba, ilyenkor nincs SMTP-kísérlet.
- HTML-változó alapértelmezetten HTML-escape-elt; csak ellenőrzött strukturált komponens adhat engedélyezett markupot.
- Plain-text tartalom önálló sablon, nem HTML tagek egyszerű eltávolítása.
- HTML egyszerű, reszponzív és külső JavaScript nélküli; lényegi információ kép nélkül is olvasható.
- A template preview tesztadatot használ, valódi PII-t nem. Tesztküldés egyértelműen tesztként jelölt és auditált.

## 5. Outbox, idempotencia és konzisztencia — IMPLEMENTED

Az automatikus üzleti értesítéseknél az állapotváltozás és az outbox bejegyzés ugyanabban az adatbázis-tranzakcióban történik; SMTP csak commit után fut. A módosítási értesítés ettől szándékosan eltér: a booking módosítás mentése nem hoz létre outbox sort, az első explicit admin küldés pedig külön tranzakcióban hozza létre és rögtön claimeli azt. A Phase 10 worker kizárólag már létező rekordokat dolgoz fel, nem generál díjbekérőt, emlékeztetőt, érkezési tájékoztatót vagy más opcionális kommunikációt.

A kivételes díjbekérő nélküli megerősítés sem készít `booking_payment_request` vagy előleg-emlékeztető rekordot. A `pending -> confirmed` státuszváltással ugyanabban a tranzakcióban a normál immutable `booking_confirmed` outbox készül, majd annak SMTP-kísérlete commit után, a meglévő státuszlevél-dispatcherrel fut. Kéretlen vagy fabrikált előlegösszeg nem kerül tárolásra.

A worker egy nem blokkoló `GET_LOCK('abata_email_outbox_worker', 0)` kapcsolat-lockot használ. Második példány sikeres no-op. A rekord `processing` állapotba csak az előző `status` és `attempts` értékére feltételes `UPDATE` után kerülhet, ezért a webes azonnali dispatcherrel folytatott versenyben csak az egyik fél küld. Egy futás legfeljebb 25 rekordot érint és sorosan küld.

Támogatott immutable payload-típusok: `booking_request_received`, `booking_request_admin_notification`, `booking_confirmed`, `booking_rejected`, `booking_cancelled`, `booking_payment_request`, `booking_payment_reminder`, `booking_arrival_information`, `booking_modified`, `booking_review_request`. Ismeretlen típust a worker nem próbál renderelni, nem módosít és csak PII-mentes darabszámban jelent. A 2FA kifejezetten kizárt, mert mindig friss auth-kódot és külön rate-limit folyamatot igényel.

Bizonytalan SMTP-kimenetelnél – például ha a provider elfogadta a levelet, de a folyamat a DB-véglegesítés előtt megszakadt – provider-idempotencia nélkül elméletileg duplikált kézbesítés lehetséges. A konzervatív stale recovery ezt a bizonytalan kísérletet beleszámítja.

## 6. Állapotok, retry és hibakezelés — IMPLEMENTED

Az owner-approved szabály egyetlen `EmailOutboxRetryPolicy` objektumban él:

- maximum 5 teljes kézbesítési kísérlet;
- első sikertelen kísérlet után 5 perc, második után 15 perc, harmadik után 1 óra, negyedik után 6 óra;
- az ötödik hiba végleges ugyanazon a rekordon;
- a legalább 15 perce `processing` rekord stale;
- a cron célgyakorisága 5 perc.

A stale rekord atomikusan `failed` lesz, `attempts` értéke eggyel nő, `last_error` pontosan `Stale processing lease recovered.`, és a recovery időpontjától induló backoff előtt nem küldhető újra. A deterministic JSON/payload/render hiba SMTP nélkül azonnal végleges, öt próbás `failed` állapotot és kizárólag `Stored e-mail payload could not be rendered.` okot kap. Transporthiba eggyel növeli az attempts értéket, `sent_at=NULL`, a tárolt ok `E-mail transport failure.`; egy rekord hibája nem állítja meg a batch későbbi rekordjait. Siker az SMTP-elfogadást jelenti, `sent`, eggyel növelt attempts, törölt hiba és kitöltött `sent_at` mellett.

### 6.1 Admin újraküldés

A meglévő CSRF-, auth- és rate-limit védett, ugyanazon rekordot használó manuális retry megmarad, és az automatikus várakozást operátori döntéssel megkerülheti. Minden claim útvonal közös korlátja `attempts < 5`; öt próbánál nincs hatodik küldés és a gomb eltűnik. A booking részlet `attempts / 5` formában külön jelzi az automatikus retryra váró failed, a limitet elért failed, a processing és a sent állapotot. A `booking_modified` első rekordja nem resend: kizárólag a legutóbbi immutable módosításhoz, explicit admin szándékra készül és atomikusan `processing` állapotba kerül. Más új rekordos manuális resend **DEFERRED**. 2FA esetén továbbra is új kód készül a hitelesítési rate limitekkel.

## 7. Naplózás, PII és megőrzés — IMPLEMENTED auditminimum, retention OPEN

A worker PII-mentes `email.outbox_stale_recovered`, `email.outbox_retry_sent`, `email.outbox_retry_failed` és `email.outbox_payload_failed` auditot ír `admin_id=NULL` értékkel. Csak belső booking/outbox azonosító, message type és attempt szám tárolható. Auditírási hiba SMTP-elfogadás után nem teheti újra retry-képessé a már elküldött rekordot.

Az e-mail napló tartalmazhat: belső rekordazonosító, eseménytípus, aggregate hivatkozás, template ID/verzió, idempotenciakulcs vagy hash, maszkolt címzett, állapot, próbálkozásszám, ütemezett/elküldött idő, provider message ID és redaktált hibakategória.

Nem naplózható:

- SMTP jelszó/token, teljes konfiguráció vagy kapcsolatstring;
- 2FA kód, jelszó, session token, iCal feed token;
- teljes e-mail body vagy tárgy, ha PII-t tartalmaz;
- szükségtelen teljes e-mail-cím, vendégnév, telefonszám, megjegyzés;
- nyers szolgáltatói válasz kontrollálatlanul.

A template input minimális, strukturált whitelist alapján készül. A tartalomhoz és naplóhoz csak jogosult admin férhet hozzá. A Phase 10 nem töröl, archivál, anonimizál vagy purge-ol outbox rekordot, és cleanup cron sincs. A későbbi retention/törlési szabály továbbra is külön owner/legal döntés.

> **DECISION REQUIRED:** e-mail esemény-, tartalom- és provider-log megőrzési ideje, hozzáférési szerepkörök és törlési/anonimizálási eljárás.

## 8. Kézbesíthetőség és üzemeltetés — PLANNED

- A feladó domainhez SPF rekord engedélyezi a választott SMTP szolgáltatót.
- DKIM-aláírás aktív, kulcsrotáció dokumentált.
- DMARC kezdetben monitorozott, majd ellenőrzött eredmények alapján szigorítható; riportcím hozzáférése korlátozott.
- PTR/rDNS és HELO megfelelőség a szolgáltató felelősségi körével tisztázandó.
- Feladó és reply-to domain igazolt; production nem használ Mailpitet.
- SMTP kapcsolat és hitelesítés titkosított; tanúsítvány-ellenőrzés kikapcsolása tilos.
- Sikerráta, queue age, retry, permanent failure és cron utolsó siker monitorozott; titok/PII nélküli riasztások készülnek.
- Deployment előtt staging tesztcímekkel HTML/plain-text, link, mobilmegjelenés és spam/deliverability ellenőrzés szükséges.

## 9. Biztonsági követelmények — PLANNED

- Címek szerveroldali validációja; display name, subject és header értékekben CR/LF tiltása az e-mail header injection ellen.
- Sablonváltozók kontextusfüggő escapingje az e-mailes XSS/phishing kockázat csökkentésére.
- Publikus inputból tetszőleges címzett, feladó, tárgy, sablonazonosító vagy redirect URL nem választható.
- 2FA-kód rövid lejáratú, egyszer használatos, próbálkozás- és újraküldés-limitált; tartalma support/admin számára sem visszaolvasható.
- Adminlinkek HTTPS-ek; jogosultságot nem maga a link biztosít. Secret token csak az arra tervezett egyszer használatos folyamatban szerepelhet.
- E-mailhiba publikus válaszban nem fedheti fel, létezik-e admin vagy vendégcím.

## 10. Elfogadási feltételek — PLANNED

1. A kódban nincs közvetlen vagy fallback PHP `mail()`; minden küldés absztrakción és hitelesített TLS SMTP-n át történik.
2. Minden kötelező eseményhez verziózott HTML és plain-text sablon, változó-whitelist és renderelési teszt tartozik.
3. Booking/státusz esemény és outbox rekord atomi; rollbackelt műveletből nem megy levél.
4. Az idempotenciakulcs egyedi, ismételt esemény/cron nem okoz második automatikus küldést.
5. Unit/integrációs teszt fedi a retry-besorolást, backoffot, claimet, lease-helyreállítást és tartós hibát.
6. A meglévő same-record admin retry jogosultság-, CSRF- és rate-limit védett, öt kísérletnél leáll; az új rekordos, indokolt resend külön DEFERRED workflow.
7. Sem alkalmazási, SMTP-, exception- vagy auditnaplóban nincs credential, 2FA-kód, token vagy szükségtelen PII.
8. Mailpitben fejlesztői smoke teszt igazolja mindkét MIME-változatot; stagingen SMTP/TLS és kézbesíthetőségi smoke teszt fut.
9. SPF, DKIM és DMARC ellenőrzési eredménye production release checklist része.
10. Cron újrafutás, párhuzamos worker és SMTP timeout tesztelt; állapot és riasztás adminból követhető.
11. Az opcionális emlékeztető csak tulajdonosi döntés és dokumentált időzítés után aktiválható.

## 11. Ismert kockázatok és halasztások

- SMTP elfogadás utáni timeout esetén provider idempotencia nélkül elméletileg duplikált levél keletkezhet.
- Az e-mail nem garantál kézbesítést; `sent` az SMTP/provider átvételét jelenti, nem az inboxba érkezést.
- Bounce és complaint feldolgozás szolgáltatófüggő, döntésig hiányzik.
- **DEFERRED:** érkezés előtti emlékeztető végleges aktiválása.
- **OUT OF SCOPE:** marketingkampány, hírlevél és PHP `mail()` alapú küldés.

### Phase 10 CLI és cron — IMPLEMENTED kód, production aktiválás nélkül

Fejlesztői kézi futás PowerShellből:

```powershell
docker compose exec app composer email:outbox
```

A `bin/process-email-outbox.php` a közös environment bootstrapet használja, kimenete csak a `stale_recovered`, `claimed`, `sent`, `failed`, `final_failed` és `unsupported` számlálókat tartalmazza. No-work és már foglalt worker-lock esetén exit `0`; konfigurációs, adatbázis- vagy worker-infrastruktúrahibánál nem nulla; rekordszintű SMTP-hiba mellett a futás folytatódik és exit `0` marad.

Dokumentált, de **NOT YET ENABLED** production célritmus:

```cron
*/5 * * * * /ABSOLUTE/PHP/PATH /ABSOLUTE/RELEASE/PATH/bin/process-email-outbox.php
```

A valós PHP/release útvonalat a hosting adja. A Phase 10 nem módosít production cront és nem futtat production workert.

## 12. Sprint 4 booking-request e-mail — IMPLEMENTED

A booking tranzakció az SMTP művelet előtt létrehozza az egyedi outbox rekordot. Commit után az adapter atomi `pending` → `processing` claimet végez, majd a meglévő `Mailer` porton HTML és plain-text levelet küld. Siker esetén `sent`, biztonságosan kezelt transporthiba esetén `failed` állapot készül. A booking mindkét esetben `pending` marad.

A levél az A Bata nevet, publikus referenciát, dátumokat, éjszakák és vendégek számát, gyermekkorokat, végösszeget és HUF pénznemet tartalmazza, továbbá jelzi, hogy ez csak igény, amely admin jóváhagyás után válik véglegessé.

**IMPLEMENTED Phase 10:** a booking-request levélhez továbbra sincs admin resend, de a pending/failed rekordot az automatikus worker feldolgozza, a legalább 15 perce `processing` rekordot pedig konzervatívan helyreállítja.

## Státuszértesítések – IMPLEMENTED Sprint 5

Confirmed/rejected/cancelled outbox ugyanabban a tranzakcióban készül, SMTP commit után fut, és sent/failed auditot ír. Hiba nem rollbackeli a bookingot; failed levél adminból és az owner-approved Phase 10 policy szerint automatikusan is újrapróbálható, összesen legfeljebb öt kísérletig. Invalidated és belső admin note nem kerül vendéglevélbe.
## Sprint 6 cancellation notification — IMPLEMENTED

A `confirmed -> cancelled` tranzakció cancellation snapshotot és státusz-email outbox rekordot hoz létre. SMTP I/O továbbra is csak commit után történik. A vendéglevél közli, hogy a lemondás kötbérmentes, vagy megadja az immutable accommodation-fee snapshotból számolt 50%-os kötbért HUF-ban. A szöveg nem állít automatikus terhelést vagy beszedést. A Bata branding a HTML- és plain-text változatban megmarad.

## Foglalásmódosítási értesítés — IMPLEMENTED Phase 6

A confirmed booking módosításának mentése nem ír `booking_modified` outbox rekordot és nem küld levelet. A legutóbbi módosításhoz az admin külön POST művelettel kérheti a küldést; régebbi snapshot első küldése szerveroldalon tiltott. Az explicit művelet az immutable `after_snapshot` alapján egyedileg, `modification:{id}` kulccsal hozza létre és atomikusan claimeli az outbox sort. A worker `booking_modified + pending + attempts=0` rekordot nem kezdeményezhet, de az explicit kísérlet után `failed` 1–4 és stale `processing` állapotot a közös policy szerint kezel. A tárgy `Foglalásának adatai módosultak`; a levél az új időszakot/létszámot/árakat és a változatlan korábbi előleget tartalmazza, az általános mail identity és Reply-To beállítást használja.
