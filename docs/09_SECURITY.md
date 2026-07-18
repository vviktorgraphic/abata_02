# Biztonsági specifikáció és threat model

**Állapot:** az alább külön jelölt alapkontrollok IMPLEMENTED; az 1.0 hardening és auth kontrollok PLANNED
**Utolsó ellenőrzött commit:** `9adc564`

## Hatókör és biztonsági alapállás

Ez a dokumentum a publikus foglalási felületet és API-t, a tervezett adminfelületet, adatbázist, e-mailt, iCal-integrációt, naplókat, backupot és cPanel üzemeltetést fedi le. Az egyetlen jelenlegi admin route csak [JSON placeholder](04_ADMIN_AND_AUTHENTICATION.md#jelenlegi-állapot), ezért az adminvédelmek még nem tekinthetők működőnek.

**IMPLEMENTED:** környezeti változó alapú adatbázis-konfiguráció; hiányzó DB-változók fail-fast hibája; PDO exception mód, `utf8mb4`, natív prepared statement (`PDO::ATTR_EMULATE_PREPARES=false`); `public/` document root; `.env` kizárása a repositoryból; foglalási lekérdezésekben prepared statementek; publikus API általános 500-as hibája stack trace és credential nélkül.

**IMPLEMENTED Sprint 3 alap:** admin auth/2FA/session/CSRF, rate limit, audit metadata/persistence és SMTP adapter. **PLANNED vagy környezetfüggő:** HTTPS-kényszerítés, teljes security-header smoke, központi output escaping és input séma, GDPR-retenció, backup titkosítás/restore próba, iCal- és production SMTP-hardening.

## Védendő értékek és bizalmi határok

- vendég PII és foglalási adatok;
- admin jelszóhash, 2FA challenge, session és CSRF-token;
- adatbázis-, SMTP-, backup- és iCal-feed credential/token;
- foglaltság, árazás, beállítások és audit integritása;
- rendszer rendelkezésre állása és backup visszaállíthatósága.

Bizalmi határ van a böngésző és HTTPS végpont, PHP és MySQL/SMTP, cron és külső iCal szolgáltató, cPanel fájlrendszer és webroot, valamint backup tároló között. Minden határon inputvalidáció, minimális jogosultság, timeout és biztonságos hibakezelés szükséges.

## Könnyű threat model

Skála: valószínűség és hatás `Alacsony`, `Közepes` vagy `Magas`. Az állapot a `9adc564` commiton ellenőrzött tényleges védelmet jelzi.

| # | Fenyegetés | Érintett elem | Valószínűség | Hatás | Kötelező kontroll | Ellenőrzési mód | Állapot |
|---:|---|---|---|---|---|---|---|
| 1 | SQL injection | API, admin, MySQL | Közepes | Magas | PDO prepared statement; dinamikus oszlop/rendezés allowlist | Unit/integration teszt támadó inputtal; kódreview | IMPLEMENTED a jelenlegi értékes lekérdezéseknél; PLANNED minden új queryre |
| 2 | Tárolt/reflektált/DOM XSS | publikus UI, admin UI | Közepes | Magas | Kontextushelyes HTML/attribútum/JS escaping; `textContent`; CSP | Automata payload teszt és manuális böngészőteszt | PLANNED; nincs teljes admin/booking write felület |
| 3 | CSRF | admin auth POST-ok, logout | Magas | Magas | Sessionhöz kötött CSRF-token minden auth state-change kérésen; SameSite cookie | Feature teszt hiányzó/hibás tokennel | IMPLEMENTED auth route-okon |
| 4 | Jelszó brute-force | admin login | Magas | Magas | IP- és fiókalapú rate limit és lockout | Küszöb- és időablak teszt; staging terhelési próba | IMPLEMENTED konfigurálható alapértékekkel; production küszöb OPEN |
| 5 | 2FA-kód találgatása | 2FA verify | Közepes | Magas | 6 számjegy, 10 perc, max. 5 próbálkozás, atomi számláló, rate limit | Határérték-, lejárat- és persistence teszt | IMPLEMENTED |
| 6 | Session fixation | admin session | Közepes | Magas | Session ID rotation a pending és authenticated határon | Feature teszt régi ID érvénytelenségével | IMPLEMENTED komponensszinten |
| 7 | Session theft | admin cookie, kliens | Közepes | Magas | HTTPS, Secure/HttpOnly/SameSite, 15 perc idle és revoke | Cookie-header és visszavonási teszt stagingen | Idle/revoke IMPLEMENTED; HTTPS staging és abszolút maximum OPEN |
| 8 | Credential stuffing / fiók-enumeráció | admin login | Magas | Magas | Általános hiba, dummy-hash időzítés, rate limit | Ismeretlen/inaktív/hibás fiók teszt | IMPLEMENTED alap |
| 9 | Spam vagy automatizált booking | booking create | Magas | Közepes | Rate limit, idempotency key, honeypot, szervervalidáció | API abuse teszt és metrika/riasztás | IMPLEMENTED alap; production küszöb OPEN |
| 10 | E-mail header injection | SMTP feladó/címzett/tárgy | Közepes | Magas | SMTP adapter, címvalidáció, CR/LF tiltás, sablon allowlist; `mail()` tilos | Unit teszt CR/LF payloadokkal | IMPLEMENTED 2FA mailerben |
| 11 | iCal feed token kiszivárgása | export URL, log, analytics | Közepes | Magas | Nagy entrópiájú rotálható token, URL/log redaction, PII-mentes feed, cache szabály | Logscan, tokenrotációs és jogosulatlan hozzáférési teszt | PLANNED; iCal nincs |
| 12 | SSRF külső iCal URL-lel | iCal importer, belső hálózat | Magas | Magas | Csak HTTPS, DNS/IP validáció minden redirectnél, privát/link-local/metadata cím tiltása, port allowlist | SSRF tesztek loopback, RFC1918, IPv6 és redirect célokra | PLANNED |
| 13 | Rosszindulatú vagy túlméretes ICS | parser, memória/CPU, DB | Közepes | Magas | Méret-, esemény-, sor- és időkorlát, biztonságos parser, sémaellenőrzés, tranzakció | Fuzz, zip/size jellegű és hibás encoding tesztek | PLANNED |
| 14 | Race condition / double booking | booking create, MySQL | Magas | Magas | Készlet-sorzár, tranzakciós confirmed/blocked újraellenőrzés és idempotencia | Párhuzamos integration teszt; pending overlap engedett | IMPLEMENTED |
| 15 | PII vagy secret a logokban | app, audit, SMTP/iCal log | Közepes | Magas | Strukturált allowlist log, redaction, korrelációs ID; body/token/jelszó tiltása | Automata logscan ismert canary értékekkel | PLANNED egységesen; jelenlegi API hiba általános |
| 16 | Secret commit/repository history | Git, `.env`, config | Közepes | Magas | `.env` ignore, `.env.example` csak placeholder, secret scanner, rotációs eljárás | CI secret scan és release előtti history ellenőrzés | IMPLEMENTED ignore/példa szabály; PLANNED automata scan |
| 17 | Jogosulatlan adminművelet / IDOR | admin API és objektumok | Magas | Magas | Minden kérésen szerveroldali authz, objektumszintű ellenőrzés, deny-by-default, audit | Feature teszt anonim, lejárt és más azonosítós kéréssel | PLANNED; admin üzleti API nincs |
| 18 | Backup kiszivárgása | SQL dump, fájlbackup | Közepes | Magas | Titkosítás átvitelkor és tároláskor, elkülönített minimális hozzáférés, retenció, leltár | Jogosultság-review, restore gyakorlat és hozzáférési audit | PLANNED |
| 19 | Clickjacking | publikus/admin oldal | Közepes | Közepes | CSP `frame-ancestors 'none'` és/vagy `X-Frame-Options: DENY` | HTTP header teszt | PLANNED |
| 20 | MIME sniffing / tartalomértelmezés | HTTP válaszok, assetek | Közepes | Közepes | Helyes Content-Type, `X-Content-Type-Options: nosniff` | Header smoke teszt minden response-osztályra | PLANNED; JSON Content-Type IMPLEMENTED az ismert JSON válaszokon |
| 21 | Hibás CORS | API, admin session | Közepes | Magas | Same-origin alapértelmezés; nincs wildcard credentiallel; explicit origin/method/header allowlist ha szükséges | Preflight és idegen Origin teszt | PLANNED explicit policy; jelenleg nincs CORS engedélyezés |
| 22 | Open redirect | login utáni `return_to` | Közepes | Közepes | Csak relatív belső allowlist útvonal, séma/host tiltás | Redirect payload feature teszt | PLANNED |
| 23 | Hibainformáció- és stack trace szivárgás | API, PHP/cPanel log | Közepes | Közepes | Production `display_errors=Off`, általános válasz, védett részletes log | Hibainjektálás stagingen és response/log review | IMPLEMENTED általános 500 az availability/validate ágon; PLANNED globális handler |
| 24 | Tömeges mezőhozzárendelés / hibás input | planned admin/booking write API | Közepes | Magas | Végpontonkénti input schema, allowlist DTO, hossz/típus/tartomány limit | Ismeretlen és privilegizált mezők feature tesztje | PLANNED |
| 25 | Függőség/supply-chain kompromittálása | Composer, deployment | Közepes | Magas | `composer.lock`, audit, minimális csomagok, trusted artifact, review | `composer audit`, lock-diff review, SBOM döntés szerint | IMPLEMENTED lockfile; PLANNED release gate |

## Kötelező kontrollok

### Jelszó és 2FA

**PLANNED:** jelszót csak PHP `password_hash()` aktuális ajánlott algoritmusával szabad tárolni, plaintextben, visszafejthetően vagy logban soha. Verify után szükség szerint rehash készül. Jelszó-reset külön, egyszer használatos, rövid lejáratú folyamatot igényel; megvalósítása előtt külön specifikáció szükséges. A 2FA részleteit, lockoutot és hibafolyamatokat az [admin auth dokumentum](04_ADMIN_AND_AUTHENTICATION.md#bejelentkezési-állapotgép) szabályozza.

### HTTPS, cookie és session

**IMPLEMENTED alkalmazási támogatás:** productionben a `SESSION_COOKIE_SECURE=true`, a pozitív `HSTS_MAX_AGE_SECONDS` és az explicit `ADMIN_SESSION_ABSOLUTE_TIMEOUT_SECONDS` kötelező. A HSTS fejléc kizárólag production környezetben és HTTPS-kérésnél kerül ki. Az alkalmazás az `X-Forwarded-Proto` értéket csak a `TRUSTED_PROXY_IPS` pontos IP-listáján szereplő reverse proxytól fogadja el; üres lista mellett nem bízik forwarded fejlécben. A közös publikus és admin válaszok nosniff, frame, referrer és permissions headereket kapnak; az admin ezen felül no-store és szigorú CSP választ használ. **KÖRNYEZETFÜGGŐ RELEASE-KAPU:** a webszerver HTTP→HTTPS átirányítása, TLS és HSTS staging smoke továbbra is deployment feladat.

Fejlesztési alapérték: 28 800 másodperc abszolút admin session-élettartam és kikapcsolt HSTS (`0`). Productionben mindkettőt explicit kell beállítani; production timeoutot és HSTS időtartamot a rendszer nem talál ki.

### Security headerek

**PLANNED:** minden dinamikus válaszon és lehetőség szerint asseten:

- `Content-Security-Policy` szűk `default-src`, script/style/image/connect direktívákkal; inline kivétel csak nonce/hash alapján;
- `X-Content-Type-Options: nosniff`;
- `Referrer-Policy: strict-origin-when-cross-origin` vagy szigorúbb;
- `Permissions-Policy` a nem használt képességek tiltásával;
- `frame-ancestors 'none'` és kompatibilitási `X-Frame-Options: DENY`;
- admin és érzékeny válaszon `Cache-Control: no-store`.

A konkrét CSP-t a jelenlegi inline/template használat felmérése után kell rögzíteni; működést gyengítő `unsafe-inline` nem lehet végleges kerülőút.

### CSRF, CORS és output escaping

**PLANNED:** cookie-alapú admin session minden state-change kérésén CSRF-tokent követel. A publikus booking create esetében is dokumentálni kell a választott CSRF/Origin és botvédelmi modellt. Az API same-origin; CORS csak igazolt consumer esetén, explicit allowlisttel engedhető. Minden output a célkontextus szerint escape-elendő; adatbázisba mentett HTML alapértelmezetten nem megbízható.

### Inputvalidáció és adatbázis

**IMPLEMENTED:** a jelenlegi availability és validation flow végez szerveroldali dátum- és üzletiszabály-validációt, a persistence lekérdezések prepared statementeket használnak. **PLANNED:** minden új endpoint explicit mező-, típus-, hossz-, enum- és tartomány allowlistet kap; fájl/URL/ICS input külön méret- és protokollkorlátos. SQL-ben értéket soha nem szabad konkatenálni; nem paraméterezhető identifier csak statikus allowlistből jöhet. Az alkalmazás DB-user csak a szükséges adatbázison, minimális joggal működik, root credentialt nem használ.

### Rate limit és visszaélésvédelem

**PLANNED:** login, 2FA verify/resend, booking validate/create és iCal kézi sync külön limiteket kap. A kulcs legalább IP + cél/fiók/challenge kombináció, a számláló atomi. `X-Forwarded-For` csak konfigurált megbízható proxy mögött használható. A válasz ne segítsen fiók-enumerációban; a küszöbök a [döntési naplóban](11_ROADMAP_AND_DECISIONS.md#nyitott-üzleti-kérdések) lezárandók.

### Audit és naplózás

**PLANNED:** admin login, 2FA, logout, session revoke, booking/pricing/blocked period/settings változás, e-mail újraküldés, iCal forrás/sync és backup/restore művelet auditált. Az audit append-only, hozzáférése minimális, megőrzése dokumentált. Jelszó, kód, session/CSRF/feed token, DB/SMTP credential és teljes request body tiltott. Napló-hozzáférés és export maga is auditált.

### Backup, restore és rendelkezésre állás

**PLANNED:** automatikus, titkosított DB- és szükséges fájlbackup; elkülönített tároló; dokumentált retenció és rotáció; rendszeres restore próba stagingre; helyreállítás után integritás- és jogosultságellenőrzés. Backup nem tölthető webroot alá. Az RPO/RTO és retenció **DECISION REQUIRED**. Részletes üzemeltetés: [tesztelés és üzemeltetés](10_TESTING_AND_OPERATIONS.md).

## Adatmegőrzés és GDPR

**PLANNED:** adatminimalizálás, célhoz kötöttség és hozzáférési korlátozás alkalmazandó. A foglalási űrlap adatkezelési tájékoztatója különítse el a szerződés/ajánlat kezeléséhez szükséges adatot az opcionális hozzájárulástól; előre bepipált checkbox nem használható. Érintetti hozzáférési, helyesbítési, törlési/korlátozási és exportkérésekhez azonosítási és auditált eljárás kell. Jogi/számviteli megőrzés felülírhat törlési kérelmet, de csak dokumentált jogalappal és minimális adatkörrel.

> **IMPLEMENTED Sprint 8 technikai kontroll:** a kötelező privacy checkbox konfigurált URL/verzió immutable snapshotját és audit eseményét a booking tranzakció tárolja. A két publikus jogi útvonal biztonságosan escape-elt, `noindex` fejlesztői placeholdert ad. **P0 RELEASE GATE:** a tényleges tartalmat, jogalapot, terminológiát, verziót és hatálybalépést jogi/tulajdonosi jóváhagyás nélkül tilos productionben publikálni.

> **DECISION REQUIRED:** booking, vendég, audit, e-mail napló és backup konkrét megőrzési ideje, adatkezelői tájékoztató, jogalapok és incidensfolyamat tulajdonosi/jogi jóváhagyást igényelnek.

Teszt- és demo adat nem tartalmazhat valódi személyes adatot. Production adat fejlesztői környezetbe csak dokumentált, szükséges és megfelelően anonimizált módon kerülhet.

## Titkok kezelése cPanelen

**PLANNED:** valódi `.env`, credential és privát kulcs soha nem kerül Gitbe vagy `public/` alá. cPanelen a secret a webrooton kívüli, alkalmazásuser által olvasható és más user által nem olvasható konfigurációban vagy szolgáltatói secret-mechanizmusban legyen. A `.env.example` csak nem titkos mintaneveket/placeholdert tartalmaz. Admin, DB, SMTP és iCal token külön credential; minimális jogú és rotálható. Debug képernyő, phpinfo, support bundle és backup nem fedheti fel őket.

Secret incidensnél nem elég a fájl törlése: credential azonnali visszavonása/rotációja, Git history és log/backup érintettség vizsgálata, hozzáférések áttekintése és auditált incidensjegy szükséges.

## Biztonsági elfogadási feltételek 1.0-hoz

1. HTTPS és a dokumentált headerek staging/production smoke teszten megfelelnek.
2. Admin auth, 2FA, session, CSRF, rate limit, lockout és authz negatív tesztjei sikeresek.
3. Booking concurrency tesztben azonos időszakra több pending sikerülhet, de confirmed/blocked intervallum nem kerülhető meg; azonos idempotenciakulcs csak egy bookingot eredményez.
4. Minden SQL value prepared statement; dinamikus identifier allowlistelt, kódreview-val igazolva.
5. XSS, SSRF, malicious ICS, CORS, clickjacking, MIME sniffing és header injection teszt lefut.
6. Secret scanner és dependency audit nem jelez kezeletlen magas kockázatot.
7. Logscan igazolja, hogy canary jelszó/token/PII nem kerül alkalmazás- vagy auditlogba.
8. Titkosított backupból staging restore sikeres, és az RPO/RTO dokumentált.
9. Adatmegőrzési szabályok és GDPR-tájékoztató tulajdonosi/jogi jóváhagyást kapnak.
10. Nyitott magas kockázat csak dokumentált tulajdonossal, határidővel és kifejezett release-elfogadással maradhat.

## Kapcsolódó dokumentumok

- [Architektúra](01_ARCHITECTURE.md)
- [Adatbázis- és domainmodell](02_DATABASE_AND_DOMAIN_MODEL.md)
- [Admin és hitelesítés](04_ADMIN_AND_AUTHENTICATION.md)
- [E-mail folyamatok](06_EMAIL_WORKFLOWS.md)
- [iCal-szinkron](07_ICAL_SYNC.md)
- [API-referencia](08_API_REFERENCE.md)
- [Tesztelés és üzemeltetés](10_TESTING_AND_OPERATIONS.md)
- [Roadmap és döntések](11_ROADMAP_AND_DECISIONS.md)

## Sprint 3 biztonsági kontrollok – IMPLEMENTED alap

- A jelszó PHP password API-val ellenőrzött; az ismeretlen és hibás credential általános eredményt és dummy-hash ellenőrzést kap.
- A 2FA-kód hat számjegyű, hash-elve tárolt, 10 percig és legfeljebb öt próbáig érvényes; plaintext kód nem auditálható.
- A sessionazonosító pending és authenticated határon rotálható; a szerveroldali token hash-elve tárolt; 15 perc inaktivitás után lejár.
- Minden admin POST sessionhöz kötött, timing-safe CSRF-ellenőrzést kap.
- A rate-limit kulcsok secret pepperrel HMAC-pszeudonimizáltak; a login és 2FA policy külön konfigurálható.
- Az audit metadata allowlistelt; jelszó, 2FA/CSRF/session token, nyers e-mail és nyers IP nem engedett.
- Az SMTP adapter nem használ `mail()` fallbacket és nem teszi kivételbe a provider nyers válaszát.

**DECISION REQUIRED:** az abszolút session-élettartam hiánya tudatos nyitott döntés; a 15 perces idle timeout nem helyettesíti. A production SMTP port/TLS/auth és a végleges rate-limit küszöbök release előtt lezárandók.

## Sprint 4 publikus write kontrollok – IMPLEMENTED

- Kizárólag JSON objektum, explicit DTO/mezővalidáció, body- és mezőlimitek, privacy követelmény és honeypot.
- Böngészőkérésnél konfigurált Origin/Referer allowlist; header nélküli nem böngészős kliens engedett. CORS wildcard nincs.
- IP-alapú konfigurálható rate limit; `429` válasz nyers IP vagy secret visszaadása nélkül.
- Készletzárral védett tranzakciós confirmed/blocked újraellenőrzés; pending átfedés szándékosan megengedett.
- Hash-elt idempotenciakulcs és request hash; ugyanaz a kulcs eltérő payloadnál `409`.
- PDO prepared statement minden értékhez, publikus válaszban nincs belső ID, stack trace vagy PII-visszatükrözés.
- SMTP kizárólag commit után; credential és nyers provider válasz nem kerül publikus hibába.

**PLANNED:** production küszöbök és originlista jóváhagyása, jogi privacy-link, központi strukturált booking audit, automatikus secret scan, teljes böngésző/WCAG és staging abuse teszt.

## Sprint 5 kontrollok – IMPLEMENTED

Admin auth, CSRF, no-store/security headerek, prepared queryk, output escaping, body/note limit, mass-assignment whitelist, action rate limit és szerveroldali reference lookup működik. Audit metadata nem tartalmaz note-ot, vendég PII-t, session- vagy CSRF-tokent.
## Sprint 6 kontrollok — IMPLEMENTED

- Booking-policy bypass ellen külön szerveroldali boolean validáció és tranzakciós snapshot véd; a kliensoldali `required` csak kiegészítő kontroll.
- Policy URL csak relatív vagy HTTPS; HTTP kizárólag development/local/testing környezetben engedett.
- Pricing state change route-ok admin authot, CSRF-et, no-store választ, rate limitet, PRG-t, szigorú mass-assignment whitelistet és numerikus/dátum boundary validációt használnak.
- Azonos prioritású, átfedő aktív szabály nem oldható fel véletlenszerűen; explicit konfliktus és PII-mentes audit keletkezik.
- Lemondási összeg kizárólag az immutable booking snapshot accommodation-fee mezőjéből készül, Europe/Budapest naptári dátummal.
