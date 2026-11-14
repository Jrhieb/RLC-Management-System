export function initializeStaffLogin(doc = document) {
    const dialog = doc.querySelector('#staff-login-dialog');
    if (!dialog || typeof dialog.showModal !== 'function' || dialog.dataset.initialized) return;
    dialog.dataset.initialized = 'true';
    let opener;
    doc.querySelectorAll('[data-staff-login-open]').forEach((link) => {
        link.setAttribute('aria-haspopup', 'dialog');
        link.setAttribute('aria-controls', dialog.id);
        link.addEventListener('click', (event) => {
            if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || event.button !== 0) return;
            event.preventDefault();
            const registration = link.closest('[data-registration-dialog]');
            opener = registration ? doc.querySelector('.public-nav-login') : link;
            const open = () => {
                if (!dialog.open) dialog.showModal();
                dialog.querySelector('[name=email]').focus();
            };
            if (registration?.open) {
                registration.addEventListener('close', open, { once: true });
                registration.querySelector('[data-registration-close]').click();
            } else {
                open();
            }
        });
    });
    dialog.querySelector('.staff-login-close').addEventListener('click', () => dialog.close());
    dialog.addEventListener('close', () => {
        const password = dialog.querySelector('[name=password]');
        password.value = '';
        password.type = 'password';
        toggle.setAttribute('aria-label', 'Show password');
        toggle.setAttribute('aria-pressed', 'false');
        if (!doc.querySelector('dialog[open]')) {
            const target = opener?.getClientRects().length ? opener : doc.querySelector('[data-menu-toggle]');
            target?.focus();
        }
    });
    const toggle = dialog.querySelector('[data-login-password-toggle]');
    toggle.addEventListener('click', () => {
        const password = dialog.querySelector('[name=password]');
        const show = password.type === 'password';
        password.type = show ? 'text' : 'password';
        toggle.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
        toggle.setAttribute('aria-pressed', String(show));
    });
    if (doc.defaultView.location.hash === '#staff-login') {
        doc.querySelector('.public-nav-login')?.click();
    }
}

if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => initializeStaffLogin());
    else initializeStaffLogin();
}
