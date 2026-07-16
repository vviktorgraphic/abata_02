document.addEventListener('DOMContentLoaded', () => {
    const code = document.querySelector('[name="code"]');
    if (!(code instanceof HTMLInputElement)) return;
    code.addEventListener('input', () => { code.value = code.value.replace(/\D/g, '').slice(0, 6); });
});
