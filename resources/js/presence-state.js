import { createPresenceStore } from './state/presence-store.js';

export function initializePresenceState(document, window) {
    const users = createPresenceStore();
    const config = window.PharmaStrategiesConfig || {};
    let pending = false;
    let revision = 0;
    const nodes = () => document.querySelectorAll('[data-presence-user], [data-selected-presence-user]');
    const render = () => nodes().forEach(node => {
        const id = Number(node.dataset.presenceUser || node.dataset.selectedPresenceUser);
        const user = users.get(id);
        node.classList.toggle('is-online', !!user?.online);
        const label = node.querySelector('[data-presence-label]');
        if (label) label.textContent = user?.online ? 'Online' : 'Offline';
    });
    const refresh = async () => {
        if (pending || !config.routes?.presence || document.visibilityState !== 'visible') return;
        const ids = [...new Set(Array.from(nodes(), node => Number(node.dataset.presenceUser || node.dataset.selectedPresenceUser)))].filter(id => id > 0).slice(0, 100);
        if (!ids.length) return;
        pending = true;
        const startedAtRevision = revision;
        const controller = new AbortController();
        const timeout = window.setTimeout(() => controller.abort(), 10000);
        try {
            const query = ids.map(id => `ids[]=${id}`).join('&');
            const response = await window.fetch(`${config.routes.presence}?${query}`, {credentials: 'same-origin', cache: 'no-store', signal: controller.signal, headers: {Accept: 'application/json'}});
            if (!response.ok) return;
            const payload = await response.json();
            if (revision !== startedAtRevision) return;
            ids.forEach(id => users.set(id, {online: false}));
            (payload.users || []).forEach(user => users.set(Number(user.id), user));
            render();
        } catch { /* Keep the last known state during temporary network loss. */ }
        finally { window.clearTimeout(timeout); pending = false; }
    };
    window.addEventListener('pharma:presence', event => {
        const data = event.detail || {};
        revision++;
        if (data.type === 'snapshot') {
            users.snapshot(data.users || []);
        } else if (data.user) {
            users.set(Number(data.user.id), {...data.user, online: data.type !== 'leaving'});
        }
        render();
    });
    window.addEventListener('pharma:presence-render', () => { render(); refresh(); });
    document.addEventListener('visibilitychange', refresh);
    window.addEventListener('online', refresh);
    window.Echo?.connector?.pusher?.connection?.bind('state_change', refresh);
    window.setInterval(refresh, 15000);
    refresh();
}
