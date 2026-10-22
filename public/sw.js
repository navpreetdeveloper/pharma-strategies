self.addEventListener('install', event => event.waitUntil(self.skipWaiting()));
self.addEventListener('activate', event => event.waitUntil(self.clients.claim()));

self.addEventListener('push', event => {
    if (!event.data) return;

    let payload;
    try {
        payload = event.data.json();
    } catch {
        payload = { title: 'Pharma Strategies', body: event.data.text(), data: {} };
    }

    const data = payload?.data || {};
    const title = payload.title || 'Pharma Strategies';
    const body = payload.body || 'You have a new workplace message.';
    const targetUrl = payload.url || '/chat';
    const messageId = data.message_id ? String(data.message_id) : '';
    const conversationId = data.conversation_id ? String(data.conversation_id) : '';

    event.waitUntil((async () => {
        const clientsList = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
        const sameConversationOpen = conversationId && clientsList.some(client => {
            try {
                const url = new URL(client.url);
                return client.visibilityState === 'visible'
                    && url.origin === self.location.origin
                    && url.pathname === '/chat'
                    && url.searchParams.get('conversation') === conversationId;
            } catch {
                return false;
            }
        });

        if (sameConversationOpen) return;

        await self.registration.showNotification(title, {
            body,
            icon: payload.icon || '/icons/icon-192.png',
            badge: payload.badge || '/icons/icon-192.png',
            tag: conversationId ? `conversation-${conversationId}` : (messageId ? `message-${messageId}` : `pharma-strategies-${Date.now()}`),
            renotify: false,
            requireInteraction: false,
            timestamp: Date.now(),
            data: {
                url: targetUrl,
                conversation_id: conversationId,
                message_id: messageId,
                notification_id: data.notification_id || null,
            },
        });
    })());
});

self.addEventListener('notificationclick', event => {
    event.notification.close();
    let targetUrl = '/chat';
    try {
        const candidate = new URL(event.notification?.data?.url || '/chat', self.location.origin);
        if (candidate.origin === self.location.origin && candidate.pathname === '/chat') targetUrl = candidate.href;
    } catch { /* Use the authenticated chat page for an invalid destination. */ }

    event.waitUntil((async () => {
        const clientsList = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
        for (const client of clientsList) {
            if (new URL(client.url).origin !== self.location.origin) continue;
            if ('focus' in client) {
                await client.focus();
                if ('navigate' in client) await client.navigate(targetUrl);
                return;
            }
        }
        if (self.clients.openWindow) await self.clients.openWindow(targetUrl);
    })());
});
