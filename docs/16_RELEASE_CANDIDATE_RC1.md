# Release Candidate RC1 validáció

**Állapot:** repository-validáció IMPLEMENTED; valós staging ellenőrzések PENDING
**Dátum:** 2026-07-18
**Branch:** `release/rc1`

Ez a dokumentum végrehajtható staging checklist. A repository tesztjeinek sikere nem jelent automatikus production engedélyt. Minden környezetfüggő lépéshez dátum, végrehajtó, környezet, bizonyíték és PASS/FAIL eredmény szükséges, secret vagy személyes adat rögzítése nélkül.

## Repository és automatizált release gate

```powershell
git switch release/rc1
git status --short --branch
docker compose up -d --build
docker compose ps
docker compose exec app composer validate --strict --no-check-publish
docker compose exec app composer audit --locked
docker compose exec app composer db:check
docker compose exec app composer migrate
docker compose exec app vendor/bin/phpunit
git diff --check
```

Elfogadás: tiszta release branch, healthy DB/Mailpit, sikeres build/DB/migráció/teszt/Composer/diff. Az RC1 nem tartalmazhat új migrációt vagy üzleti logika módosítást.

### RC1 repository eredmény — 2026-07-18

- Docker build és szolgáltatások: PASS; DB és Mailpit healthy.
- Composer strict validáció: PASS.
- `composer audit --locked`: PASS, ismert security advisory nélkül.
- DB connection és migráció: PASS; 0 új migráció alkalmazva.
- PHPUnit: PASS, 374 teszt és 1213 assertion.
- Helyi HTTP smoke: PASS a `/health`, publikus oldal, availability, admin login és érvénytelen iCal token `404` útvonalakon.
- Az automatizált üzleti workflow-lefedettség komponens/HTTP/PDO szinten PASS; a teljes, valódi böngésző-, SMTP provider-, Google/Szallas.hu- és cPanel staging E2E lánc továbbra is PENDING.

## Funkcionális checklist

| Terület | Automatizált bizonyíték | Staging smoke | RC1 repository állapot |
|---|---|---|---|
| Publikus foglalás | `BookingCreateApiTest`, `TransactionalBookingRepositoryTest` | szintetikus pending request, snapshot és request e-mail | automatizált PASS; staging PENDING |
| Admin login és 2FA | `AdminControllersTest`, `AdminSessionTest`, auth persistence tesztek | login, Mail 2FA, resend-limit, logout | automatizált PASS; staging PENDING |
| Pricing | `PricingEngineTest`, pricing repository/controller tesztek | jóváhagyott staging szabállyal preview és booking-egyezés | automatizált PASS; owner adatok PENDING |
| Booking workflow | state-machine, transactional state-change és admin UI tesztek | pending → confirmed, tiltott átmenet elutasítása | automatizált PASS; staging PENDING |
| Cancellation | `CancellationPolicyTest`, policy/cancellation repository teszt | free és 50%-os snapshot ellenőrzése, terhelés nélkül | automatizált PASS; staging PENDING |
| Audit | audit sanitizer és persistence tesztek | login/booking/pricing/iCal események PII/secret nélkül | automatizált PASS; staging PENDING |
| iCal import | parser/fetcher/import/persistence/controller tesztek | Google és Szallas.hu kijelölt staging fixture kézi sync | automatizált PASS; provider smoke PENDING |
| iCal export | exporter/feed repository/endpoint tesztek | tokenes feed, confirmed/blocked igen, PII/pending nem | automatizált PASS; staging PENDING |
| Backup/restore | operations contract tesztek | checksum-valid dump → eltérő nevű staging DB restore | contract PASS; roundtrip PENDING |
| Monitoring | `HealthEndpointTest` | HTTPS `/health`, DB-kiesés 503, riasztás | automatizált PASS; monitor PENDING |
| Deployment | `DeploymentArtifactsTest` | cPanel document root, HTTPS/proxy és rollback | contract PASS; cPanel PENDING |

## Staging readiness checklist

### cPanel és environment

- [ ] Külön staging DB, SMTP credential, cookie domain, backup könyvtár és iCal token.
- [ ] PHP 8.2+, PDO MySQL, mbstring, curl, openssl, Composer 2 és Apache rewrite ellenőrizve.
- [ ] Document root pontosan a release `public/` könyvtára vagy arra mutató támogatott symlink.
- [ ] `composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction` sikeres.
- [ ] A `.env.production.example` összes helykitöltője jóváhagyott hosting environment/secret értéket kapott; a projekt nem tölt `.env` fájlt.
- [ ] Web és CLI azonos, secretet ki nem író environmentből fut; `db:check` és `migrate` sikeres.
- [ ] `APP_ENV=production`, `APP_DEBUG=false`, `APP_TIMEZONE=Europe/Budapest`.

### HTTPS, DNS és mail

- [ ] Canonical domain DNS-e a staging célra mutat; tanúsítvány hostname és expiry rendben.
- [ ] Apache TLS terminationnél a kitöltött canonical-host `.htaccess` 308 redirectet ad; proxy terminationnél a proxy végzi ezt, loop nélkül.
- [ ] Secure/HttpOnly/SameSite cookie, HSTS és biztonsági headerek ellenőrizve.
- [ ] Exact trusted proxy IP-k beállítva, vagy közvetlen Apache TLS esetén a lista üres.
- [ ] Authenticated TLS/SSL SMTP és jóváhagyott feladó működik.
- [ ] SPF egyetlen érvényes rekordként, DKIM provider selectorral, DMARC jóváhagyott policyval publikálva.
- [ ] 2FA, booking request, confirmed/rejected/cancelled HTML és text levél kézbesül; headerben TLS/SPF/DKIM/DMARC PASS.
- [ ] Hibás SMTP credential nem fed fel secretet és nem törli a booking tranzakciót.

### Cron, backup és monitoring

- [ ] iCal/outbox/cleanup cron nincs felvéve: jelenleg nincs jóváhagyott runnable worker.
- [ ] Backup könyvtár repositoryn/webrooton kívül, minimális jogosultsággal és tárhelytitkosítással rendelkezik.
- [ ] Backup checksum-valid, eltérő nevű staging DB-be restore sikeres.
- [ ] 4 órás RPO és 5 perces RTO mérése dokumentált; retention és ütemezés jóváhagyott.
- [ ] HTTPS `/health` külső monitor aktív; DB-kiesés és helyreállás riasztása kipróbált.
- [ ] 5xx, tárhely/inode, backup, SMTP, iCal és auth anomaly riasztási felelős/escalation rögzített.
- [ ] Logok webrooton kívül vannak, token/credential/PII nincs bennük, rotáció működik.

## End-to-end staging forgatókönyv

1. Hozz létre kizárólag szintetikus vendégadatú, jövőbeli publikus booking requestet; igazold a `pending` státuszt, immutable pricing/policy/privacy snapshotot és booking outboxot.
2. Ellenőrizd a request e-mailt, majd admin jelszó + e-mailes 2FA után nyisd meg a booking részletét.
3. Hasonlítsd össze az admin pricing preview és a booking snapshot eredményét. Erősítsd meg a bookingot; igazold a history, audit, outbox és confirmed levél rekordját.
4. Kérd le a tokenes iCal exportot: a confirmed booking szerepeljen PII nélkül, pending ne szerepeljen.
5. Külön, kijelölt Google és Szallas.hu fixture forrást szinkronizálj kézzel; igazold az idempotenciát, blocked periodot, sync logot és konfliktusjelzést.
6. Hozz létre két külön confirmed tesztbookingot a kötbérmentes és 50%-os időablakhoz. Mondd le őket, és igazold az immutable cancellation snapshotot, auditot és levelet; pénzügyi terhelés nem történhet.
7. Ellenőrizd az admin listát, szűrőket, részletet, pricing és calendar felületeket, CSRF/no-store védelmet és logoutot.
8. Töröld vagy anonimizáld a szintetikus staging adatot kizárólag jóváhagyott retention/cleanup eljárással; automatikus booking-idempotency cleanup nincs.

## Release döntés

- **RC1 staging:** GO, ha a repository gate PASS és nincs reviewer P0 kódhiba.
- **Production:** NO-GO, amíg bármely kötelező checkbox PENDING/FAIL, illetve a [P0 owner döntések](98_OPEN_DECISIONS.md#p0--production-előtt-kötelező) nyitottak.
- FAIL esetén rögzítsd az érintett commitot, környezetet, reprodukciót és rollbacket; secretet/PII-t ne.
