export function passwordFeedback(value) {
    if (!value) return '8+ characters, uppercase, lowercase and a number.';
    const missing = [];
    if (Array.from(value).length < 8) missing.push('8 characters');
    if (!/\p{Lu}/u.test(value)) missing.push('an uppercase letter');
    if (!/\p{Ll}/u.test(value)) missing.push('a lowercase letter');
    if (!/\p{N}/u.test(value)) missing.push('a number');
    return missing.length ? 'Add ' + missing.join(', ') + '.' : '';
}

export function initializeFormFeedback(document) {
    let sequence = 0;
    const errors = new WeakMap();
    const passwords = new Set();
    const guidance = new WeakMap();
    const describe = (field, node) => {
        node.id = 'field-feedback-' + (++sequence);
        const ids = new Set((field.getAttribute('aria-describedby') || '').split(/\s+/).filter(Boolean));
        ids.add(node.id);
        field.setAttribute('aria-describedby', [...ids].join(' '));
        field.insertAdjacentElement('afterend', node);
    };
    const showError = (field, text) => {
        if (guidance.has(field)) {
            const help = guidance.get(field);
            help.textContent = text || passwordFeedback(field.value) || 'Password meets requirements.';
            help.classList.toggle('is-error', !!text);
            if (text) field.setAttribute('aria-invalid', 'true');
            else field.removeAttribute('aria-invalid');
            return;
        }
        let node = errors.get(field);
        if (!node && !text) return;
        if (!node) {
            node = document.createElement('span');
            node.className = 'nc-field-error';
            node.setAttribute('aria-live', 'polite');
            describe(field, node);
            errors.set(field, node);
        }
        node.textContent = text;
        node.hidden = !text;
        if (text) field.setAttribute('aria-invalid', 'true');
        else field.removeAttribute('aria-invalid');
    };
    document.querySelectorAll('input[type="password"][autocomplete="new-password"]').forEach(field => {
        if (!['password', 'admin_password'].includes(field.name)) return;
        passwords.add(field);
        const help = document.createElement('span');
        help.className = 'nc-password-guidance';
        help.setAttribute('role', 'status');
        help.setAttribute('aria-live', 'polite');
        describe(field, help);
        guidance.set(field, help);
        const previous = field.parentElement.querySelector('.nc-form-help');
        if (previous) previous.hidden = true;
        const update = () => {
            const message = passwordFeedback(field.value);
            field.setCustomValidity(field.value ? message : '');
            help.textContent = message || 'Password meets requirements.';
            help.classList.toggle('is-valid', !!field.value && !message);
        };
        field.addEventListener('input', update);
        update();
    });
    const forms = [...document.querySelectorAll('form')].filter(form => form.method.toUpperCase() !== 'GET' && !form.matches('#composer, .nc-new-chat-form, .nc-edit-form'));
    forms.forEach(form => {
        form.noValidate = true;
        [...form.elements].forEach(field => {
            field.addEventListener('input', () => showError(field, ''));
            field.addEventListener('change', () => showError(field, ''));
        });
    });
    document.addEventListener('submit', event => {
        const form = event.target;
        if (!forms.includes(form) || event.submitter?.formNoValidate) return;
        const fields = [...form.elements].filter(field => field.willValidate);
        let first;
        fields.forEach(field => {
            let message = field.validity.valid ? '' : field.validationMessage;
            if (field.type === 'email' && field.validity.typeMismatch) message = 'Email is invalid.';
            if (field.name.endsWith('password_confirmation')) {
                const original = [...form.elements].find(input => input.name === field.name.replace('_confirmation', ''));
                if (original && field.value !== original.value) message = 'Passwords do not match.';
            }
            if (passwords.has(field) && field.value) message = passwordFeedback(field.value);
            showError(field, message);
            if (message && !first) first = field;
        });
        if (first) {
            event.preventDefault();
            event.stopImmediatePropagation();
            first.focus();
        }
    }, true);
    const payload = document.querySelector('[data-validation-errors]');
    if (payload) {
        const scope = document.querySelector('dialog[data-employee-editor-reopen]') || document.querySelector('main');
        const fields = [...scope.querySelectorAll('input, select, textarea')];
        const messages = JSON.parse(payload.textContent);
        const unmatched = [];
        Object.entries(messages).forEach(([name, values]) => {
            const field = fields.find(input => input.name === name);
            if (field) showError(field, values[0]);
            else unmatched.push(values[0]);
        });
        if (unmatched.length) {
            const summary = document.createElement('div');
            summary.className = 'nc-inline-form-summary';
            summary.setAttribute('role', 'alert');
            summary.textContent = unmatched.join(' ');
            (scope.querySelector('form') || scope).prepend(summary);
        }
    }
}
