# Production deployment – cPanel/Apache

**Állapot:** IMPLEMENTED deployment artefaktumok és reprodukálható runbook; a szolgáltatói és tulajdonosi értékek OPEN release-kapuk.

## Biztonsági előfeltételek

A deploy csak jóváhagyott jogi tartalommal, ellenőrzött backupból visszaállási lehetőséggel, hitelesített SMTP-vel és működő HTTPS-sel végezhető el. A repository nem tartalmaz production credentialt. A `.env.production.example` kizárólag mezőleltár: minden `<...>` értéket a hosting secret store-ban kell kitölteni.

### Jogi dokumentumverziók

Productionben a privacy policy azonosítója `PRIVACY_POLICY_VERSION=2018-09-10`. A foglalási szabályzatnak nincs tulajdonostól kapott emberi verziója, ezért a ténylegesen közzétett PDF bájtjainak fingerprintje szükséges. Deploy előtt futtasd:

```powershell
curl.exe -fsSL 'https://abata.hu/abata_foglalasi_szabalyzat.pdf' -o .\booking-policy.pdf
$hash = (Get-FileHash .\booking-policy.pdf -Algorithm SHA256).Hash.ToLowerInvariant()
"BOOKING_POLICY_VERSION=sha256:$hash"
Remove-Item .\booking-policy.pdf
```

Az így kapott sort másold a hosting secret store production `.env` értékébe. A preflight productionben elutasítja az angle-bracket helyőrzőket és a hibás SHA-256 fingerprintet; a repositoryban nem szerepelhet a valódi production `.env`.

A public booking két külön outbox üzenetet hoz létre: a vendégnek `booking_request_received`, az ownernek `booking_request_admin_notification`. Productionben a címzett `BOOKING_NOTIFICATION_EMAIL=foglalas@abata.hu`, az admin link alapja `BOOKING_ADMIN_BASE_URL=https://foglalas.abata.hu/admin/bookings`; az üzenetek külön claimelhetők és idempotens replay esetén nem duplikálódnak.

Követelmény: PHP 8.2 vagy újabb 8.x (productionen jelenleg PHP 8.3 tesztelt), Composer 2, MySQL 8.0 vagy MariaDB 10.6, Apache `mod_rewrite`, valamint PHP `pdo`, `pdo_mysql`, `mbstring`, `curl` és `openssl`. A migrációs mátrix mindkét adatbázis-motoron fut. Ajánlott production PHP-beállítás: `display_errors=Off`, `log_errors=On`, `expose_php=Off`, `session.use_strict_mode=1`. A szolgáltató által kezelt hibanapló és session könyvtár nem lehet weben elérhető.

## Könyvtárak és document root

Javasolt elrendezés, ahol `<account>` és `<release-id>` deployment érték:

```text
/home/<account>/apps/foglalo/releases/<release-id>/   alkalmazáskód
/home/<account>/apps/foglalo/shared/                   webrooton kívüli operations fájlok
/home/<account>/public_html -> /home/<account>/apps/foglalo/releases/<release-id>/public
```

A domain document rootja kizárólag a release `public/` könyvtára vagy pontosan arra mutató támogatott symlink lehet. A `public/` fájljait tilos önmagukban `public_html` alá másolni: a front controller a szülő release könyvtárban keresi a `vendor/`, `config/`, `src/` és `templates/` elemeket. A `src/`, `config/`, `database/`, `vendor/`, `.env`, backup és log soha nem lehet a webroot alatt. Az alkalmazás jelenleg nem igényel feltöltési vagy cache könyvtárat, ezért a release kódnak nem kell írhatónak lennie. Írási jog csak a hosting által használt, webrooton kívüli session- és logkönyvtárhoz, illetve az operations runbook által kijelölt backup célhoz kell. Ne adj rekurzívan `777` jogot.

### Statikus fájlok shared-hosting kompatibilitása

Az aktuális shared-hosting nginx környezetben az `/assets/` útvonal foglalt és HTTP 403 választ ad. Az alkalmazás statikus fájljai ezért a `/static/` útvonalon érhetők el; a release-ben a `public/static/` könyvtárat kell publikálni.

## Első telepítés és release

Az alábbi parancsok PowerShellből, SSH-n keresztül vagy a cPanel Terminalban azonos sorrendben futtathatók; a konkrét SSH hostot és elérési utat a szolgáltató adja meg.

1. Töltsd fel az ellenőrzött commit tiszta release artefaktumát egy új, nem webes release könyvtárba. Ne tölts fel `.git`, `.env`, tesztadat vagy backup fájlt.
2. Futtasd a release gyökerében: `composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction`.
3. Ellenőrizd: `composer validate --no-check-publish` és `composer audit --locked`. A projekt jelenleg csak fejlesztői Composer csomagokat zárol; a `--no-dev` audit üres csomaglistával hibakódot adna, ezért a release lock teljes tartalmát auditáljuk.
4. A production titkok a release gyökerében, a webrooton kívüli `.env` fájlban legyenek; a fájlt tilos commitolni vagy a `public/` alá tenni. A web és minden CLI entry point ugyanazt a közös environment bootstrapet tölti be, ezért normál CLI használathoz nem kell `source .env` vagy kézi `DB_*` export. A már létező process/server environment változók elsőbbséget élveznek. A handler és CLI környezetét secret kiírása nélkül külön ellenőrizd; ne tedd a secretet `.htaccess` fájlba.
5. Futtasd az új kóddal, ugyanazon production környezettel: `php bin/db-check.php`, majd `php bin/migrate.php`. Migráció előtt kötelező az ellenőrzött backup.
6. Állítsd a domain document rootját az új release `public/` könyvtárára. Symlinkcsere csak akkor használható, ha a cPanel konfigurációja követi és a váltás atomi.
7. Ha Apache maga terminálja a TLS-t, a tanúsítvány és HTTPS-végpont működése után cseréld a sablon minden `PRODUCTION_HOST` helyőrzőjét a regexhez escape-elt, jóváhagyott canonical hostnévre, ellenőrizd, hogy helykitöltő nem maradt benne, majd másold a `deploy/apache/public.htaccess.production` tartalmát a production release `public/.htaccess` fájljába. A redirect nem tükröz tetszőleges `Host` fejlécet. Reverse proxy/CDN TLS termination esetén ezt a sablont tilos telepíteni, mert Apache HTTP-t látna és redirect loop keletkezhet; ilyenkor a proxy végzi a canonical-host validációt és redirectet, az alkalmazás pedig az exact `TRUSTED_PROXY_IPS` listával fogadja el a HTTPS jelzést. A repository alap `public/.htaccess` szándékosan nem kényszerít HTTPS-t, így a lokális Docker HTTP nem törik el.
8. Futtasd végig az alábbi smoke checklistet, majd rögzítsd a commitot, időpontot és végrehajtót a release naplóban – credential nélkül.

## HTTPS, proxy és session smoke

Az alkalmazás productionben csak `SESSION_COOKIE_SECURE=true`, pozitív `HSTS_MAX_AGE_SECONDS` és explicit abszolút admin session limit mellett indul. A HSTS csak valóban HTTPS-nek felismert kérésen jelenik meg.

- Apache TLS termination esetén `TRUSTED_PROXY_IPS` maradjon üres.
- Reverse proxy/CDN TLS termination esetén a redirectet a proxyn kell beállítani. Csak a kontrollált proxy pontos IP-címe kerüljön `TRUSTED_PROXY_IPS` értékbe; tartományt és felhasználói `X-Forwarded-Proto` fejlécet tilos megbízhatónak tekinteni.
- Először rövid, jóváhagyott HSTS értékkel staging smoke szükséges. `includeSubDomains` vagy preload nincs automatikusan bekapcsolva; csak a teljes domainállomány felmérése után engedhető.

PowerShell smoke egy előre beállított `$BaseUrl` változóval:

```powershell
$http = Invoke-WebRequest -Uri ($BaseUrl -replace '^https://', 'http://') -MaximumRedirection 0 -SkipHttpErrorCheck
if ($http.StatusCode -notin 301,302,307,308) { throw 'HTTP redirect missing' }
if (-not $http.Headers.Location.StartsWith('https://')) { throw 'Redirect is not HTTPS' }

$health = Invoke-WebRequest -Uri "$BaseUrl/health"
if ($health.StatusCode -ne 200) { throw 'Health check failed' }

$login = Invoke-WebRequest -Uri "$BaseUrl/admin/login" -SessionVariable session
if ($login.Headers.'Strict-Transport-Security' -notmatch '^max-age=[1-9][0-9]*') { throw 'HSTS missing' }
if (($login.Headers.'Set-Cookie' -join ';') -notmatch 'Secure') { throw 'Secure cookie missing' }
```

Ellenőrizd továbbá a `nosniff`, frame/CSP, referrer és admin `no-store` headereket, a tanúsítvány hostnevét és lejáratát, továbbá hogy HTTP POST esetén a redirect nem veszít metódust (a template ezért 308-at használ).

## Production SMTP

Productionben `MAIL_HOST`, `MAIL_PORT`, `MAIL_TIMEOUT_SECONDS`, `MAIL_ENCRYPTION=tls|ssl`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_EMAIL` és `MAIL_FROM_NAME` kötelező; hitelesítés nélküli vagy plaintext transport fail-fast hibát ad. A portot, timeoutot és titkosítási módot kizárólag a szolgáltató dokumentációja alapján válaszd, credentialt ne adj parancssori argumentumban és ne naplózz.

Az SMTP/levelezési szolgáltatónál:

- igazold a feladó domaint/címet;
- a provider által adott pontos SPF rekordot publikáld, a meglévő SPF-fel egyesítve – ugyanazon néven ne legyen két SPF rekord;
- a provider által adott selectorral és publikus kulccsal publikáld a DKIM rekordot;
- DMARC-ot tulajdonosi döntés szerinti policyval és riport címzettel vezess be; enforcement előtt staging és riportértékelés szükséges.

Provider DNS rekordot, selector nevet, portot, policyt vagy credentialt ez a projekt nem talál ki.

Staging smoke:

1. Staging környezetben valós, erre kijelölt tesztpostafiókkal jelentkezz be, és ellenőrizd a 2FA-levelet.
2. Hozz létre nem valós személyt tartalmazó tesztfoglalást, majd ellenőrizd a request- és státuszleveleket HTML és text kliensben.
3. A fogadó fejlécében ellenőrizd a TLS-t, valamint az SPF, DKIM és DMARC eredményt.
4. Tesztelj hibás/lejárt credentialt: az alkalmazás ne fedje fel azt válaszban vagy logban, a booking tranzakció pedig ne vesszen el SMTP-hiba miatt.
5. Rögzítsd a provider message ID helyett csak a smoke eredményét és időpontját, személyes adat és secret nélkül.

## Release elfogadás és rollback

Release előtt: tiszta commit/tag, review, teljes tesztcsomag, dependency audit, migrációlista, backup igazolás, jogi release-kapuk, HTTPS/SMTP smoke és cPanel document-root ellenőrzés szükséges.

### Sprint 10 migráció és cron

Backup után a normál `php bin/migrate.php` belépési ponttal futtasd a következő hiányzó migrációkat (a rendszer sorrendben alkalmazza őket), beleértve a 021 legacy import provenance sémát. A 020 csak a meglévő `nightly_price` értékből javítja a hiányzó legacy `amount` mezőt; nem seedel production árat. A deploy után az egyetlen `/admin/pricing` oldalon kizárólag tulajdonos által jóváhagyott felnőttárakat és teljes gyermeklefedettséget ments.

Az iCal workert először kézzel, kétszer egymás után futtasd ugyanabban a védett CLI environmentben. Ellenőrizd a JSON outputot, exit kódot, duplikációmentességet és admin sync metrikákat; csak ezután vedd fel a [monitoring runbook](14_MONITORING_AND_CRON.md) helyőrzős 15 perces cronját. A PHP- és release-útvonalat a hosting adja.

Rollback előtt tiltsd le az új cront, az új kóddal állítsd a pricing módot legacyra, majd válts az előző release-re. A forward-only 017–020 változásokat és v3 snapshotokat ne töröld; maintenance + restore vagy forward-fix szükséges, ha az előző kód mégsem kompatibilis.

Alkalmazáskód rollbackhez állítsd vissza a document rootot/symlinket az előző ellenőrzött release-re, majd ismételd meg a health és HTTPS smoke-ot. Az adatbázismigrációk forward-only-k: SQL-t kézzel visszavonni tilos. Inkompatibilis migráció esetén állítsd maintenance módba a forgalmat, őrizd meg a hibás állapot bizonyítékát, és kizárólag jóváhagyott restore/forward-fix eljárást használj. A rollback után az új release-ből elindult cronokat kapcsold ki, ellenőrizd az outbox/idempotencia állapotot, és dokumentáld az incidenst.

## Nyitott release-kapuk

- cPanel account, domain, PHP handler és deployment path;
- jóváhagyott jogi tartalom/verzió;
- session-, HSTS- és rate-limit értékek;
- SMTP provider, DNS rekordok és credentialek;
- kontrollált proxy pontos IP-je, ha egyáltalán van;
- backup RPO/RTO/retenció és monitoring címzettek.

## Determinisztikus Windows PowerShell release-csomag

**Állapot: IMPLEMENTED.** A tárhely deployment könyvtára nem Git checkout, ezért az ellenőrzött commitból készíts csomagot, ne kézi fájlmásolással állíts össze release-t:

```powershell
.\tools\New-ReleasePackage.ps1 -Commit (git rev-parse HEAD) -OutputDirectory .\release-packages
```

A script a megadott commitból POSIX jogosultságokat megőrző `.tar.gz` és SHA-256 manifestet készít. Alapértelmezésben a `tests/` könyvtár kimarad; a runtime fájlok és a `database/migrations/` benne maradnak. A `.git`, a lokális `.env` és `.env.*` fájlok nem kerülnek a csomagba, és a script megtagadja az olyan commit csomagolását, amely tracked environment fájlt tartalmaz. Tesztekkel együtt csak külön ellenőrzési artefaktumhoz használható:

```powershell
.\tools\New-ReleasePackage.ps1 -Commit (git rev-parse HEAD) -IncludeTests
```

Ellenőrizd a manifestet és a csomagot a `tools/Verify-ReleasePackage.ps1` scripttel, majd Linuxon bontsd ki:

```powershell
.\tools\Verify-ReleasePackage.ps1 -ArchivePath .\release-packages\foglalo-<sha>.tar.gz
mkdir -p <release>
tar -xzf foglalo-<sha>.tar.gz -C <release>
```

A `.tar.gz` megőrzi a Gitből származó traversable könyvtárjogokat; normál telepítéshez nem kell rekurzív `chmod`. A production `.env`-et külön, meglévő secretből hozd létre; a csomag soha nem írhatja felül. A `public/` maradjon a DocumentRoot, a `vendor/` pedig Composerrel vagy ugyanazon platformon előállított, ellenőrzött artefaktummal kerüljön a release-be.

## Pre-import release preflight és sorrend

Az új release-ben a Composer telepítése után futtasd a közös `.env` bootstrapet használó előellenőrzést; shell `source .env`, `set -a` vagy kézi `export DB_*` nem szükséges és nem támogatott:

```powershell
/opt/alt/php83/usr/bin/php bin/preflight.php
/opt/alt/php83/usr/bin/php bin/db-check.php
/opt/alt/php83/usr/bin/php bin/migrate.php
```

A végső sorrend: pontos commitból artifact készítése; feltöltés és `tar -xzf` kibontás új release könyvtárba; production `.env` létrehozása secretből; Composer install, validate és audit; `bin/preflight.php`; csak új migrációt tartalmazó release esetén migráció; WePanel DocumentRoot váltás; `/health`, `/api/availability` és `/static/...` smoke; admin login és 2FA; `/admin/bookings/import` preview.

Az admin felhasználók az `/admin/users` oldalon kezelhetők. Minden aktív rekord azonos admin jogosultságú; az új felhasználó legalább 12 karakteres jelszót kap, majd a normál e-mailes 2FA-belépést használja. Inaktiválás visszavonja az érintett munkameneteket és függő 2FA-kódokat; az utolsó aktív admin és a saját aktuális fiók nem inaktiválható.

A személyalapú árképzésben az aktív, illeszkedő felnőtt tartózkodáshossz-sáv elsőbbsége: `stay-length band > adult weekday/weekend`. Ha nincs illeszkedő sáv, a meglévő péntek/szombat hétvégi és egyéb hétköznapi felnőttár marad érvényben; a gyermek életkor- és hétvégi árazása ettől független.

A valós 110 soros import előtt kötelező friss adatbázis-backupot készíteni:

```powershell
$env:BACKUP_DIRECTORY = 'D:\secure-backups\foglalo'
/opt/alt/php83/usr/bin/php bin/backup-database.php
Get-ChildItem 'D:\secure-backups\foglalo' | Sort-Object LastWriteTime -Descending | Select-Object -First 2
```

Rögzítsd a backup időpontját és SQL/checksum útvonalát, ellenőrizd hogy az SQL fájl nem üres és a `.sha256` egyezik, majd csak owner-jóváhagyás után futtasd a CSV importot. A backupot tartsd meg a booking-count és availability ellenőrzésének végéig. A `bin/restore-database.php` kizárólag külön jóváhagyott disposable adatbázison validálható; staging/production restore-t Codex nem futtat.
