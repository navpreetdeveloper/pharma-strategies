/** Share only in-flight reads. Fulfilled private message responses are never cached. */
export function createMessageReceiver(window, denied, revoked, timeoutMs = 10000) {
    const pending = new Map();
    return async id => {
        if (revoked() || id == null) return null;
        const key = String(id);
        if (!pending.has(key)) {
            const controller = new AbortController();
            const timer = (window.setTimeout || setTimeout)(() => controller.abort(), timeoutMs);
            const request = (async () => window.fetch('/messages/' + encodeURIComponent(key), {credentials: 'same-origin', cache: 'no-store', signal: controller.signal, headers: {Accept: 'application/json'}}))()
                .then(async response => {
                    if (!response.ok) {
                        if ([401, 403, 419].includes(response.status)) denied();
                        return null;
                    }
                    const payload = await response.json();
                    return revoked() ? null : payload.message;
                }).finally(() => {
                    (window.clearTimeout || clearTimeout)(timer);
                    pending.delete(key);
                });
            pending.set(key, request);
        }
        return pending.get(key);
    };
}
