import Swal from 'sweetalert2';
import { initializeNavigation } from './navigation';
import { initializeActionFeedback } from './action-feedback';
import { initializeFormFeedback } from './form-feedback';
import { initializePasswordVisibility } from './password-visibility';

export function initializePageUi(document, window) {
    window.Swal = Swal.mixin({
        buttonsStyling: false,
        heightAuto: false,
        customClass: {popup: 'nc-swal-popup', title: 'nc-swal-title', htmlContainer: 'nc-swal-text', confirmButton: 'nc-swal-confirm', cancelButton: 'nc-swal-cancel'},
    });
    initializeNavigation(document, window);
    document.addEventListener('DOMContentLoaded', () => {
        initializePasswordVisibility(document);
        initializeFormFeedback(document);
        initializeActionFeedback(document, window, window.Swal);
        document.querySelectorAll('[data-history-back]').forEach(link => {
            link.addEventListener('click', event => {
                try {
                    const referrer = document.referrer ? new URL(document.referrer) : null;
                    if (referrer?.origin === window.location.origin && window.history.length > 1) {
                        event.preventDefault();
                        window.history.back();
                    }
                } catch { /* Keep the link's normal destination. */ }
            });
        });
    }, {once: true});
}
