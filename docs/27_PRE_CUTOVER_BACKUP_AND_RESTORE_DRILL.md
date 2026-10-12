# Pre-cutover backup, állapot-snapshot és restore drill

**Állapot:** IMPLEMENTED eszközök; production snapshot és production-backup restore drill még nincs végrehajtva

**Időzóna:** Europe/Budapest

**Utolsó felülvizsgálat:** 2026-10-12, Phase 7.6C

Ez a runbook három külön bizonyítékot kezel. Egy sikeres adatbázis-checksum csak a dump sértetlenségét igazolja. Egy teljes state snapshot a dump mellett a release-t, a production environment fájlt és a futásállapotot is összefogja. A restore drill pedig azt igazolja, hogy egy checksum-valid dump külön céladatbázisba ténylegesen visszatölthető. Ezek egyike sem helyettesíti a másikat.

## 1. Adatbázis-backup

A részletes backup/restore szerződés a [13 – Backup és restore](13_BACKUP_AND_RESTORE.md) dokumentumban található. A `bin/backup-database.php`:

- a credentialt environmentből, ideiglenes `0600` option fájlon keresztül adja a kliensnek;
- a repositoryn és a publikus webrooton kívüli célkönyvtárat követel;
- ideiglenes dumpból és checksumból atomikusan véglegesít;
- siker esetén egymáshoz tartozó `.sql` és `.sql.sha256` fájlt készít;
- nem töröl korábbi mentést.

PowerShell-kompatibilis helyi példa:

```powershell
docker compose exec -T app sh -lc 'install -d -m 700 /tmp/booking-backups'
docker compose exec -T `
  -e BACKUP_DIRECTORY=/tmp/booking-backups `
  app php bin/backup-database.php
docker compose exec -T app sh -lc 'cd /tmp/booking-backups && sha256sum -c --status *.sha256'
```

A modern MariaDB kliens önaláírt helyi Compose tanúsítványnál elutasíthatja a kapcsolatot. Kizárólag ilyen elkülönített helyi környezetben adható át `MYSQL_CLIENT_VERIFY_SERVER_CERT=false`. Alapértelmezetten nincs override, ezért a kliens biztonságos alapbeállítása érvényesül; productionben ezt tilos ellenőrzött CA/hostname-validáció nélkül kikapcsolni.

## 2. Atomi állapot-snapshot

A követhető belépési pont:

```text
tools/New-StateSnapshot.ps1
```

Kötelező paraméterei:

- `-SshTarget`
- `-ReleaseRoot`
- `-SnapshotRoot`
- `-DatabaseBackupFile`
- `-DatabaseChecksumFile`

Opcionálisan `-SnapshotId`, `-SshPort` és `-IdentityFile` adható meg. A toolban nincs production host, account, útvonal, token, passphrase vagy credential alapérték. Az SSH kapcsolat batch módban fut, jelszavas fallback nélkül.

Példa kizárólag operátori, jóváhagyott futtatásra, helykitöltőkkel:

```powershell
.\tools\New-StateSnapshot.ps1 `
  -SshTarget 'CPANEL_USER@HOST' `
  -ReleaseRoot '/ABSOLUTE/RELEASE_ROOT' `
  -SnapshotRoot '/ABSOLUTE/PRIVATE_SNAPSHOT_ROOT' `
  -DatabaseBackupFile '/ABSOLUTE/PRIVATE_BACKUP.sql' `
  -DatabaseChecksumFile '/ABSOLUTE/PRIVATE_BACKUP.sql.sha256' `
  -IdentityFile 'C:\ABSOLUTE\PATH\TO\SSH_KEY'
```

### Atomi véglegesítés

1. Az eszköz minden inputot és a DB checksumot még staging létrehozása előtt ellenőriz.
2. A privát snapshot root alatt `.<snapshot-id>.tmp` staging könyvtárat készít `0700` jogosultsággal.
3. Minden artifactot stagingbe gyűjt, a fájlokat `0600` jogosultságúra állítja.
4. Létrehozza és ellenőrzi a `SNAPSHOT.sha256` manifestet, valamint az exact artifact listát.
5. Csak teljes siker után nevezi át a staging könyvtárat a végleges snapshotnévre.
6. Bármely hiba esetén az EXIT trap eltávolítja a staginget, a végleges könyvtár pedig nem jön létre.

Meglévő staging vagy végleges név nem írható felül. A snapshot root nem lehet a release-en belül. A release archive kihagyja az `.env`, `.git`, log, backup, cache és session útvonalakat; a production environment külön védett artifactként kerül a snapshotba.

### Kötelező, pontos artifact-készlet

```text
database.sql
database.sql.sha256
production.env
release.tar.gz
STATE.txt
SNAPSHOT.sha256
```

Ellenőrzés PowerShellből, csak a privát szerveren, secret vagy fájltartalom kiírása nélkül:

```powershell
ssh -o BatchMode=yes 'CPANEL_USER@HOST' `
  'cd /ABSOLUTE/PRIVATE_SNAPSHOT_ROOT/SNAPSHOT_ID && test "$(find . -mindepth 1 -maxdepth 1 -type f | wc -l)" -eq 6 && sha256sum -c --status SNAPSHOT.sha256'
```

### A 2026-10-12-i üres snapshot megállapítása

A korábbi, untracked egyszeri script a végleges snapshot könyvtárat a DB backup futtatása előtt létrehozta, és nem tartalmazott hibakori cleanup/finalizálási protokollt. Ez **bizonyított tervezési hibamód**: bármely későbbi hiba vagy megszakítás késznek látszó, akár üres végleges könyvtárat hagyhatott. A meglévő DB backup checksum-valid, miközben a hozzá tartozó snapshot könyvtár üres. A pontos megszakítási esemény log hiányában nem bizonyítható; valószínű időablaka a backup sikeres elkészülte és az első artifact másolása közötti szakasz.

## 3. Read-only migrációállapot

A parancs a normál application environmentet tölti be, a repository `.sql` fájlneveit összeveti a már létező `migrations` tábla verzióival, és JSON-t ír:

```powershell
docker compose exec -T app php bin/migration-status.php
# vagy
docker compose exec -T app composer migration:status
```

Sikeres példa:

```json
{
  "status": "ok",
  "expected_count": 28,
  "applied_count": 28,
  "pending": [],
  "unknown_applied": []
}
```

A parancs kizárólag `information_schema.tables` és `migrations` SELECT lekérdezést végez. Nem hívja a `Migrator` osztályt, nem hajt végre migration SQL-t, és nem hoz létre vagy módosít sémát/adatot. A következők nem nulla exit kódot adnak:

- hiányzó `migrations` tábla: `status=missing_migrations_table`;
- repositoryban függő fájl: `status=drift`, a fájlnév a `pending` listában;
- a kód számára ismeretlen DB-verzió: `status=drift`, a fájlnév az `unknown_applied` listában;
- konfigurációs vagy kapcsolati hiba: generikus, credentialmentes `status=error`.

Egyedi könyvtár `MIGRATION_DIRECTORY` environment változóval ellenőrizhető. A pre-deploy gate csak `status=ok` és `0` exit esetén PASS.

### Phase 7.5 production read-only bizonyíték

A dokumentált `21d15ea` release könyvtárban, annak normál environment bootstrapjével végzett server-side összevetés 28 repository migration fájlt és 28 alkalmazott verziót talált. A pending és `unknown_applied` lista üres, ezért a meglévő production migrációkészlet állapota PASS. A `77db2c1..93bd17a` Phase 7.4 diff nem módosít és nem ad hozzá migrációs fájlt.

Az ellenőrzés nem töltött fel eszközt, nem deployolt, nem hívta a `Migrator` osztályt vagy a `bin/migrate.php` parancsot, és nem hajtott végre DDL/DML műveletet. Az audit utáni három anonim production GET smoke PASS. Az aktív WePanel Document Root ettől még nem bizonyított; owner-megerősítés szükséges.

### Phase 7.6A read-only snapshot preflight

A productionön írási próba nélkül ellenőrzött útvonalak:

| Útvonal | Tulajdonos/csoport | Mód | Read-only eredmény |
|---|---|---:|---|
| `/home/abatahu/foglalas.abata.hu/release/21d15ea` | `abatahu:abatahu` | `0775` | létezik; writable inferred: yes, kizárólag mode/ownership alapján |
| `/home/abatahu/foglalas.abata.hu/release/21d15ea/public` | `abatahu:abatahu` | `0775` | létezik; writable inferred: yes; aktív WePanel Document Rootként nem bizonyított |
| `/home/abatahu/backups/foglalas` | `abatahu:abatahu` | `0700` | létezik, privát; writable inferred: yes |
| `/home/abatahu/backups/foglalas/state-snapshots` | `abatahu:abatahu` | `0775` | létezik; writable inferred: yes; a snapshot tool jóváhagyott futáskor `0700` módra szűkíti |

Nem található olvasható symlink vagy hosting metadata, amely a `21d15ea/public` aktív Document Root státuszát igazolja. Az aktív release útvonalat az ownernek a WePanelben kell megerősítenie, és addig új state snapshot nem hagyható jóvá.

Kapacitásbecslés:

```text
release nyers méret (konzervatív archive estimate): 2697950 byte
DB backup:                                      345603 byte
environment/metaadat allowance:                1048576 byte
becsült payload:                                4092129 byte
minimum 2x biztonsági igény:                    8184258 byte
elérhető kapacitás:                        494574338048 byte
disk-space preflight: PASS
```

A `abatahu_abookbata_20261012_095730_f376e475.sql` backup `345603` byte, timestampje `2026-10-12 09:57:30 +02:00`, a hozzá tartozó checksum fájl `0600` módú, és az újraellenőrzött SHA-256 egyezik. Ez korábbi evidence; **friss pre-cutover DB backup továbbra is kötelező**.

A snapshot rootban négy végleges nevű, de üres könyvtár található:

```text
20261012_095613_21d15ea
20261012_095650_21d15ea
20261012_095706_21d15ea
20261012_095730_21d15ea
```

Egy új, időbélyegzett candidate név az ellenőrzéskor nem ütközött. Könyvtár nem jött létre, a meglévőket tilos újrahasználni vagy automatikusan törölni.

A `tools/New-StateSnapshot.ps1` továbbra is explicit `SshTarget`, `ReleaseRoot`, `SnapshotRoot`, `DatabaseBackupFile` és `DatabaseChecksumFile` értéket kér; opcionális a `SnapshotId`, `SshPort` és `IdentityFile`. A wrapper vagy a `New-StateSnapshot.Core.sh` nem tartalmaz production hostot vagy secretet. A core a távoli `realpath`, `sha256sum`, `tar`, `stat`, `find`, `cp`, `chmod`, `mv` és `wc` binárisokat igényli. A jövőbeni, külön engedélyezendő parancs alakja:

```powershell
.\tools\New-StateSnapshot.ps1 `
  -SshTarget '<confirmed target>' `
  -ReleaseRoot '<CONFIRMED ACTIVE RELEASE ROOT>' `
  -SnapshotRoot '<private snapshot root>' `
  -DatabaseBackupFile '<fresh backup path>' `
  -DatabaseChecksumFile '<checksum path>'
```

Az alkalmazás DB credentialjének read-only grant vizsgálata nem mutatott globális `CREATE DATABASE` jogosultságot, és a látható sémák között nem volt külön restore/staging/sandbox/test névmintájú cél. Ez nem jogosít fel meglévő adatbázis használatára: az izolált restore drillhez owner/WePanel által provisionált eldobható target és külön least-privilege credential szükséges. A production adatbázis soha nem lehet restore-drill cél.

A migrációs quick recheck változatlanul `28/28`, pending és unknown lista üres; az ezt követő három anonim HTTP GET smoke PASS. Production fájl, adatbázis, cron, Document Root vagy alkalmazásadat nem változott.

### Phase 7.6B safe failure és Phase 7.6C hosting-kompatibilitási hotfix

Az owner által engedélyezett első production kísérletben a friss `abatahu_abookbata_20261012_153135_e9ede119.sql` backup létrejött `0600` móddal, és checksumja PASS. Az atomi snapshot az exact artifact-lista ellenőrzésénél biztonságosan leállt, mert a shared-hosting Bash környezet nem biztosította a process substitution által igényelt `/dev/fd/62` hátteret. Végleges snapshot vagy rejtett staging könyvtár nem maradt; a négy korábbi üres könyvtár érintetlen, a friss backup checksum-valid maradt.

A Phase 7.6C hotfix eltávolítja a production core-ból a process substitution és `/dev/fd` függést. A hat kötelező artifact továbbra is egyenként bizonyítottan reguláris, nem üres, `0600` módú fájl. Ezután a hordozható `find ... -print | wc -l` ellenőrzés minden top-level bejegyzést — fájlt, könyvtárat, symlinket vagy más típust — számol, és pontosan hatot követel. Így extra bejegyzés mellett nincs véglegesítés, az EXIT trap pedig eltávolítja a staginget. A hosting read-only inventory szerint a szükséges `wc` és minden további production-parancs elérhető.

Az extra reguláris fájl, extra könyvtár és extra symlink tesztszimuláció kizárólag `/tmp` snapshot rootnál aktiválható. Az atomi rename, a DB- és manifest-checksum, a `0700`/`0600` jogosultságok, a meglévő staging/final elutasítása és a hibakori cleanup szerződése változatlan.

**Production state snapshot: BLOCKED pending hotfix review + reauthorization.** A Phase 7.6B engedély az első kísérlettel elfogyott; a Phase 7.6C nem futtatott második production snapshotot. Egy későbbi retryhoz explicit owner jóváhagyás és külön döntés kell arról, hogy a `15:31:35 +02:00` backup a közben történt production változások alapján még újrahasználható-e, vagy új friss backup szükséges.

## 4. Biztonságos restore-rehearsal

Restore-próba kizárólag elkülönített, eldobható helyi vagy staging adatbázison végezhető. Production adat vagy production dump helyi gépre másolása tilos. A minimum ellenőrzési sorrend:

1. hozz létre egy üres, eldobható adatbázist és benne kizárólag szintetikus fixture-t;
2. készíts backupot, és őrizd meg mellette az automatikusan létrehozott `.sha256` fájlt;
3. igazold, hogy hiányzó checksum esetén a restore elutasít;
4. igazold, hogy hibás checksum esetén a restore elutasít;
5. igazold, hogy hibás `RESTORE_CONFIRM` esetén a restore elutasít;
6. módosítsd a szintetikus fixture-t, majd helyes checksum és `RESTORE:<DB_DATABASE>` értékkel töltsd vissza;
7. read-only SELECT-tel hasonlítsd össze a visszaállított fixture-t;
8. dobd el a tesztadatbázist és töröld az ideiglenes backupot.

Phase 7.4 helyi eredmény:

```text
restore mechanism rehearsal: PASS
production backup restore drill: NOT YET PERFORMED
```

A próba eldobható `phase74_restore` adatbázist és egyetlen szintetikus sort használt. A hiányzó checksum, hibás checksum és hibás megerősítés elutasítása PASS; a helyes restore utáni fixture-egyezés PASS; az adatbázis és az ideiglenes fájlok takarítása PASS. Ez nem bizonyítja a jelenlegi production dump visszaállíthatóságát és nem RTO-mérés.

## 5. Kötelező pre-cutover sorrend

1. Owner/üzemeltető erősítse meg az aktív Document Rootot és release-t.
2. Futtasd a read-only `migration-status.php` parancsot; eltérésnél állj meg, migrációt automatikusan ne futtass.
3. Készíts friss, privát DB backupot, majd ellenőrizd a checksumot.
4. Külön jóváhagyással végezz production-backup restore drillt izolált staging célba; productionbe ne restore-olj.
5. A validált DB backupból és a kiválasztott release-ből készíts teljes, atomi state snapshotot.
6. Ellenőrizd a hat artifactot, a manifestet és a jogosultságokat.
7. Csak ezután folytatható a külön engedélyezett deploy/Document Root/cron/feed cutover.

Jelenleg a production state snapshot és a production-backup restore drill továbbra is **BLOCKED / NOT YET PERFORMED**. E dokumentum és az eszközök nem adnak engedélyt production snapshotra, restore-ra, deployra vagy main merge-re.
