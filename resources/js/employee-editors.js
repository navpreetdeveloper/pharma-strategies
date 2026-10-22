export function initializeEmployeeEditors(document, window) {
    if (!document.querySelector('.nc-employee-dialog')) return;
    let active = null;
    let opener = null;
    const measure = () => active?.style.setProperty('--nc-editor-height', (window.visualViewport?.height || window.innerHeight) + 'px');
    const open = (dialog, trigger = null) => {
        if (!dialog || typeof dialog.showModal !== 'function') return;
        opener = trigger;
        active = dialog;
        measure();
        dialog.showModal();
        document.body.classList.add('nc-employee-editor-open');
    };
    document.addEventListener('click', event => {
        const trigger = event.target.closest('[data-employee-editor]');
        if (trigger) {
            const dialog = document.getElementById(trigger.dataset.employeeEditor);
            open(dialog, trigger);
            return;
        }
        if (event.target.closest('[data-employee-editor-close]')) active?.close();
    });
    document.querySelectorAll('.nc-employee-dialog').forEach(dialog => {
        dialog.addEventListener('close', () => {
            active = null;
            document.body.classList.remove('nc-employee-editor-open');
            opener?.focus({preventScroll: true});
            opener = null;
        });
        dialog.addEventListener('click', event => {
            if (event.target !== dialog) return;
            const rect = dialog.getBoundingClientRect();
            if (event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom) dialog.close();
        });
    });
    window.addEventListener('resize', measure);
    window.visualViewport?.addEventListener('resize', measure);
    open(document.querySelector('[data-employee-editor-reopen]'));
}
