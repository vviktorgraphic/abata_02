document.addEventListener('DOMContentLoaded', () => {
    const code = document.querySelector('[name="code"]');
    if (code instanceof HTMLInputElement) {
        code.addEventListener('input', () => { code.value = code.value.replace(/\D/g, '').slice(0, 6); });
    }
    document.querySelectorAll('form[data-confirm]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            if (!window.confirm(form.getAttribute('data-confirm') || 'Biztosan folytatja?')) {
                event.preventDefault();
            }
        });
    });

    const navigationToggle = document.querySelector('[data-admin-nav-toggle]');
    if (navigationToggle instanceof HTMLButtonElement) {
        const navigationId = navigationToggle.getAttribute('aria-controls');
        const navigation = navigationId === null ? null : document.getElementById(navigationId);
        if (navigation instanceof HTMLElement) {
            const closeNavigation = (restoreFocus = false) => {
                navigation.classList.remove('is-open');
                navigationToggle.setAttribute('aria-expanded', 'false');
                navigationToggle.setAttribute('aria-label', 'Admin menü megnyitása');
                if (restoreFocus) navigationToggle.focus();
            };
            navigationToggle.addEventListener('click', () => {
                const open = navigationToggle.getAttribute('aria-expanded') !== 'true';
                navigation.classList.toggle('is-open', open);
                navigationToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
                navigationToggle.setAttribute('aria-label', open ? 'Admin menü bezárása' : 'Admin menü megnyitása');
            });
            document.addEventListener('keydown', (event) => {
                if (event.key === 'Escape' && navigation.classList.contains('is-open')) {
                    closeNavigation(true);
                }
            });
            document.addEventListener('click', (event) => {
                if (event.target instanceof Node && !navigation.contains(event.target) && !navigationToggle.contains(event.target)) {
                    closeNavigation();
                }
            });
            navigation.querySelectorAll('a').forEach((link) => link.addEventListener('click', () => closeNavigation()));
            window.matchMedia('(max-width: 42rem)').addEventListener('change', () => closeNavigation());
        }
    }

    const modificationForm = document.querySelector('[data-booking-modification-form]');
    if (modificationForm instanceof HTMLFormElement) {
        const childCount = modificationForm.querySelector('[data-modification-child-count]');
        const ageContainer = modificationForm.querySelector('[data-modification-child-ages]');
        if (childCount instanceof HTMLSelectElement && ageContainer instanceof HTMLElement) {
            let initialAges = [];
            try { initialAges = JSON.parse(ageContainer.dataset.initialAges || '[]'); } catch { initialAges = []; }
            const renderAges = () => {
                const current = Array.from(ageContainer.querySelectorAll('input')).map((input) => input.value);
                ageContainer.replaceChildren();
                for (let index = 0; index < Number(childCount.value); index += 1) {
                    const wrapper = document.createElement('div');
                    const label = document.createElement('label');
                    const input = document.createElement('input');
                    input.type = 'number'; input.min = '0'; input.max = '17'; input.required = true;
                    input.name = 'child_ages[]'; input.id = `modification-child-age-${index + 1}`;
                    input.value = current[index] ?? initialAges[index] ?? '';
                    label.htmlFor = input.id; label.textContent = `${index + 1}. gyermek életkora`;
                    wrapper.append(label, input); ageContainer.append(wrapper);
                }
                initialAges = [];
            };
            childCount.addEventListener('change', renderAges);
            renderAges();
        }
    }
});
