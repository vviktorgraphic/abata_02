# Admin havi foglaltsági lista

**Állapot:** IMPLEMENTED Phase 2

## HTTP- és jogosultsági szerződés

A `GET /admin/bookings/monthly` csak teljes admin sessionnel érhető el. Az opcionális `month` paraméter kizárólag `YYYY-MM`; hiányakor az aktuális `Europe/Budapest` hónap, hibánál HTTP 422 és `A megadott hónap érvénytelen.` válasz jár. Az oldal csak olvas, ezért nincs CSRF-tokenes állapotváltoztatás.

## Napi modell

A hónap minden napja pontosan egyszer szerepel magyar napnévvel. A booking intervalluma `[arrival_date, departure_date)`: érkezéskor `Érkezés`, belső éjszakán `Foglalt`, távozáskor `Távozás` látható. A távozás önmagában nem blokkol, ezért a sor `Szabad` jelölést is kap. Azonos napi távozás és új érkezés fordulónap; mindkét booking megmarad a listában.

A read-only történeti nézet explicit megjelenítési szerződése szerint a `pending`, `confirmed` és `completed` booking vesz részt. Ez szándékosan nem a `BookingStatus::BLOCKING_VALUES`: a `completed` tartózkodás érkezése, foglalt belső napjai és szabad távozási napja történetileg látható marad, miközben továbbra sem blokkol publikus kapacitást és nem kerül iCal exportba. A workflow-státusz külön, magyarul látható. A referencia a részletoldalra mutat, a név és legacy provenance látható, e-mail és telefonszám nem.

Az aktív kézi blokk `[start_date, end_date)` szerint `Blokkolt`. A `external_calendar_events.blocked_period_id` kapcsolattal rendelkező blokk `Külső naptár`, humanizált providerrel, forrásnévvel és összefoglalóval. A besorolás nem indokszöveg alapján történik; UID és raw payload nem jelenik meg. Minden dinamikus szöveg HTML-escape-et kap.

## Lekérdezési szerződés

A `PdoAdminMonthlyOccupancyRepository` kérésenként két prepared queryt futtat:

1. a read modelben látható `pending`, `confirmed` és `completed` bookingok: `arrival_date < nextMonthStart AND departure_date >= monthStart`;
2. aktív blokkok és külső metaadat: `start_date < nextMonthStart AND end_date > monthStart`.

A napi lista az `AdminMonthlyOccupancyBuilder` memóriabeli read modellje. Nincs N+1, denormalizált cache vagy új adatbázis-séma.

## Reszponzivitás és hozzáférhetőség

A teljes asztali táblázat keskeny nézeten kizárólag a `.table-scroll` régióban görgethető. A badge-ek szövegesek és törhetnek; a hétvége és a mai Budapest-nap külön háttérrel jelenik meg, utóbbi `aria-current="date"` jelölést kap. A hónapnavigáció felül és alul megismétlődik, billentyűzettel elérhető szerveroldali linkekkel.

## Hatókör

Ez a funkció nem változtat booking státuszt, inventory-lockot, publikus availabilityt, pricingot, IFA-t, payment requestet, e-mailt, iCal importot/exportot, sessiont vagy blokkolt időszak életciklust. Migráció nem tartozik hozzá.
