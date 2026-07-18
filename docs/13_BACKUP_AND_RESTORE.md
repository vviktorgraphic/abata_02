# Adatbázis-backup és restore runbook

**Állapot:** IMPLEMENTED eszközök és production üzemeltetési eljárás
**Időzóna:** Europe/Budapest
**Cél:** legfeljebb 4 órás RPO és 5 perces RTO mérhető staging ellenőrzése

## Biztonsági modell

- A mentés célkönyvtára kötelezően a repositoryn és a `public/` webrooton kívül van.
- A DB-jelszó környezeti változóból érkezik, és csak egy futásidejű, `0600` MySQL option fájlba kerül. Nem parancssori argumentum és nem naplózódik.
- A dump ideiglenes, `0600` fájlba készül, majd sikeres `mysqldump` és SHA-256 képzés után atomikus átnevezéssel válik véglegessé.
- Sikertelen futás nem hagy késznek látszó mentést; az ideiglenes fájlokat a script eltávolítja és nem nulla exit kóddal tér vissza.
- A restore kizárólag a hozzá tartozó `.sha256` fájl sikeres ellenőrzése és az adatbázisnévhez kötött explicit megerősítés után indul.
- A dump nem tartalmaz adatbázis-létrehozó vagy `USE` utasítást; a restore kliens mindig az explicit megerősített `DB_DATABASE` célba tölt.
- A mentés `--no-tablespaces` kapcsolót használ, és nem kér routine/event mentést, így nem igényel ezekhez felesleges globális jogosultságot. A backup usernek a teljes alkalmazássémára konzisztens olvasási és trigger-hozzáférés kell; a konkrét grantet a hosting MySQL-verziójához kell igazítani, és az alkalmazás író userénél szélesebb jogot csak külön backup credential kaphat.
- A repository nem határoz meg retention időt. Automatikus törlést csak külön tulajdonosi döntés, jogi egyeztetés és visszaállítási teszt után szabad bevezetni.

Az SQL dump személyes adatot tartalmazhat. A backup könyvtár ne legyen weben elérhető, csak a backupot végző rendszerfelhasználó férjen hozzá, és a hosting/storage oldali titkosítást külön engedélyezni kell.

## cPanel előfeltételek

- PHP CLI 8.2+, engedélyezett `proc_open`;
- elérhető, MySQL szerverrel kompatibilis `mysqldump` és `mysql` kliens;
- a projekt gyökerén kívüli, nem publikus backup könyvtár;
- az alkalmazás `.env`/hosting secret store DB-változói;
- legalább a legnagyobb várható dump kétszeresének megfelelő szabad hely az atomikus temp→final lépéshez.

A binárisok nem szabványos helye a `MYSQLDUMP_BINARY`, illetve `MYSQL_BINARY` változóval adható meg. A változó értékét csak megbízható deployment konfiguráció kezelheti.

## Backup futtatása

Példa cPanel Terminalban (az értékek helykitöltők):

```sh
export BACKUP_DIRECTORY=/home/CPANEL_USER/private-backups
export DB_HOST=localhost
export DB_PORT=3306
export DB_DATABASE=APPLICATION_DATABASE
export DB_USERNAME=APPLICATION_DATABASE_USER
export DB_PASSWORD='READ_FROM_CPANEL_SECRET_CONFIGURATION'
php /home/CPANEL_USER/application/bin/backup-database.php
```

A jelszót ne írd shell historyba: a production futás ugyanabból a védett environment/secret betöltésből kapja meg, mint az alkalmazás. Sikeres futás exit kódja `0`, és létrejön:

```text
APPLICATION_DATABASE_YYYYMMDD_HHMMSS_RANDOM.sql
APPLICATION_DATABASE_YYYYMMDD_HHMMSS_RANDOM.sql.sha256
```

A timestamp Budapest-idő szerinti. Az ütemező csak a `0` exit kódot tekintheti sikernek, és nem nulla kódnál riasztást kell küldenie. A script semmilyen régi mentést nem töröl.

PowerShell-alapú helyi ellenőrzés Dockerben:

```powershell
$env:BACKUP_DIRECTORY = "/tmp/booking-backups"
docker compose exec app mkdir -p /tmp/booking-backups
docker compose exec `
  -e BACKUP_DIRECTORY `
  app composer backup:database
docker compose exec app sh -lc 'ls -l /tmp/booking-backups && sha256sum -c /tmp/booking-backups/*.sha256'
```

Ez csak lokális smoke. Production backupot soha ne tarts konténer ephemeral fájlrendszerében.

## Restore – csak stagingen próbáld először

> **VESZÉLY:** A restore a céladatbázis tartalmát módosítja. Production futtatás előtt legyen jóváhagyott karbantartási ablak, friss visszaállítási pont, helyes céladatbázis és két személyes ellenőrzés. A scriptet cronból tilos futtatni.

1. Hozz létre külön, üres staging adatbázist, és adj a staging felhasználónak csak azon jogosultságot.
2. Másold oda együtt a `.sql` és `.sha256` fájlt.
3. Állítsd a `DB_*` változókat a staging célra.
4. Add meg a backup abszolút útját és a pontos, céladatbázishoz kötött megerősítést.
5. Futtasd a restore-t, majd migrációs, adatkonzisztencia- és alkalmazás-smoke ellenőrzést.

```sh
export RESTORE_BACKUP_FILE=/home/CPANEL_USER/private-backups/APPLICATION_DATABASE_YYYYMMDD_HHMMSS_RANDOM.sql
export RESTORE_CONFIRM=RESTORE:STAGING_DATABASE
php /home/CPANEL_USER/application/bin/restore-database.php
php /home/CPANEL_USER/application/bin/db-check.php
php /home/CPANEL_USER/application/bin/migrate.php
```

A `RESTORE_CONFIRM` eltérő adatbázisnévnél az eszköz a MySQL kliens elindítása előtt leáll. Hibás vagy hiányzó checksum esetén szintén nincs restore. A mentés nem használ `--databases` módot, ezért nem írja felül a céladatbázis kiválasztását `CREATE DATABASE` vagy `USE` utasítással; a MySQL kliens az ellenőrzött `DB_DATABASE` értéket kapja explicit célként.

## RPO 4 óra mérése

Az RPO cél azt jelenti, hogy a legutolsó bizonyítottan használható mentés legfeljebb négy órával maradhat el a kiesés időpontjától.

1. A hosting scheduler gyakoriságát úgy kell beállítani, hogy a legrosszabb esetben is teljesüljön a 4 óra; konkrét cron gyakoriság tulajdonosi/üzemeltetési döntés.
2. Rögzítsd minden futás start/end idejét, exit kódját, fájlméretét és checksum-ellenőrzését, de secretet vagy dump tartalmat ne.
3. Mérd: `vizsgálat ideje - legutolsó sikeres, checksum-valid backup befejezési ideje`.
4. Négy órát meghaladó kor vagy két egymást követő hiba legyen riasztási esemény; a végleges riasztási küszöböt az üzemeltető hagyja jóvá.

## RTO 5 perc staging próba

Az RTO-t valós méretű, anonimizált vagy megfelelően védett staging adatokkal kell mérni:

1. Indíts stoppert közvetlenül a restore parancs előtt.
2. Futtasd a checksum-valid restore-t egy elkülönített staging adatbázisba.
3. Futtasd a `db-check`, migráció, `/health`, admin login és egy read-only foglaláslista smoke-ot.
4. Állítsd meg a mérést, amikor az alkalmazás minden smoke pontja sikeres.
5. Dokumentáld a dump méretét, restore idejét, teljes RTO-t, kliens/szerver verziót és hosting erőforrást.

Az 5 perces cél csak sikeres, dokumentált staging mérés után tekinthető igazoltnak. Ha nem teljesül, növelni kell az erőforrást vagy külön jóváhagyott fizikai/snapshot mentési stratégiát kell bevezetni; az adatbázis tartalmát vagy auditadatait gyorsítás céljából elhagyni tilos.

## Incidens és rendszeres próba

- Minden restore-próba eredményét dátummal, backup-azonosítóval/checksummal és végrehajtóval auditálható üzemeltetési jegyben rögzítsd.
- A próba gyakorisága és retention továbbra is **DECISION REQUIRED**; a rendszer nem talál ki értéket.
- Sérült checksum, üres dump, nem nulla exit kód vagy RPO/RTO-cél sérülése esetén a release/üzemeltetési felelős kapjon riasztást.
- Production restore után újra ellenőrizni kell a migrációkat, admin hozzáférést, booking olvasást, outboxot és iCal konfigurációt.
