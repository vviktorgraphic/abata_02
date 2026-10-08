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
});
