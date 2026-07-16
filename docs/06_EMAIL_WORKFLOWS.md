# E-mail folyamatok

**Állapot:** 2FA mailer, booking-request e-mail és szűk outbox IMPLEMENTED; általános retry/admin workflow PLANNED
**Utolsó ellenőrzés:** 2026-07-16, Sprint 3 munkafa (commit előtt)

Ez a dokumentum az 1.0 tranzakciós e-mail folyamatait tervezi. Kapcsolódó dokumentumok: [admin és hitelesítés](04_ADMIN_AND_AUTHENTICATION.md), [adatbázis- és domainmodell](02_DATABASE_AND_DOMAIN_MODEL.md), [árképzés](05_PRICING.md), [iCal](07_ICAL_SYNC.md), [biztonság](09_SECURITY.md), [tesztelés és üzemeltetés](10_TESTING_AND_OPERATIONS.md).

## 1. Jelenlegi állapot — IMPLEMENTED / hiányzó

**IMPLEMENTED Sprint 3:** `Mailer` port, strukturált `Message`, HTML/plain-text 2FA sablonrenderer, tesztelhető in-memory mailer és socket-alapú SMTP adapter. Az adapter Mailpithez plain SMTP-t, production konfigurációhoz titkosítást és opcionális authentikációt támogat; a PHP `mail()` függvényét nem használja. Transporthiba nem adja vissza a provider nyers válaszát, így credential vagy PII nem kerül kivételszövegbe.

**PLANNED:** outbox, retry worker, általános foglalási e-mail események, provider-idempotencia, bounce/complaint és admin újraküldés.

**DECISION REQUIRED:** a production host tulajdonosi értéke `s54.tarhely.com`, de a port, TLS mód, authentikáció, felhasználónév, feladó cím és reply-to továbbra is nyitott. Ezek hiányában production SMTP smoke nem tekinthető teljesítettnek.

**IMPLEMENTED:** Docker fejlesztésben Mailpit szolgáltatás elérhető, és a repository szabálya tiltja a PHP `mail()` közvetlen használatát.

**IMPLEMENTED részhalmaz:** van mailer absztrakció, SMTP klienskonfiguráció és HTML/plain-text 2FA sablon. **PLANNED:** outbox, e-mail napló, retry worker/cron, admin újraküldés és foglalási eseményekből induló levél.

## 2. Transport és komponensek — PLANNED

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

## 5. Outbox, idempotencia és konzisztencia — PLANNED

Az üzleti állapotváltozás és az outbox bejegyzés ugyanabban az adatbázis-tranzakcióban történjen. SMTP-küldés csak commit után, külön feldolgozóban történik. Így adatbázis rollback után nem megy téves levél, SMTP-kiesés pedig nem görgeti vissza az üzleti műveletet.

Javasolt idempotenciakulcs:

```text
event_type : aggregate_id : aggregate_version : recipient_identity_hash : template_version
```

Az idempotenciakulcs adatbázis-szinten egyedi. A worker atomi claim/lock segítségével vált `pending → processing` állapotba. Siker után ugyanaz a rekord nem küldhető újra automatikusan. Bizonytalan SMTP-kimenetelnél (timeout a szolgáltató elfogadása után) fennállhat duplikáció; ahol elérhető, stabil provider message/idempotency ID használandó.

> **DECISION REQUIRED:** outbox és napló egy vagy külön táblában legyen-e, claim mechanizmus, batch méret és worker cron gyakorisága. Minden sémaváltozás verziózott migrációt igényel.

## 6. Állapotok, retry és hibakezelés — PLANNED

Tervezett állapotok: `pending`, `processing`, `sent`, `retry_scheduled`, `failed_permanent`, `cancelled`, valamint admin által új eseményként létrehozott `manual_resend` kapcsolat.

- Átmeneti hibák (timeout, kapcsolat, SMTP `4xx`, szolgáltatói ideiglenes limit): exponenciális backoff jitterrel.
- Tartós hibák (érvénytelen cím, SMTP `5xx`, tiltott feladó, renderelési/konfigurációs hiba): automatikus retry nélkül `failed_permanent`, adminjelzéssel.
- Szemléltető retry-sor: 1, 5, 15, 60 perc, majd 6 óra; ez **nem végleges üzemi érték**.
- Minden kísérlet növeli a számlálót és rögzíti az időt, eredménykategóriát és redaktált hibát.
- Beragadt `processing` rekord lease lejárta után biztonságosan újra claimelhető.
- A worker hibája nem állíthatja le a teljes batch feldolgozását; rekordonként izolált hiba kell.

> **DECISION REQUIRED:** maximális próbálkozásszám, backoff idők, lease timeout, riasztási küszöb és bounce/complaint utáni címletiltás.

### 6.1 Admin újraküldés

Az admin nem állíthat egy hibás rekordot egyszerűen vissza `pending` állapotba. Újraküldés új outbox rekordot és új idempotenciakulcsot hoz létre, hivatkozik az eredetire, kötelező indokot kér, jogosultság- és CSRF-védett, auditált. A felület mutassa a címzett maszkolt formáját, sablonverziót, állapotot, próbálkozásokat és redaktált hibát. 2FA kód kézi újraküldése tilos; új kódot kell generálni az auth rate limitjeivel.

## 7. Naplózás, PII és megőrzés — PLANNED

Az e-mail napló tartalmazhat: belső rekordazonosító, eseménytípus, aggregate hivatkozás, template ID/verzió, idempotenciakulcs vagy hash, maszkolt címzett, állapot, próbálkozásszám, ütemezett/elküldött idő, provider message ID és redaktált hibakategória.

Nem naplózható:

- SMTP jelszó/token, teljes konfiguráció vagy kapcsolatstring;
- 2FA kód, jelszó, session token, iCal feed token;
- teljes e-mail body vagy tárgy, ha PII-t tartalmaz;
- szükségtelen teljes e-mail-cím, vendégnév, telefonszám, megjegyzés;
- nyers szolgáltatói válasz kontrollálatlanul.

A template input minimális, strukturált whitelist alapján készüljön. A tartalomhoz és naplóhoz csak jogosult admin férhet hozzá; export auditált. Adatmegőrzési idő után törlés vagy anonimizálás szükséges, a jogi/számviteli igények és backup-retention összehangolásával.

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
6. Admin újraküldés új, hivatkozott rekord, jogosultság- és CSRF-védett, indokolt és auditált.
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

## 12. Sprint 4 booking-request e-mail — IMPLEMENTED

A booking tranzakció az SMTP művelet előtt létrehozza az egyedi outbox rekordot. Commit után az adapter atomi `pending` → `processing` claimet végez, majd a meglévő `Mailer` porton HTML és plain-text levelet küld. Siker esetén `sent`, biztonságosan kezelt transporthiba esetén `failed` állapot készül. A booking mindkét esetben `pending` marad.

A levél az A Bata nevet, publikus referenciát, dátumokat, éjszakák és vendégek számát, gyermekkorokat, végösszeget és HUF pénznemet tartalmazza, továbbá jelzi, hogy ez csak igény, amely admin jóváhagyás után válik véglegessé.

**TECHNICAL DEBT / PLANNED:** nincs automatikus retry worker, admin resend vagy stale `processing` recovery. Ha a folyamat a claim után a végállapot frissítése előtt megszakad, a rekord `processing` állapotban maradhat; ezt időkorlátos reclaimmel és cron-kompatibilis retryval kell kezelni.

## Státuszértesítések – IMPLEMENTED Sprint 5

Confirmed/rejected/cancelled outbox ugyanabban a tranzakcióban készül, SMTP commit után fut, és sent/failed auditot ír. Hiba nem rollbackeli a bookingot; failed levél adminból újraküldhető. Invalidated és belső admin note nem kerül vendéglevélbe. Az automatikus retry/max-attempt/stale reclaim továbbra is **PLANNED / OWNER DECISION REQUIRED**.
