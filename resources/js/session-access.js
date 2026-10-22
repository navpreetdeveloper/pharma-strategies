/** Single-flight permission checks; private history snapshots are masked until verified. */
export function initializeSessionAccess(document, window, request, revoke) {
    let current;
    let activeController;
    let epoch = 0;
    let restored = false;
    let revoked = false;
    const channel = window.BroadcastChannel ? new window.BroadcastChannel('pharma-session') : null;
    const root = document.documentElement;
    const mask = () => root.setAttribute('data-session-verifying', '');
    const reveal = () => root.removeAttribute('data-session-verifying');
    const check = async (restore = false) => {
        if (revoked) return;
        if (restore) { restored = true; mask(); }
        if (current) return current;
        const controller = new AbortController();
        activeController = controller;
        const checkEpoch = epoch;
        const timeout = window.setTimeout(() => controller.abort(), 10000);
        current = (async () => {
            try {
                const url = window.PharmaStrategiesConfig?.workspaceAccessEnabled ? '/workspace/access' : '/session/status';
                const response = await request(url, {signal: controller.signal, cache: 'no-store'});
                if (checkEpoch !== epoch) return;
                if ([401, 403, 419].includes(response.status)) {
                    revoked = true;
                    revoke();
                    if (restored || response.status === 401) window.location.replace('/login');
                    else reveal();
                } else if (response.ok) {
                    const context = await response.json();
                    if (checkEpoch !== epoch) return;
                    const expected = window.PharmaStrategiesConfig;
                    if (Number(context.user_id) !== Number(expected.userId) || Number(context.company_id || 0) !== Number(expected.companyId || 0) || (context.workspace_role || null) !== (expected.workspaceRole || null) || (context.security_fingerprint || null) !== (expected.securityFingerprint || null)) {
                        revoked = true;
                        mask();
                        revoke();
                        const target = new URL(context.landing_url || '/login', window.location.href);
                        window.location.replace(target.origin === window.location.origin ? target.href : '/login');
                        return;
                    }
                    restored = false;
                    reveal();
                }
            } catch { /* Network failure does not revoke permission or reveal an unverified history snapshot. */ }
            finally { window.clearTimeout(timeout); if (checkEpoch === epoch) current = null; }
        })();
        return current;
    };
    window.addEventListener('pagehide', () => {
        epoch++;
        activeController?.abort();
        current = null;
        restored = true;
        mask();
    });
    window.addEventListener('pageshow', event => {
        if (event.persisted) check(true);
        else if (!restored) reveal();
    });
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') check(true);
    });
    channel?.addEventListener('message', event => {
        if (event.data?.type !== 'logout' || Number(event.data.userId) !== Number(window.PharmaStrategiesConfig.userId)) return;
        revoked = true;
        mask();
        revoke();
        window.location.replace('/login');
    });
    window.addEventListener('pharma:logged-out', () => channel?.postMessage({type: 'logout', userId: window.PharmaStrategiesConfig.userId}));
    window.addEventListener('online', () => check(restored));
    const interval = window.setInterval(() => {
        if (document.visibilityState === 'visible') check(restored);
    }, 15000);
    return () => { window.clearInterval(interval); activeController?.abort(); channel?.close(); };
}
