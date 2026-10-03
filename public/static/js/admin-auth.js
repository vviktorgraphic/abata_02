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
});
