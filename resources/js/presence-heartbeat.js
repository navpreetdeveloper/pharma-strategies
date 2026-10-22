/** Keep the server's activity lease current even when WebSockets are unavailable. */
export function initializePresenceHeartbeat(document, window, request) {
    const config = window.PharmaStrategiesConfig;
    if (config?.presenceEnabled === false || !config?.companyId || !config?.routes?.heartbeat) return;
    let pending = false;
    const heartbeat = async () => {
        if (pending || document.visibilityState !== 'visible') return;
        pending = true;
        const controller = new AbortController();
        const timeout = window.setTimeout(() => controller.abort(), 10000);
        try {
            await request(config.routes.heartbeat, {
                method: 'POST', credentials: 'same-origin', keepalive: true, signal: controller.signal,
            });
        } catch { /* Retry on the next interval or foreground event. */ }
        finally { window.clearTimeout(timeout); pending = false; }
    };
    heartbeat();
    window.setInterval(heartbeat, 20000);
    document.addEventListener('visibilitychange', heartbeat);
    window.addEventListener('online', heartbeat);
}
