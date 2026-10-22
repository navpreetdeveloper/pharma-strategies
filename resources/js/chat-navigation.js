import { initializeChatRuntime } from './chat-runtime.js';
import { initializeChatRecovery } from './chat-recovery.js';

export function isChatUrl(value, base) {
    const url = new URL(value, base);
    const current = new URL(base);
    return url.origin === current.origin && url.pathname === current.pathname && !url.hash;
}

export function initializeChatNavigation(document, window) {
    if (!document.querySelector('[data-chat-view]')) return;
    const chatPath = window.location.pathname;
    let dispose = () => {};
    let navigationRequest;
    let sidebarRequest;
    let sidebarRefreshing = false;
    let sidebarAgain = false;
    let version = 0;
    let mountedUrl = window.location.href;
    const replaceSidebar = html => {
        const page = new window.DOMParser().parseFromString(html, 'text/html');
        const panel = document.querySelector('[data-conversation-results]');
        const next = page.querySelector('[data-conversation-results]');
        if (!panel || !next) return;
        panel.replaceChildren(...Array.from(next.childNodes).map(node => document.importNode(node, true)));
        window.dispatchEvent?.(new CustomEvent('pharma:presence-render'));
        document.querySelector('[data-chat-navigation-error]')?.remove();
        const selected = JSON.parse(document.querySelector('[data-chat-config]').textContent).conversationId;
        panel.querySelectorAll('[data-conversation-id]').forEach(link => {
            const active = Number(link.dataset.conversationId) === Number(selected);
            link.classList.toggle('active', active);
            if (active) link.setAttribute('aria-current', 'page');
            else link.removeAttribute('aria-current');
        });
    };
    const mount = () => {
        const config = JSON.parse(document.querySelector('[data-chat-config]').textContent);
        dispose = initializeChatRuntime(document, window, config);
        window.dispatchEvent?.(new CustomEvent('pharma:presence-render'));
    };
    const busy = () => document.getElementById('send-button')?.disabled;
    const report = (message, notify = true) => {
        let notice = document.querySelector('[data-chat-navigation-error]');
        if (!notice) {
            notice = document.createElement('div');
            notice.className = 'nc-alert nc-chat-flash';
            notice.dataset.chatNavigationError = '';
            notice.setAttribute('role', 'alert');
            document.querySelector('[data-chat-view]').prepend(notice);
        }
        notice.textContent = message;
        if (notify) window.PharmaFeedback?.error(message);
    };
    const navigate = async (url, {method = 'GET', body, history = 'push'} = {}) => {
        if (busy()) { report('Please wait for your message to finish sending.'); return; }
        navigationRequest?.abort();
        sidebarRequest?.abort();
        sidebarAgain = false;
        const request = new AbortController();
        navigationRequest = request;
        const requestVersion = ++version;
        const current = document.querySelector('[data-chat-view]');
        current.setAttribute('aria-busy', 'true');
        try {
            const response = await window.fetch(url, {
                method, body, signal: request.signal, credentials: 'same-origin', cache: 'no-store',
                headers: {'Accept': 'text/html', 'X-Requested-With': 'XMLHttpRequest', 'X-Chat-View': '1'},
            });
            if (requestVersion !== version) return;
            const target = new URL(response.url || url, window.location.href);
            if (target.origin !== window.location.origin || target.pathname !== chatPath) {
                window.location.assign(target.href);
                return;
            }
            if (!response.ok) throw new Error(response.status === 403 ? 'You no longer have access to this conversation.' : 'Could not open this conversation. Please try again.');
            const page = new window.DOMParser().parseFromString(await response.text(), 'text/html');
            if (requestVersion !== version) return;
            const next = page.querySelector('[data-chat-view]');
            if (!next?.querySelector('[data-chat-config]')) throw new Error('Could not open this conversation. Please refresh the page.');
            // Only replace the authenticated chat view. Never execute scripts from fetched HTML.
            dispose();
            current.replaceWith(document.importNode(next, true));
            document.title = response.headers?.get('X-Chat-Title') || page.title || document.title;
            if (history === 'push') window.history.pushState({chat: true}, '', target.href);
            mountedUrl = target.href;
            window.scrollTo(0, 0);
            mount();
        } catch (error) {
            if (error.name !== 'AbortError' && requestVersion === version) report(error.message);
        } finally {
            if (requestVersion === version) document.querySelector('[data-chat-view]')?.removeAttribute('aria-busy');
        }
    };
    // Delegation also covers the sidebar after the first message creates a visible entry.
    document.addEventListener('click', event => {
        const link = event.target.closest('a[href]');
        if (!link || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || link.target || link.hasAttribute('download')) return;
        if (!isChatUrl(link.href, window.location.href)) return;
        event.preventDefault();
        navigate(link.href);
    });
    document.addEventListener('submit', event => {
        const form = event.target.closest('.nc-new-chat-form');
        if (!form) return;
        event.preventDefault();
        navigate(form.action, {method: 'POST', body: new window.FormData(form)});
    });
    window.addEventListener('popstate', () => {
        if (busy()) {
            window.history.replaceState({chat: true}, '', mountedUrl);
            report('Please wait for your message to finish sending.');
            return;
        }
        navigate(window.location.href, {history: 'none'});
    });
    window.addEventListener('pharma:chat-close', () => navigate(chatPath));
    window.addEventListener('pharma:workspace-revoked', () => {
        navigationRequest?.abort();
        sidebarRequest?.abort();
        sidebarAgain = false;
        dispose();
        dispose = () => {};
    });
    window.addEventListener('pharma:chat-list-refresh', async () => {
        // Finish an in-flight refresh instead of restarting it for every live event.
        // A burst needs at most one follow-up request to include its latest message.
        sidebarAgain = true;
        if (sidebarRefreshing) return;
        sidebarRefreshing = true;
        try {
            do {
                sidebarAgain = false;
                const request = new AbortController();
                const timeout = window.setTimeout?.(() => request.abort(), 10000);
                sidebarRequest = request;
                const currentVersion = version;
                try {
                    const response = await window.fetch(chatPath, {credentials: 'same-origin', cache: 'no-store', signal: request.signal, headers: {Accept: 'text/html', 'X-Chat-Sidebar': '1'}});
                    if (!response.ok || new URL(response.url).pathname !== chatPath) return;
                    const html = await response.text();
                    if (currentVersion !== version || sidebarRequest !== request) return;
                    replaceSidebar(html);
                } catch (error) { if (error.name !== 'AbortError') report('Could not update conversations. Check your connection.', false); }
                finally {
                    window.clearTimeout?.(timeout);
                    if (sidebarRequest === request) sidebarRequest = undefined;
                }
            } while (sidebarAgain);
        } finally { sidebarRefreshing = false; }
    });
    window.addEventListener('pharma:notification', event => {
        const node = document.querySelector('[data-chat-config]');
        const selected = node ? JSON.parse(node.textContent).conversationId : null;
        if (selected != null && Number(selected) === Number(event.detail?.data?.conversation_id)) {
            window.dispatchEvent(new CustomEvent('pharma:chat-recover'));
        } else {
            window.dispatchEvent(new CustomEvent('pharma:chat-list-refresh'));
        }
    });
    window.addEventListener('pharma:chat-sidebar', event => {
        if (event.detail?.url !== window.location.href) return;
        replaceSidebar(event.detail.html);
    });
    mount();
    initializeChatRecovery(document, window);
}
