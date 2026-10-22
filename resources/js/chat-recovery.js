export function initializeChatRecovery(document, window) {
    if (!window.setInterval) return;
    let pending = false;
    let again = false;
    const states = new WeakMap();
    const recover = async () => {
        if (pending || document.visibilityState !== 'visible') return;
        pending = true;
        const controller = new AbortController();
        const timeout = window.setTimeout(() => controller.abort(), 10000);
        try {
            const node = document.querySelector('[data-chat-config]');
            if (!node) return;
            const config = JSON.parse(node.textContent);
            const currentUrl = window.location?.href;
            if (config.updatesUrl) {
                if (!states.has(node)) {
                    const ids = Array.from(document.querySelectorAll('#messages [data-message-id]'), row => Number(row.dataset.messageId));
                    states.set(node, {cursor: config.recoveryCursor ?? (ids.length ? Math.max(0, Math.min(...ids) - 1) : 0), offset: 0});
                }
                const state = states.get(node);
                const ids = Array.from(document.querySelectorAll('#messages [data-message-id]'), row => Number(row.dataset.messageId));
                if (state.offset >= ids.length) state.offset = 0;
                const visible = ids.slice(state.offset, state.offset + 100);
                const page = new URL(currentUrl || 'http://localhost/chat').searchParams.get('conversations_page') || '1';
                // Drain bounded pages without allowing live-rendered IDs to advance this cursor.
                for (let batch = 0; batch < 10; batch++) {
                    const query = new URLSearchParams({after: String(state.cursor), sidebar: '1', conversations_page: page});
                    visible.forEach(id => query.append('visible[]', String(id)));
                    const response = await window.fetch(`${config.updatesUrl}?${query}`, {credentials: 'same-origin', cache: 'no-store', signal: controller.signal, headers: {Accept: 'application/json'}});
                    if (!response.ok) break;
                    const payload = await response.json();
                    if (node !== document.querySelector('[data-chat-config]') || currentUrl !== window.location?.href) return;
                    for (const message of payload.messages || []) window.dispatchEvent(new CustomEvent('pharma:message', {detail: {conversationId: config.conversationId, message}}));
                    window.dispatchEvent(new CustomEvent('pharma:message-reconcile', {detail: {conversationId: config.conversationId, messages: payload.reconciled || [], removed: payload.removed || []}}));
                    const next = Number(payload.cursor);
                    if (Number.isSafeInteger(next) && next >= state.cursor) state.cursor = next;
                    window.dispatchEvent(new CustomEvent('pharma:recovery-complete', {detail: {conversationId: config.conversationId, cursor: state.cursor}}));
                    if (typeof payload.sidebar === 'string') window.dispatchEvent(new CustomEvent('pharma:chat-sidebar', {detail: {html: payload.sidebar, url: currentUrl}}));
                    if (!payload.has_more) {
                        state.offset += visible.length;
                        if (typeof payload.sidebar !== 'string') window.dispatchEvent(new CustomEvent('pharma:chat-list-refresh'));
                        return;
                    }
                    if (!Number.isSafeInteger(next)) break;
                }
            }
            window.dispatchEvent(new CustomEvent('pharma:chat-list-refresh'));
        } catch { /* Retry from the last completed cursor without interrupting the composer. */ }
        finally { window.clearTimeout(timeout); pending = false; if (again) { again = false; void recover(); } }
    };
    window.setInterval(recover, 5000);
    document.addEventListener('visibilitychange', recover);
    window.addEventListener('online', recover);
    window.addEventListener('pharma:chat-recover', () => { if (pending) { again = true; return; } return recover(); });
    window.Echo?.connector?.pusher?.connection?.bind('connected', recover);
}
