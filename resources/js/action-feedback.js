export function shortFeedbackMessage(message) {
    const replacements = [
        ['Employee account created.', 'Account created.'],
        ['Company membership updated.', 'Employee updated.'],
        ['Company access removed.', 'Access removed.'],
        ['Privacy policy saved.', 'Policy saved.'],
    ];
    return replacements.find(([prefix]) => message.startsWith(prefix))?.[1] || message;
}

export function actionDetails(form, submitter) {
    const method = (form.querySelector('[name="_method"]')?.value || form.method || 'GET').toUpperCase();
    const path = new URL(form.action, 'http://localhost').pathname;
    const label = form.dataset.confirmLabel || submitter?.textContent?.trim().replace(/^\+\s*/, '') || (method === 'DELETE' ? 'Remove' : 'Save changes');
    return {
        method,
        skip: method === 'GET' || (method === 'POST' && form.dataset.actionNavigation === 'true') || ['/login', '/two-factor-challenge'].includes(path) || form.matches('#composer, .nc-new-chat-form, .nc-edit-form'),
        title: form.dataset.confirmTitle || label + '?',
        text: form.dataset.confirmText || '',
        label,
        danger: method === 'DELETE' || form.dataset.confirmDanger === 'true',
    };
}

export function initializeActionFeedback(document, window, swal) {
    const approved = new WeakSet();
    const pending = new WeakSet();
    const submitted = new WeakSet();
    const target = () => document.querySelector('dialog[open]') || document.body;
    const feedback = {
        success(message) {
            return swal.fire({target: target(), toast: true, position: 'top-end', icon: 'success', title: shortFeedbackMessage(message), showConfirmButton: false, timer: 2200, timerProgressBar: true});
        },
        error(message) {
            return swal.fire({target: target(), icon: 'error', title: 'Action unsuccessful', text: message, confirmButtonText: 'OK'});
        },
    };
    window.PharmaFeedback = feedback;
    document.addEventListener('submit', async event => {
        const form = event.target;
        if (!form?.matches?.('form')) return;
        if (approved.has(form)) { approved.delete(form); submitted.add(form); return; }
        const details = actionDetails(form, event.submitter);
        if (details.skip) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        if (pending.has(form) || submitted.has(form)) return;
        pending.add(form);
        try {
            const result = await swal.fire({
                target: form.closest('dialog[open]') || document.body,
                title: details.title, text: details.text,
                showCancelButton: true, confirmButtonText: details.label, cancelButtonText: 'Cancel',
                reverseButtons: true, focusCancel: details.danger, allowOutsideClick: false,
                customClass: {popup: 'nc-swal-popup nc-swal-confirmation', actions: 'nc-swal-actions', confirmButton: 'nc-swal-confirm' + (details.danger ? ' danger' : ''), cancelButton: 'nc-swal-cancel'},
            });
            if (!result.isConfirmed) return;
            approved.add(form);
            // Replaying preserves validation, submitter values and existing logout cleanup.
            form.requestSubmit(event.submitter || undefined);
        } finally {
            approved.delete(form);
            pending.delete(form);
        }
    }, true);
    const notice = document.querySelector('[data-action-feedback]');
    if (notice) {
        if (notice.dataset.feedbackType === 'error') feedback.error(notice.textContent.trim());
        else feedback.success(notice.textContent.trim());
    }
    return feedback;
}
