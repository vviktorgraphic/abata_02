# Production deployment – cPanel/Apache

**Állapot:** IMPLEMENTED deployment artefaktumok és reprodukálható runbook; a szolgáltatói és tulajdonosi értékek OPEN release-kapuk.

## Biztonsági előfeltételek

A deploy csak jóváhagyott jogi tartalommal, ellenőrzött backupból visszaállási lehetőséggel, hitelesített SMTP-vel és működő HTTPS-sel végezhető el. A repository nem tartalmaz production credentialt. A `.env.production.example` kizárólag mezőleltár: minden `<...>` értéket a hosting secret store-ban kell kitölteni.

Követelmény: PHP 8.2 vagy újabb 8.x, Composer 2, MySQL 8, Apache `mod_rewrite`, valamint PHP `pdo`, `pdo_mysql`, `mbstring`, `curl` és `openssl`. Ajánlott production PHP-beállítás: `display_errors=Off`, `log_errors=On`, `expose_php=Off`, `session.use_strict_mode=1`. A szolgáltató által kezelt hibanapló és session könyvtár nem lehet weben elérhető.

## Könyvtárak és document root

Javasolt elrendezés, ahol `<account>` és `<release-id>` deployment érték:

```text
/home/<account>/apps/foglalo/releases/<release-id>/   alkalmazáskód
/home/<account>/apps/foglalo/shared/                   webrooton kívüli operations fájlok
/home/<account>/public_html -> /home/<account>/apps/foglalo/releases/<release-id>/public
```

A domain document rootja kizárólag a release `public/` könyvtára vagy pontosan arra mutató támogatott symlink lehet. A `public/` fájljait tilos önmagukban `public_html` alá másolni: a front controller a szülő release könyvtárban keresi a `vendor/`, `config/`, `src/` és `templates/` elemeket. A `src/`, `config/`, `database/`, `vendor/`, `.env`, backup és log soha nem lehet a webroot alatt. Az alkalmazás jelenleg nem igényel feltöltési vagy cache könyvtárat, ezért a release kódnak nem kell írhatónak lennie. Írási jog csak a hosting által használt, webrooton kívüli session- és logkönyvtárhoz, illetve az operations runbook által kijelölt backup célhoz kell. Ne adj rekurzívan `777` jogot.

## Első telepítés és release

Az alábbi parancsok PowerShellből, SSH-n keresztül vagy a cPanel Terminalban azonos sorrendben futtathatók; a konkrét SSH hostot és elérési utat a szolgáltató adja meg.

1. Töltsd fel az ellenőrzött commit tiszta release artefaktumát egy új, nem webes release könyvtárba. Ne tölts fel `.git`, `.env`, tesztadat vagy backup fájlt.
2. Futtasd a release gyökerében: `composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction`.
3. Ellenőrizd: `composer validate --no-check-publish` és `composer audit --no-dev`.
4. A `.env.production.example` csak mezőleltár: az alkalmazás szándékosan nem tölt be `.env` fájlt. A cPanel PHP/Apache handler számára minden változót a hosting dokumentált environment/secret mechanizmusával kell átadni; a CLI parancsokat ugyanebben a védett environmentben vagy egy webrooton kívüli, jogosultságszűkített wrapperből kell indítani. A handler és CLI környezetét secret kiírása nélkül külön ellenőrizd. Ha a hosting nem tud ugyanazokat a változókat biztonságosan átadni mindkettőnek, a deploy blokkolt; ne tedd a secretet `.htaccess` fájlba és ne feltételezz automatikus dotenv betöltést.
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

Productionben `MAIL_HOST`, `MAIL_PORT`, `MAIL_ENCRYPTION=tls|ssl`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_EMAIL` és `MAIL_FROM_NAME` kötelező; hitelesítés nélküli vagy plaintext transport fail-fast hibát ad. A portot és titkosítási módot kizárólag a szolgáltató dokumentációja alapján válaszd, credentialt ne adj parancssori argumentumban és ne naplózz.

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

Alkalmazáskód rollbackhez állítsd vissza a document rootot/symlinket az előző ellenőrzött release-re, majd ismételd meg a health és HTTPS smoke-ot. Az adatbázismigrációk forward-only-k: SQL-t kézzel visszavonni tilos. Inkompatibilis migráció esetén állítsd maintenance módba a forgalmat, őrizd meg a hibás állapot bizonyítékát, és kizárólag jóváhagyott restore/forward-fix eljárást használj. A rollback után az új release-ből elindult cronokat kapcsold ki, ellenőrizd az outbox/idempotencia állapotot, és dokumentáld az incidenst.

## Nyitott release-kapuk

- cPanel account, domain, PHP handler és deployment path;
- jóváhagyott jogi tartalom/verzió;
- session-, HSTS- és rate-limit értékek;
- SMTP provider, DNS rekordok és credentialek;
- kontrollált proxy pontos IP-je, ha egyáltalán van;
- backup RPO/RTO/retenció és monitoring címzettek.
