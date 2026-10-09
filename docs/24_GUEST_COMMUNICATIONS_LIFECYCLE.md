# Vendégkommunikáció és foglalási életciklus

**Állapot: IMPLEMENTED a `release/rc2` ágon, production aktiválás nélkül.**

Minden vendégnek címzett booking levél központilag a `MAIL_FROM_NAME` + `MAIL_FROM_EMAIL` feladói identitást használja, és a `GUEST_REPLY_TO_NAME` + `GUEST_REPLY_TO_EMAIL` címre irányítja a válaszokat. Ez a kezdeti foglalási értesítésre, díjbekérőre, előleg-emlékeztetőre, confirmed/rejected/cancelled státuszlevélre, érkezési tájékoztatóra és értékeléskérőre egyaránt érvényes. Az admin új-foglalás értesítő és a 2FA levél ugyanazt a feladói nevet használja, de guest Reply-To-t nem kap. A production rollout értéke `GUEST_REPLY_TO_EMAIL=info@abata.hu` és `GUEST_REPLY_TO_NAME=A Bata`; a tényleges `MAIL_FROM_EMAIL` és az SMTP envelope sender nem változott.

## Díjbekérő és előleg

Az előleg alapja kizárólag a foglalás immutable pricing snapshotjának `accommodation_fee` mezője. Az IFA (`taxes`) teljes egészében helyben fizetendő, ezért nem része az előlegnek. A rendszer nem áraz újra és nem használja előlegalapként a `bookings.total_amount` mezőt. Hiányos vagy legacy, használhatatlan snapshot esetén az új díjbekérő fail-closed.

Az új `booking_payment_request` payload `template_version=2`, és snapshotolja az `accommodation_fee`, `taxes`, `total`, `currency`, előleg-, kedvezményezett- és bankadatokat, valamint az `AB-%06d` alakú, booking ID-ból determinisztikusan képzett `payment_reference` értéket. Ez csak utalási közlemény; nem URL-, lookup- vagy hitelesítési kulcs. A korábbi v1 payload olvasható marad, failed retry során nem változik sem az összeg, sem a korábbi booking referencia alapú közlemény.

Kötelező konfiguráció: `PAYMENT_REQUEST_BENEFICIARY`, `PAYMENT_REQUEST_BANK_NAME`, `PAYMENT_REQUEST_BANK_ACCOUNT`, `PAYMENT_REQUEST_SWIFT_BIC`, valamint az 1–100 közötti `PAYMENT_REQUEST_ADVANCE_PERCENT` (alapérték 50). Production `.env` módosítása nem része a fejlesztési változásnak.

Jóváhagyott rollout-értékek: bank `Erste Bank`, kedvezményezett `Petróczki-Oravecz Anikó`, bankszámla `HU08 1160 0006 5000 0006 1212 6887`, SWIFT/BIC `GIBAHUHB`. Ezeket a deployment environment adja át; a rendererben nincsenek hard-code-olva.

## Kézi admin kommunikáció

- `POST /admin/bookings/{reference}/payment-reminder`: csak pending booking és már `sent` díjbekérő esetén. Az eredeti payment payload előlegét, közleményét és banki snapshotját használja; nincs újraárazás és nincs automatikus másnapi törlés.
- `POST /admin/bookings/{reference}/arrival-information`: kizárólag confirmed bookinghoz, mindig kézi admin művelet.

Mindkettő auth-, CSRF-, form-body- és admin rate-limit védelemmel fut. Foglalásonként és message type-onként egy sikeres outbox rekord készül; `failed` újrapróbálható, `sent` nem duplikálható.

Az érkezési levél négy repositoryban tárolt JPEG képet ágyaz be `multipart/related` + `multipart/alternative` MIME szerkezettel és `cid:` hivatkozással. Mind a négy HTML-kép explicit `width="600"` attribútumot és `width:600px;max-width:100%;height:auto` inline stílust kap: desktopon egységesen 600 px szélesek, keskeny kliensben aránytartóan zsugorodnak, crop nélkül. A fájlútvonal nem felhasználói input. Csak biztonságos ASCII CID/fájlnév és `image/jpeg` engedélyezett. A kulcsszéfhez kizárólag a redaktált `resources/email/arrival/bata3-safe.jpg` kerülhet release-be; az eredeti `bata3.jpg` tiltott. Hiányzó vagy hibás required kép leállítja a küldést. A plain-text változat képek nélkül is érthető.

## Automatikus review és completed

`BOOKING_LIFECYCLE_ENABLED=false` mellett a `composer booking:lifecycle` sikeres, géppel olvasható no-op választ ad még DB- vagy SMTP-inicializálás előtt. Bekapcsoláskor a Budapest szerint értelmezett, valid `BOOKING_LIFECYCLE_START_DATE=YYYY-MM-DD` kötelező. A start dátumnál korábbi foglalást a worker sem review, sem completion célból nem érinti.

Bekapcsolva az értékeléskérő catch-up tartománya `departure_date >= start_date AND departure_date <= today`, kizárólag `confirmed` vagy `completed` státuszhoz. A `sent` outbox nem küldhető újra; a `pending` és `failed` rekord későbbi futásban is claimelhető, ezért a departure napján elbukott review a booking független completion átmenete után is újrapróbálható. A completion tartománya `departure_date >= start_date AND departure_date < today`, kizárólag `confirmed` státuszhoz. Az outbox- és booking-sorzárak miatt az ismételt futás idempotens. A completed státusz magyar címkéje `Teljesült`, nem blokkol kapacitást és nem kerül iCal exportba. A státusztörténet és `booking.completed_auto` audit ugyanabban a tranzakcióban, sikeres átmenetenként pontosan egyszer készül.

**CRON NOT ENABLED.** A worker elkészült, de production aktiválási dátuma és ütemezése külön cutover döntés és kézi smoke után engedélyezhető. Előbb a start dátumot kell jóváhagyni, majd `BOOKING_LIFECYCLE_ENABLED=true` mellett kézi, ellenőrzött futást végezni; csak ezután vehető fel cron. Példa, amelyet csak az ellenőrzött cPanel PHP/útvonalakkal szabad véglegesíteni:

```text
15 1 * * * cd /home/<account>/apps/foglalo/current && /usr/local/bin/php bin/booking-lifecycle-worker.php >> /home/<account>/logs/foglalo/booking-lifecycle.log 2>&1
```

## Admin, nyelvek, audit és séma

A booking részlet immutable bontásban mutatja a szállásdíjat, a helyben fizetendő IFA-t, az előleget és a rövid közleményt. A shared admin fejléc jobb oldalán POST + CSRF alapú, billentyűzettel használható kijelentkezés ikon található; GET logout nincs.

A lemondás `admin_note` mezője vendégnek küldött indoklás, ezért a cancel űrlap ezt explicit jelzi; a confirm, reject és invalidate megjegyzések admin megjegyzésként maradnak címkézve. A generikus státuszlevél-retry csak az aktuális workflow-státuszhoz tartozó failed `booking_confirmed`, `booking_rejected` vagy `booking_cancelled` rekordnál jelenik meg, más failed kommunikáció nem aktiválja.

A `completed` foglalás kizárólag a read-only admin havi történeti nézetben marad látható érkezés/foglalt/távozás bontásban. Ez nem változtatja meg a publikus kapacitás- vagy iCal-szerződést.

Az automatikus levelezés magyar. Nyelvi mező hiányában névből, e-mailből, telefonszámból, megjegyzésből vagy böngészőből nem történik nyelvkövetkeztetés; automatikus angol routing nincs.

Új message type-ok: `booking_payment_reminder`, `booking_arrival_information`, `booking_review_request`. Az audit sent/failed/retry állapotot rögzít PII, teljes body, SMTP credential és kép nélkül. SMTP-elfogadás után az outbox `sent` állapota megelőzi az auditot, ezért későbbi audithiba nem teszi újraküldhetővé a levelet.

Új adatbázis-migráció nem szükséges: a meglévő `email_outbox` booking/message-type/recipient egyedisége és a szöveges booking státuszmező támogatja a változást.
