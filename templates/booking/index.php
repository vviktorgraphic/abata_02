<!doctype html>
<html lang="hu">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Válasszon szabad időpontot és állítsa össze foglalási igényét.">
    <title>Foglalás | Abáta</title>
    <link rel="stylesheet" href="/assets/css/booking.css">
</head>
<body>
<main class="booking-shell">
    <section class="calendar-panel" aria-labelledby="booking-title">
        <p class="eyebrow">Közvetlen foglalási igény</p>
        <h1 id="booking-title">Mikor érkeznétek?</h1>
        <p class="intro">Válaszd ki az érkezés és távozás napját. A távozási nap már nem számít foglalt éjszakának.</p>

        <div class="calendar-toolbar">
            <button class="month-nav" id="previous-month" type="button" aria-label="Előző hónap">←</button>
            <p id="calendar-period" aria-live="polite"></p>
            <button class="month-nav" id="next-month" type="button" aria-label="Következő hónap">→</button>
        </div>

        <div id="calendar-error" class="notice notice-error" role="alert" hidden></div>
        <div id="calendars" class="calendars" aria-busy="true"></div>

        <div class="legend" aria-label="Naptár jelmagyarázat">
            <span><i class="legend-dot available"></i>Szabad</span>
            <span><i class="legend-dot occupied"></i>Foglalt</span>
            <span><i class="legend-dot partial"></i>Érkezés vagy távozás</span>
            <span><i class="legend-dot blocked"></i>Nem elérhető</span>
        </div>

        <div class="selection-summary" aria-live="polite">
            <div><span>Érkezés</span><strong id="arrival-summary">Nincs kiválasztva</strong></div>
            <div class="summary-arrow">→</div>
            <div><span>Távozás</span><strong id="departure-summary">Nincs kiválasztva</strong></div>
            <button id="clear-dates" class="text-button" type="button" disabled>Dátumok törlése</button>
        </div>
    </section>

    <section class="form-panel" aria-labelledby="details-title">
        <div>
            <p class="eyebrow">Foglalási adatok</p>
            <h2 id="details-title">Mesélj magatokról</h2>
            <p class="intro">Az űrlap ebben a fejlesztési szakaszban még nem küld valódi foglalást.</p>
        </div>

        <form id="booking-form" novalidate>
            <div class="field full">
                <label for="guest-name">Név</label>
                <input id="guest-name" name="name" autocomplete="name" required>
            </div>
            <div class="field">
                <label for="guest-email">E-mail-cím</label>
                <input id="guest-email" name="email" type="email" autocomplete="email" required>
            </div>
            <div class="field">
                <label for="guest-phone">Telefonszám</label>
                <input id="guest-phone" name="phone" type="tel" autocomplete="tel" required>
            </div>
            <div class="field">
                <label for="adult-count">Felnőttek</label>
                <select id="adult-count" name="adults">
                    <option>1</option><option selected>2</option><option>3</option><option>4</option><option>5</option><option>6</option>
                </select>
            </div>
            <div class="field">
                <label for="child-count">Gyermekek</label>
                <select id="child-count" name="children">
                    <option selected>0</option><option>1</option><option>2</option><option>3</option><option>4</option>
                </select>
            </div>
            <div id="child-ages" class="child-ages full" aria-live="polite"></div>
            <div class="field full">
                <label for="guest-notes">Megjegyzés <span>(opcionális)</span></label>
                <textarea id="guest-notes" name="notes" rows="4"></textarea>
            </div>
            <label class="consent full">
                <input name="privacy" type="checkbox" required>
                <span>Elolvastam és elfogadom az adatkezelési tájékoztatót.</span>
            </label>
            <input id="arrival-input" name="arrival_date" type="hidden">
            <input id="departure-input" name="departure_date" type="hidden">
            <div id="form-message" class="notice full" role="status" hidden></div>
            <button class="submit-button full" type="submit">Foglalási igény küldése <span>→</span></button>
        </form>
    </section>
</main>
<script src="/assets/js/booking-calendar.js" defer></script>
</body>
</html>

