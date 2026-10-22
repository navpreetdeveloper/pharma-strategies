export function initializePasswordVisibility(document) {
    document.querySelectorAll('[data-password-toggle]').forEach(button => {
        const input = document.getElementById(button.getAttribute('aria-controls'));
        if (!input || input.type !== 'password') return;

        const slash = button.querySelector('[data-password-slash]');
        function conceal() {
            input.type = 'password';
            button.setAttribute('aria-pressed', 'false');
            button.setAttribute('aria-label', 'Show password');
            slash?.setAttribute('hidden', '');
        }

        button.hidden = false;
        button.addEventListener('click', () => {
            if (input.type === 'text') {
                conceal();
                return;
            }
            input.type = 'text';
            button.setAttribute('aria-pressed', 'true');
            button.setAttribute('aria-label', 'Hide password');
            slash?.removeAttribute('hidden');
        });
        input.form?.addEventListener('submit', conceal);
        document.defaultView?.addEventListener('pagehide', conceal);
    });
}
