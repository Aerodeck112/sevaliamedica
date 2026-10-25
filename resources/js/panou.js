// Comportamente comune ale panoului. Totul funcționează și fără JavaScript; scriptul doar îmbunătățește.

// Meniul lateral pe ecrane mici.
document.addEventListener('click', (event) => {
    const toggle = event.target.closest('[data-nav-toggle]');
    const shell = document.querySelector('.shell');
    if (toggle && shell) {
        const open = shell.dataset.navOpen === 'true';
        shell.dataset.navOpen = open ? 'false' : 'true';
        toggle.setAttribute('aria-expanded', open ? 'false' : 'true');
        return;
    }
    if (shell?.dataset.navOpen === 'true' && !event.target.closest('.sidebar')) {
        shell.dataset.navOpen = 'false';
    }
});

// Confirmare pentru acțiuni ireversibile: <form data-confirm="Mesaj">.
document.addEventListener('submit', (event) => {
    const form = event.target;
    const message = form.dataset.confirm;
    if (message && !window.confirm(message)) {
        event.preventDefault();
        return;
    }
    // Previne trimiterea dublă a aceluiași formular.
    if (form.dataset.submitting === 'true') {
        event.preventDefault();
        return;
    }
    form.dataset.submitting = 'true';
    form.querySelectorAll('button[type="submit"], button:not([type])').forEach((b) => b.setAttribute('aria-disabled', 'true'));
    setTimeout(() => {
        form.dataset.submitting = 'false';
        form.querySelectorAll('[aria-disabled="true"]').forEach((b) => b.removeAttribute('aria-disabled'));
    }, 8000);
});

// Filtrele marcate cu data-autosubmit se trimit la schimbare.
document.addEventListener('change', (event) => {
    const form = event.target.closest('form[data-autosubmit]');
    if (form) form.requestSubmit();
});

// Dialoguri native: <button data-dialog-open="id"> și <button data-dialog-close>.
document.addEventListener('click', (event) => {
    const opener = event.target.closest('[data-dialog-open]');
    if (opener) {
        document.getElementById(opener.dataset.dialogOpen)?.showModal();
        return;
    }
    const closer = event.target.closest('[data-dialog-close]');
    if (closer) closer.closest('dialog')?.close();
});

// Module încărcate la cerere de paginile care le folosesc: <div data-module="calendar">.
const modules = import.meta.glob('./panou/*.js');
document.querySelectorAll('[data-module]').forEach((el) => {
    const loader = modules[`./panou/${el.dataset.module}.js`];
    if (loader) loader().then((m) => m.default?.(el));
});
