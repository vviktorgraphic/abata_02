(() => {
    'use strict';

    const state = { month: startOfMonth(new Date()), days: new Map(), arrival: null, departure: null, rules: null, idempotencyKey: null, bookingSaved: false, submitting: false };
    const calendars = document.querySelector('#calendars');
    const errorBox = document.querySelector('#calendar-error');
    const periodLabel = document.querySelector('#calendar-period');
    const previousButton = document.querySelector('#previous-month');
    const nextButton = document.querySelector('#next-month');
    const clearButton = document.querySelector('#clear-dates');
    const localeDate = new Intl.DateTimeFormat('hu-HU', { year: 'numeric', month: 'long', day: 'numeric' });
    const localeMonth = new Intl.DateTimeFormat('hu-HU', { year: 'numeric', month: 'long' });
    const statusLabels = {
        available: 'szabad', occupied: 'foglalt', departure_only: 'távozási nap, érkezés lehetséges',
        arrival_only: 'érkezési nap', turnover: 'távozás és érkezés', blocked: 'lezárt', past: 'múltbeli'
    };

    function startOfMonth(date) { return new Date(date.getFullYear(), date.getMonth(), 1); }
    function addMonths(date, amount) { return new Date(date.getFullYear(), date.getMonth() + amount, 1); }
    function iso(date) {
        const year = date.getFullYear();
        return `${year}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
    }
    function parseIso(value) { const [year, month, day] = value.split('-').map(Number); return new Date(year, month - 1, day); }
    function differenceInDays(from, to) { return Math.round((parseIso(to) - parseIso(from)) / 86400000); }
    function newIdempotencyKey() {
        if (typeof crypto.randomUUID === 'function') return crypto.randomUUID();
        const bytes = new Uint8Array(16); crypto.getRandomValues(bytes);
        return Array.from(bytes, byte => byte.toString(16).padStart(2, '0')).join('');
    }

    async function load() {
        calendars.setAttribute('aria-busy', 'true');
        errorBox.hidden = true;
        state.days.clear();
        const from = iso(state.month);
        const to = iso(addMonths(state.month, 2));
        try {
            const response = await fetch(`/api/availability?from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}`, { headers: { Accept: 'application/json' } });
            const payload = await response.json();
            if (!response.ok) throw new Error(payload.error || 'A naptár nem tölthető be.');
            payload.days.forEach(day => state.days.set(day.date, day));
            state.rules = payload.rules;
            render();
        } catch (error) {
            calendars.replaceChildren();
            errorBox.textContent = 'A foglaltsági adatok most nem érhetők el. Kérjük, próbáld újra később.';
            errorBox.hidden = false;
        } finally {
            calendars.setAttribute('aria-busy', 'false');
        }
    }

    function render() {
        calendars.replaceChildren(renderMonth(state.month), renderMonth(addMonths(state.month, 1)));
        periodLabel.textContent = `${localeMonth.format(state.month)} – ${localeMonth.format(addMonths(state.month, 1))}`;
        previousButton.disabled = addMonths(state.month, 1) <= startOfMonth(new Date());
        updateSummary();
    }

    function renderMonth(month) {
        const section = document.createElement('section');
        section.className = 'month';
        const heading = document.createElement('h3');
        heading.textContent = localeMonth.format(month);
        const grid = document.createElement('div');
        grid.className = 'month-grid';
        grid.setAttribute('role', 'grid');
        ['H', 'K', 'Sze', 'Cs', 'P', 'Szo', 'V'].forEach(label => {
            const weekday = document.createElement('div');
            weekday.className = 'weekday'; weekday.textContent = label; weekday.setAttribute('role', 'columnheader'); grid.append(weekday);
        });
        const firstOffset = (month.getDay() + 6) % 7;
        for (let i = 0; i < firstOffset; i++) { const empty = document.createElement('span'); empty.className = 'day empty'; grid.append(empty); }
        const count = new Date(month.getFullYear(), month.getMonth() + 1, 0).getDate();
        for (let dayNumber = 1; dayNumber <= count; dayNumber++) {
            const date = new Date(month.getFullYear(), month.getMonth(), dayNumber);
            const dateIso = iso(date);
            const availability = state.days.get(dateIso);
            const button = document.createElement('button');
            button.type = 'button'; button.className = `day ${availability?.status || 'past'}`; button.textContent = dayNumber;
            const label = `${localeDate.format(date)}: ${statusLabels[availability?.status] || 'nem elérhető'}`;
            button.title = label; button.setAttribute('aria-label', label); button.dataset.date = dateIso;
            button.disabled = !isPotentiallySelectable(availability);
            if (state.arrival === dateIso) button.classList.add('selected-arrival');
            if (state.departure === dateIso) button.classList.add('selected-departure');
            if (state.arrival && state.departure && dateIso > state.arrival && dateIso < state.departure) button.classList.add('in-range');
            button.addEventListener('click', () => selectDate(dateIso));
            grid.append(button);
        }
        section.append(heading, grid);
        return section;
    }

    function isPotentiallySelectable(day) {
        if (!day) return false;
        return state.arrival && !state.departure ? day.selectable_as_departure : day.selectable_as_arrival;
    }

    function selectDate(date) {
        if (state.bookingSaved) return;
        if (!state.arrival || state.departure) { state.arrival = date; state.departure = null; }
        else {
            const nights = differenceInDays(state.arrival, date);
            if (nights < state.rules.minimum_nights || nights > state.rules.maximum_nights || intervalBlocked(state.arrival, date)) {
                errorBox.textContent = `A távozás nem választható: ${state.rules.minimum_nights}–${state.rules.maximum_nights} éjszaka engedélyezett, foglalt nap érintése nélkül.`;
                errorBox.hidden = false; return;
            }
            state.departure = date; errorBox.hidden = true;
        }
        state.idempotencyKey = null;
        render();
    }

    function intervalBlocked(arrival, departure) {
        for (const [date, day] of state.days) {
            if (date >= arrival && date < departure && ['occupied', 'blocked', 'arrival_only', 'turnover'].includes(day.status)) return true;
        }
        return false;
    }

    function updateSummary() {
        document.querySelector('#arrival-summary').textContent = state.arrival ? localeDate.format(parseIso(state.arrival)) : 'Nincs kiválasztva';
        document.querySelector('#departure-summary').textContent = state.departure ? localeDate.format(parseIso(state.departure)) : 'Nincs kiválasztva';
        document.querySelector('#arrival-input').value = state.arrival || '';
        document.querySelector('#departure-input').value = state.departure || '';
        clearButton.disabled = !state.arrival;
    }

    previousButton.addEventListener('click', () => { state.month = addMonths(state.month, -1); load(); });
    nextButton.addEventListener('click', () => {
        const horizon = addMonths(startOfMonth(new Date()), 12);
        if (addMonths(state.month, 1) <= horizon) { state.month = addMonths(state.month, 1); load(); }
    });
    clearButton.addEventListener('click', () => { state.arrival = null; state.departure = null; state.idempotencyKey = null; errorBox.hidden = true; render(); });

    document.querySelector('#child-count').addEventListener('change', event => {
        const container = document.querySelector('#child-ages');
        container.replaceChildren();
        for (let index = 1; index <= Number(event.target.value); index++) {
            const wrapper = document.createElement('div'); wrapper.className = 'field';
            const label = document.createElement('label'); label.htmlFor = `child-age-${index}`; label.textContent = `${index}. gyermek életkora`;
            const input = document.createElement('input'); input.id = `child-age-${index}`; input.name = 'child_ages[]'; input.type = 'number'; input.min = '0'; input.max = '17'; input.required = true;
            wrapper.append(label, input); container.append(wrapper);
        }
    });

    const bookingForm = document.querySelector('#booking-form');
    const submitButton = document.querySelector('#booking-submit');
    const submitLabel = submitButton.querySelector('.submit-label');
    const message = document.querySelector('#form-message');

    bookingForm.addEventListener('input', () => {
        if (!state.bookingSaved && !state.submitting) state.idempotencyKey = null;
    });

    function clearFieldErrors(form) {
        form.querySelectorAll('[aria-invalid="true"]').forEach(field => field.removeAttribute('aria-invalid'));
        form.querySelectorAll('[data-error-for]').forEach(error => { error.textContent = ''; });
    }

    function showValidationErrors(form, errors) {
        Object.entries(errors || {}).forEach(([name, detail]) => {
            const field = form.elements.namedItem(name) || form.elements.namedItem(`${name}[]`);
            const error = form.querySelector(`[data-error-for="${CSS.escape(name)}"]`);
            const text = Array.isArray(detail) ? detail[0] : String(detail);
            if (field instanceof HTMLElement) field.setAttribute('aria-invalid', 'true');
            if (error) error.textContent = text;
        });
    }

    function setMessage(text, kind = '') {
        message.className = `notice full${kind ? ` notice-${kind}` : ''}`;
        message.textContent = text;
        message.hidden = false;
        message.focus();
    }

    bookingForm.addEventListener('submit', async event => {
        event.preventDefault();
        if (state.submitting || state.bookingSaved) return;
        if (!state.arrival || !state.departure) { setMessage('Előbb válassz érkezési és távozási dátumot.', 'error'); return; }
        if (!event.currentTarget.checkValidity()) { event.currentTarget.reportValidity(); return; }
        const form = event.currentTarget;
        clearFieldErrors(form);
        const formData = new FormData(form);
        const payload = Object.fromEntries(formData.entries());
        payload.adults = Number(payload.adults);
        payload.children = Number(payload.children);
        payload.privacy_accepted = formData.has('privacy_accepted');
        payload.child_ages = formData.getAll('child_ages[]').map(Number);
        state.idempotencyKey ||= newIdempotencyKey();
        payload.idempotency_key = state.idempotencyKey;
        state.submitting = true;
        submitButton.disabled = true;
        submitButton.setAttribute('aria-busy', 'true');
        submitLabel.textContent = 'Küldés folyamatban…';
        setMessage('A foglalási igény mentése folyamatban van. Kérjük, várj.', 'warning');
        try {
            const response = await fetch('/api/bookings', {
                method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify(payload)
            });
            const result = await response.json().catch(() => ({}));
            if ((response.status === 200 || response.status === 201) && result.status === 'pending') {
                state.bookingSaved = true;
                const reference = result.reference ? ` Hivatkozás: ${result.reference}.` : '';
                const total = result.total_amount != null ? ` Végösszeg: ${result.total_amount} ${result.currency || 'HUF'}.` : '';
                if (result.email_status === 'failed') {
                    setMessage(`A foglalási igényt rögzítettük.${reference}${total} A visszaigazoló e-mailt most nem sikerült elküldeni; az igény ettől még megmaradt, ne küldd el újra.`, 'warning');
                } else {
                    setMessage(`Köszönjük, a foglalási igényt rögzítettük.${reference}${total} Ez még nem végleges foglalás; adminisztrátori jóváhagyás szükséges.`, 'success');
                }
                form.querySelectorAll('input, select, textarea, button').forEach(control => { control.disabled = true; });
            } else if (response.status === 409) {
                setMessage(result.message || 'A kiválasztott időszak időközben foglalttá vált, vagy ez a kérés eltér egy korábbi, azonos azonosítójú kéréstől. Kérjük, ellenőrizd az adatokat.', 'error');
            } else if (response.status === 422 || response.status === 400) {
                showValidationErrors(form, result.errors);
                setMessage(result.message || 'Kérjük, javítsd a megjelölt adatokat.', 'error');
            } else if (response.status === 429) {
                setMessage('Túl sok kérés érkezett. Kérjük, várj egy kicsit, majd ugyanerről az oldalról próbáld újra.', 'warning');
            } else {
                setMessage('A foglalási igényt most nem tudtuk feldolgozni. Kérjük, próbáld újra; az oldal ugyanazzal a kérésazonosítóval védi a dupla mentést.', 'error');
            }
        } catch (error) {
            setMessage('A hálózati kapcsolat megszakadt. Nem biztos, hogy a mentés befejeződött; próbáld újra ezen az oldalon, így nem jön létre dupla foglalás.', 'warning');
        } finally {
            state.submitting = false;
            if (!state.bookingSaved) {
                submitButton.disabled = false;
                submitButton.removeAttribute('aria-busy');
                submitLabel.textContent = 'Foglalási igény küldése';
            }
        }
    });

    load();
})();
