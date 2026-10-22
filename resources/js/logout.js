/** Submit logout immediately; device-provider cleanup must not hold up session invalidation. */
export function initializeLogout(document, window, request) {
    document.querySelectorAll('form[action$="/logout"]').forEach(form => {
        form.addEventListener('submit', async event => {
            event.preventDefault();
            const controller = new AbortController();
            const timeout = window.setTimeout(() => controller.abort(), 10000);
            try {
                const response = await request(form.action, {method: 'POST', body: new window.FormData(form), signal: controller.signal});
                if (!response.ok) throw new Error('Sign-out request failed.');
                const result = await response.json();
                window.dispatchEvent(new CustomEvent('pharma:logged-out'));
                window.navigator.serviceWorker?.getRegistration('/')
                    .then(registration => registration?.pushManager?.getSubscription())
                    .then(subscription => subscription?.unsubscribe()).catch(() => {});
                const target = new URL(result.redirect || '/login', window.location.href);
                window.location.assign(target.origin === window.location.origin ? target.href : '/login');
            } catch {
                // Preserve the ordinary server-rendered logout/error behavior.
                window.HTMLFormElement.prototype.submit.call(form);
            } finally {
                window.clearTimeout(timeout);
            }
        });
    });
}
