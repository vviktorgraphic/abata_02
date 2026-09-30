# Árképzési admin UX- és runtime-javítás

**Állapot:** IMPLEMENTED a `fix/pricing-admin-ux` ágon; production aktiválás és valós cPanel/browser smoke PENDING.

## Tulajdonosi workflow

Az egyetlen normál menüpont és kanonikus oldal az `Árképzés` (`GET /admin/pricing`). Itt külön panelen szerkeszthető a hétköznapi és hétvégi felnőttár, táblázatban kezelhetők a 0–17 éves gyermekek ársávjai, és üzleti nyelvű kalkulációs előnézet kérhető. A péntek és szombat hétvégi éjszaka. Az oldal nem mutat legacy/person, rule type, base/stay_length, sorrend, aktív állapot, rule ID vagy konfigurációverzió fogalmat.

A felnőttár mentése explicit person módra vált; addig a korábban beállított motor marad érvényben, amit az oldal nem technikai figyelmeztetéssel jelez. Konkrét production árat a javítás nem vezet be. A korábbi általános rulesorok megmaradnak az adatbázisban, de a normál tulajdonosi route-okról nem kezelhetők.

## Gyermek ársáv törlés

A `Törlés` külső, CSP-kompatibilis JavaScript megerősítést használ. A repository dedikált tranzakcióban zárolja a singleton konfigurációt, ellenőrzi az optimistic verziót, fizikailag törli a sávot, növeli a konfigurációverziót és `person_pricing.band_deleted` auditot ír. A `pricing_child_age_coverage` FK `ON DELETE CASCADE`; booking vagy pricing snapshot nem hivatkozik idegen kulccsal a sávra, mert az immutable JSON a számításkori adat teljes másolata.

Törlés után hiányos coverage nem töltődik fel automatikus 0 Ft-os sávval. A felület figyelmeztet, a közös pricing engine pedig az érintett életkorral preview és booking során fail-closed marad.

## HUF runtime-hiba

A reprodukált gyökérok nem a formatter algoritmusa volt. A fejlesztői demo writer a Sprint 6 utáni `amount` oszlopot nem töltötte, ezért egy sorban `amount=NULL`, miközben a régi `nightly_price='10000.00'` maradt. A legacy admin sablon a nullt közvetlenül a szigorú `HufFormatter::format(string|int)` metódusnak adta, ami `TypeError` hibát okozott.

A `020_backfill_legacy_pricing_amount.sql` visszatölti ezeket a sorokat, a demo seed és a repository writer mindkét mezőt konzisztensen kezeli, a read repository pedig kompatibilitási fallbacket ad. A formatter nem lett kontrollálatlan mixed API. Támogatott reprezentáció az egész PHP integer és a kanonikus/numerikus DECIMAL string; float, null és nem decimális szöveg elutasított.

Production környezetben a web entry point kikapcsolja a PHP hibák response-ba írását, a legfelső HTTP hibahatár fix 500 oldalt ad, az `AdminView` pedig kivételnél eltakarítja a részleges output buffert. A hibanapló csak a kivételosztályt kapja, üzenetet, stack trace-t vagy kéréstartalmat nem.

## Reszponzív layout

A dokumentumszintű túlcsordulás gyökere az intrinsic szélesség volt: a nem törő navigáció, a grid konténer automatikus minimuma, az `admin-page` hiányzó `min-width:0` értéke és a nowrap táblák együtt a viewportnál szélesebb dokumentumot hoztak létre. A layout most `width:100%` + `max-width`, minden kritikus flex/grid elem `min-width:0`, a navigáció törik, a form grid keskeny nézetben egy oszlopra fér, és kizárólag a `.table-scroll` lehet vízszintesen görgethető. Nincs `overflow-x:hidden` elfedés.

Az ellenőrzési szélességek: 1920, 1440, 1366, 1280, 1024, 768 és 390 px. Production cPanelen a valós böngésző- és tartalom-smoke továbbra is release gate.

## Regressziós szerződés

- A preview megmutatja a szállásdíjat, IFA-t, végösszeget, éjszakaszámot, vendégösszetételt, valamint person snapshotnál a felnőtt- és gyermekdíjat; legacy snapshotnál nem talál ki 0 Ft-os bontást.
- V1/v2/v3 snapshot olvasható; a történeti snapshot ársávtörléskor byte-ra változatlan.
- Lemondás továbbra is kizárólag az immutable `accommodation_fee` alapján számol.
- Az automatikus iCal workerhez és adatmodellhez ez a javítás nem nyúl.
- A specifikáció tesztfixture-árai kizárólag izolált tesztadatbázisban futnak, production konfigurációba nem kerülnek.
