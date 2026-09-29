import './bootstrap';
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import Swal from 'sweetalert2';

window.Swal = Swal;

window.Pusher = Pusher;

const config = window.PharmaStrategiesConfig || {};

function createEcho() {
    const meta = (name) => document.querySelector(`meta[name="${name}"]`)?.content || '';
    const pageSecure = window.location.protocol === 'https:';
    const reverbKey = meta('reverb-app-key') || import.meta.env.VITE_REVERB_APP_KEY || 'pharma-strategies-local-key';
    const reverbHost = meta('reverb-host') || import.meta.env.VITE_REVERB_HOST || window.location.hostname;
    const configuredPort = Number(meta('reverb-port') || import.meta.env.VITE_REVERB_PORT || 0);
    const reverbTLS = (meta('reverb-scheme') || import.meta.env.VITE_REVERB_SCHEME || (pageSecure ? 'https' : 'http')) === 'https';
    const reverbPort = configuredPort || (reverbTLS ? 443 : 8081);

    if (!reverbKey) {
        console.warn('Pharma Strategies: Reverb app key is not configured.');
        return null;
    }

    return new Echo({
        broadcaster: 'reverb',
        key: reverbKey,
        wsHost: reverbHost,
        wsPort: reverbTLS ? 80 : reverbPort,
        wssPort: reverbTLS ? 443 : reverbPort,
        forceTLS: reverbTLS,
        enabledTransports: reverbTLS ? ['wss'] : ['ws'],
        authEndpoint: '/broadcasting/auth',
        auth: {
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || '',
            },
        },
    });
}

// The public Reverb endpoint is runtime configuration. Reading it from meta
// tags avoids baking Render's private environment into the Vite build.
window.Echo = createEcho();

window.PharmaStrategies = {
    listenConversation(id, callback) {
        if (!window.Echo) return null;
        return window.Echo.private(`conversation.${id}`).listen('.message.sent', callback);
    },
    listenConversationEdits(id, callback) {
        if (!window.Echo) return null;
        return window.Echo.private(`conversation.${id}`).listen('.message.edited', callback);
    },
    listenConversationDeletes(id, callback) {
        if (!window.Echo) return null;
        return window.Echo.private(`conversation.${id}`).listen('.message.deleted', callback);
    },
};

const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content;
const api = async (url, options = {}) => fetch(url, {
    credentials: 'same-origin',
    headers: {
        'Accept': 'application/json',
        'X-CSRF-TOKEN': csrf(),
        ...(options.body && !(options.body instanceof FormData) ? {'Content-Type': 'application/json'} : {}),
        ...(options.headers || {}),
    },
    ...options,
});

function urlBase64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
    const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    const rawData = window.atob(base64);
    return Uint8Array.from([...rawData].map(char => char.charCodeAt(0)));
}

async function getServiceWorkerRegistration() {
    if (!('serviceWorker' in navigator)) {
        throw new Error('This browser does not support service workers.');
    }
    if (!window.isSecureContext) {
        throw new Error('Device notifications require HTTPS, or localhost/127.0.0.1 during local development.');
    }

    await navigator.serviceWorker.register('/sw.js', {scope: '/'});
    return navigator.serviceWorker.ready;
}

async function getRegistration() {
    if (!window.PharmaStrategiesConfig?.pushPublicKey) {
        throw new Error('No VAPID public key is available. Check VAPID_PUBLIC_KEY in your .env file and run php artisan optimize:clear.');
    }
    return getServiceWorkerRegistration();
}

async function enablePushNotifications({requestPermission = true} = {}) {
    const pushConfig = window.PharmaStrategiesConfig;
    const isIOS = /iphone|ipad|ipod/i.test(navigator.userAgent)
        || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    const isStandalone = window.matchMedia('(display-mode: standalone)').matches
        || window.navigator.standalone === true;

    if (isIOS && !isStandalone) {
        throw new Error('On iPhone or iPad, install Pharma Strategies from Safari using Share → Add to Home Screen before enabling notifications.');
    }
    if (!('Notification' in window)) throw new Error('This browser does not support notifications.');

    // Notification permission itself enables the OS-level fallback while the
    // authenticated Pharma Strategies page is open. When VAPID/Web Push is
    // configured, the same device is also registered for background push.
    const permission = Notification.permission === 'default' && requestPermission
        ? await Notification.requestPermission()
        : Notification.permission;
    if (permission !== 'granted') {
        if (permission === 'denied') {
            throw new Error('Notifications are blocked for this site. Allow notifications in the browser site settings and reload.');
        }
        throw new Error('Notification permission was not granted.');
    }

    if (!pushConfig?.pushEnabled || !pushConfig?.pushPublicKey || !('PushManager' in window)) {
        return null;
    }

    const registration = await getRegistration();
    if (!registration?.pushManager) throw new Error('The push manager is unavailable in this browser.');

    let subscription = await registration.pushManager.getSubscription();
    if (!subscription) {
        let applicationServerKey;
        try {
            applicationServerKey = urlBase64ToUint8Array(pushConfig.pushPublicKey);
        } catch (_) {
            throw new Error('The VAPID public key is not valid Base64URL. Generate a valid VAPID key pair and update .env.');
        }
        subscription = await registration.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey,
        });
    }

    const response = await api(pushConfig.routes.subscribe, {
        method: 'POST',
        body: JSON.stringify({
            subscription: subscription.toJSON(),
            device_name: `${navigator.platform || 'Device'} · ${navigator.userAgent.includes('Mobile') ? 'Mobile' : 'Desktop'}`,
        }),
    });
    if (!response.ok) {
        let detail = `The device notification subscription could not be saved (HTTP ${response.status}).`;
        try {
            const payload = await response.json();
            detail = payload.message || (payload.errors ? Object.values(payload.errors).flat().join(' ') : detail);
        } catch (_) {}
        throw new Error(detail);
    }

    return subscription;
}
async function syncGrantedPushSubscription() {
    if (!window.PharmaStrategiesConfig?.pushEnabled || !window.PharmaStrategiesConfig?.pushPublicKey) return;
    if (!('Notification' in window) || Notification.permission !== 'granted') return;

    try {
        // Permission was already granted by the employee, so this can repair
        // a missing/stale server subscription without another browser prompt.
        await enablePushNotifications({requestPermission: false});
        const enable = document.getElementById('enable-push');
        const status = document.getElementById('notification-status');
        if (enable) {
            enable.disabled = false;
            enable.textContent = 'Notifications enabled';
        }
        if (status) {
            status.textContent = 'This device is connected to workplace notifications.';
            status.classList.remove('hidden');
        }
    } catch (error) {
        // Do not interrupt the application when background repair fails. The
        // employee can retry explicitly from the notification menu.
        console.warn('Pharma Strategies: push subscription sync failed.', error);
    }
}

async function loadNotifications() {
    const appConfig = window.PharmaStrategiesConfig;
    const list = document.getElementById('notification-list');
    const count = document.getElementById('notification-count');
    if (!appConfig || !list || !count) return;

    try {
        const response = await api(appConfig.routes.notifications);
        const payload = await response.json().catch(() => ({}));
        if (!response.ok) {
            throw new Error(payload.message || (payload.errors ? Object.values(payload.errors).flat().join(' ') : 'Unable to load notifications.'));
        }

        const unread = Number(payload.unread_count || 0);
        count.textContent = unread > 99 ? '99+' : String(unread);
        count.classList.toggle('hidden', unread === 0);

        if (!payload.notifications?.length) {
            list.innerHTML = '<div class="p-5 text-sm nc-muted nc-notification-empty">No notifications yet.</div>';
            return;
        }

        list.innerHTML = payload.notifications.map((n) => {
            const conversation = n.data?.conversation_id;
            const href = conversation ? `/chat?conversation=${encodeURIComponent(conversation)}` : '/chat';
            return `<a href="${href}" class="nc-notification-item ${n.read_at ? '' : 'unread'}" data-notification-id="${n.id}">
                <div class="nc-notification-title"></div>
                <div class="nc-notification-body"></div>
                <div class="nc-notification-time"></div>
            </a>`;
        }).join('');

        payload.notifications.forEach((n, index) => {
            const item = list.children[index];
            if (!item) return;
            item.querySelector('.nc-notification-title').textContent = n.title || 'Notification';
            item.querySelector('.nc-notification-body').textContent = n.body || '';
            item.querySelector('.nc-notification-time').textContent = n.created_at
                ? new Date(n.created_at).toLocaleString([], {dateStyle:'medium', timeStyle:'short'})
                : '';
            item.addEventListener('click', async () => {
                if (!n.read_at) await api(`${appConfig.routes.readBase}/${encodeURIComponent(n.id)}/read`, {method:'PATCH'}).catch(() => {});
            });
        });
    } catch (error) {
        list.innerHTML = '';
        const errorBox = document.createElement('div');
        errorBox.className = 'p-5 text-sm text-red-700';
        errorBox.textContent = error.message || 'Unable to load notifications.';
        list.appendChild(errorBox);
    }
}

let deferredInstallPrompt = null;
window.addEventListener('beforeinstallprompt', event => {
    event.preventDefault();
    deferredInstallPrompt = event;
    document.getElementById('install-app')?.classList.remove('hidden');
});
window.addEventListener('appinstalled', () => {
    deferredInstallPrompt = null;
    document.getElementById('install-app')?.classList.add('hidden');
});

function showInAppWorkplaceToast(notification) {
    const data = notification?.data || {};
    const sender = data.sender_name || 'A colleague';
    const preview = data.message_preview || notification?.body || 'You have a new workplace message.';
    const conversationId = data.conversation_id;
    const senderInitials = data.sender_initials || sender.split(/\s+/).filter(Boolean).slice(0, 2).map(part => part[0]).join('').toUpperCase() || 'PS';
    const timeLabel = notification?.created_at
        ? new Date(notification.created_at).toLocaleTimeString([], {hour: 'numeric', minute: '2-digit'})
        : 'Now';

    let host = document.getElementById('pharma-live-notifications');
    if (!host) {
        host = document.createElement('div');
        host.id = 'pharma-live-notifications';
        host.className = 'nc-live-notifications';
        host.setAttribute('aria-live', 'polite');
        host.setAttribute('aria-atomic', 'false');
        document.body.appendChild(host);
    }

    // Keep the notification stack compact, just like a professional workplace
    // messaging client. Older toasts are dismissed when the stack gets large.
    while (host.children.length >= 4) host.lastElementChild?.remove();

    const toast = document.createElement('div');
    toast.className = 'nc-live-notification';
    toast.setAttribute('role', 'status');
    toast.innerHTML = `
        <button type="button" class="nc-live-notification-main" aria-label="Open new message">
            <span class="nc-live-notification-avatar" aria-hidden="true"></span>
            <span class="nc-live-notification-copy">
                <span class="nc-live-notification-topline">
                    <strong></strong>
                    <small></small>
                </span>
                <span class="nc-live-notification-preview"></span>
            </span>
        </button>
        <button type="button" class="nc-live-notification-close" aria-label="Dismiss notification">×</button>
        <span class="nc-live-notification-progress" aria-hidden="true"></span>`;

    toast.querySelector('.nc-live-notification-avatar').textContent = senderInitials;
    toast.querySelector('.nc-live-notification-topline strong').textContent = sender;
    toast.querySelector('.nc-live-notification-topline small').textContent = timeLabel;
    toast.querySelector('.nc-live-notification-preview').textContent = preview;

    const openConversation = async () => {
        const notificationId = data.notification_id;
        const appConfig = window.PharmaStrategiesConfig;
        if (notificationId && appConfig?.routes?.readBase) {
            await api(`${appConfig.routes.readBase}/${encodeURIComponent(notificationId)}/read`, {method: 'PATCH'}).catch(() => {});
        }
        if (conversationId) {
            window.location.href = `/chat?conversation=${encodeURIComponent(conversationId)}`;
        } else {
            document.getElementById('notification-button')?.click();
        }
    };

    toast.querySelector('.nc-live-notification-main').addEventListener('click', openConversation);
    toast.querySelector('.nc-live-notification-close').addEventListener('click', event => {
        event.stopPropagation();
        dismissToast(toast);
    });
    host.prepend(toast);

    const timeoutId = window.setTimeout(() => dismissToast(toast), 6000);
    toast.dataset.timeoutId = String(timeoutId);
}

function dismissToast(toast) {
    if (!toast || toast.dataset.dismissed === '1') return;
    toast.dataset.dismissed = '1';
    const timeoutId = Number(toast.dataset.timeoutId || 0);
    if (timeoutId) window.clearTimeout(timeoutId);
    toast.classList.add('is-leaving');
    window.setTimeout(() => toast.remove(), 220);
}

function incrementLiveNotificationCount() {
    const count = document.getElementById('notification-count');
    if (!count) return;
    const current = Number.parseInt(count.textContent || '0', 10) || 0;
    const next = current + 1;
    count.textContent = next > 99 ? '99+' : String(next);
    count.classList.remove('hidden');
}

function prependLiveNotificationToCentre(notification) {
    const list = document.getElementById('notification-list');
    if (!list) return;
    const data = notification?.data || {};
    const id = data.notification_id || `live-${data.message_id || Date.now()}`;
    if (list.querySelector(`[data-notification-id="${CSS.escape(String(id))}"]`)) return;
    list.querySelector('.nc-notification-empty')?.remove();
    const conversation = data.conversation_id;
    const href = conversation ? `/chat?conversation=${encodeURIComponent(conversation)}` : '/chat';
    const item = document.createElement('a');
    item.href = href;
    item.className = 'nc-notification-item unread';
    item.dataset.notificationId = String(id);
    const title = document.createElement('div'); title.className = 'nc-notification-title'; title.textContent = notification.title || 'New workplace message';
    const body = document.createElement('div'); body.className = 'nc-notification-body'; body.textContent = notification.body || `${data.sender_name || 'A colleague'} sent you a new message`;
    const time = document.createElement('div'); time.className = 'nc-notification-time'; time.textContent = 'Just now';
    item.append(title, body, time);
    item.addEventListener('click', async () => {
        if (data.notification_id && window.PharmaStrategiesConfig?.routes?.readBase) {
            await api(`${window.PharmaStrategiesConfig.routes.readBase}/${encodeURIComponent(data.notification_id)}/read`, {method:'PATCH'}).catch(() => {});
        }
    });
    list.prepend(item);
    while (list.children.length > 25) list.lastElementChild?.remove();
}

async function showLocalWorkplaceNotification(notification) {
    if (!('Notification' in window) || Notification.permission !== 'granted') return;
    const data = notification?.data || {};
    const conversationId = data.conversation_id;
    const sender = data.sender_name || 'A colleague';
    const messageId = data.message_id ? String(data.message_id) : '';
    try {
        const registration = await getServiceWorkerRegistration();
        if (!registration) return;
        const subscription = await registration.pushManager?.getSubscription();
        if (subscription) return; // The server Web Push path handles subscribed devices.
        await registration.showNotification(`${sender} · Pharma Strategies`, {
            body: data.message_preview || notification.body || 'You have a new workplace message.',
            icon: '/icons/icon-192.png',
            badge: '/icons/icon-192.png',
            tag: messageId ? `message-${messageId}` : (conversationId ? `conversation-${conversationId}` : 'pharma-strategies'),
            renotify: true,
            requireInteraction: false,
            data: {
                url: conversationId ? `/chat?conversation=${encodeURIComponent(conversationId)}` : '/chat',
                conversation_id: conversationId || null,
                message_id: messageId || null,
            },
        });
    } catch (_) {}
}

function initialiseNotifications() {
    const button = document.getElementById('notification-button');
    const menu = document.getElementById('notification-menu');
    const markAll = document.getElementById('mark-all-notifications');
    const clearAll = document.getElementById('clear-notifications');
    const enable = document.getElementById('enable-push');
    const status = document.getElementById('notification-status');
    const installButton = document.getElementById('install-app');
    if (!button || !menu) return;
    if (enable) { enable.classList.remove('hidden'); if (!window.PharmaStrategiesConfig?.pushPublicKey) enable.title = 'Web Push is not configured on this server yet.'; }

    button.addEventListener('click', async () => {
        const open = !menu.classList.contains('hidden');
        menu.classList.toggle('hidden', open);
        button.setAttribute('aria-expanded', String(!open));
        if (!open) await loadNotifications();
    });

    document.addEventListener('click', event => {
        if (!document.getElementById('notification-wrap')?.contains(event.target)) {
            menu.classList.add('hidden');
            button.setAttribute('aria-expanded', 'false');
        }
    });

    markAll?.addEventListener('click', async () => {
        markAll.disabled = true;
        try {
            await api(window.PharmaStrategiesConfig.routes.readAll, {method:'POST'});
            await loadNotifications();
        } finally { markAll.disabled = false; }
    });

    clearAll?.addEventListener('click', async () => {
        const result = window.Swal ? await window.Swal.fire({
            icon: 'warning', title: 'Clear notifications?',
            text: 'This removes your notification history from this notification centre. It does not delete chat messages.',
            showCancelButton: true, confirmButtonText: 'Clear notifications', cancelButtonText: 'Keep notifications',
            reverseButtons: true, buttonsStyling: false,
            customClass: {popup:'nc-swal-popup', title:'nc-swal-title', htmlContainer:'nc-swal-text', confirmButton:'nc-swal-confirm danger', cancelButton:'nc-swal-cancel'}
        }) : {isConfirmed: window.confirm('Clear all notifications?')};
        if (!result.isConfirmed) return;
        clearAll.disabled = true;
        try {
            const response = await api(window.PharmaStrategiesConfig.routes.clear, {method:'DELETE'});
            if (!response.ok) throw new Error('Notifications could not be cleared.');
            await loadNotifications();
        } catch (error) {
            if (window.Swal) await window.Swal.fire({icon:'error', title:'Unable to clear notifications', text:error.message || 'Please try again.'});
        } finally { clearAll.disabled = false; }
    });

    document.getElementById('install-app')?.addEventListener('click', async () => {
        const button = document.getElementById('install-app');
        if (deferredInstallPrompt) {
            deferredInstallPrompt.prompt();
            await deferredInstallPrompt.userChoice;
            deferredInstallPrompt = null;
            button?.classList.add('hidden');
            return;
        }

        // iOS does not expose beforeinstallprompt. The supported installation
        // path is Safari's Share → Add to Home Screen flow.
        const isIOS = /iphone|ipad|ipod/i.test(navigator.userAgent)
            || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
        const isStandalone = window.matchMedia('(display-mode: standalone)').matches
            || window.navigator.standalone === true;

        if (isIOS && !isStandalone) {
            const message = 'On iPhone or iPad: open this site in Safari, tap Share, choose “Add to Home Screen”, then open Pharma Strategies from your Home Screen. Notifications are available after the app is installed.';
            if (window.Swal) {
                await window.Swal.fire({
                    icon: 'info',
                    title: 'Install Pharma Strategies',
                    text: message,
                    confirmButtonText: 'Got it',
                    buttonsStyling: false,
                    customClass: {popup:'nc-swal-popup', title:'nc-swal-title', htmlContainer:'nc-swal-text', confirmButton:'nc-swal-confirm'}
                });
            } else {
                window.alert(message);
            }
        }
    });

    const syncPushPermissionState = () => {
        const isIOS = /iphone|ipad|ipod/i.test(navigator.userAgent)
            || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
        const isStandalone = window.matchMedia('(display-mode: standalone)').matches
            || window.navigator.standalone === true;

        if (installButton && isIOS && !isStandalone) {
            installButton.classList.remove('hidden');
        }

        if (!enable || !('Notification' in window)) {
            if (enable && isIOS && !isStandalone) {
                enable.disabled = false;
                enable.textContent = 'Install app to enable notifications';
            }
            return;
        }
        if (Notification.permission === 'denied') {
            enable.disabled = true;
            enable.textContent = 'Notifications blocked in browser';
            status?.classList.remove('hidden');
            if (status) status.textContent = 'Allow notifications for this site in your browser settings, then reload the page.';
            return;
        }
        if (Notification.permission === 'granted' && window.PharmaStrategiesConfig?.pushEnabled) {
            enable.textContent = 'Checking notifications…';
        }
    };
    syncPushPermissionState();

    enable?.addEventListener('click', async (event) => {
        event.preventDefault();
        enable.disabled = true;
        if (status) {
            status.textContent = 'Connecting this device to workplace notifications…';
            status.classList.remove('hidden');
        }
        try {
            const subscription = await enablePushNotifications();
            enable.textContent = subscription ? 'Notifications enabled' : 'Notifications allowed';
            if (status) status.textContent = subscription
                ? 'This device is connected to workplace push notifications.'
                : 'Notifications are enabled for this browser. Server push is not configured on this environment.';
            if (window.Swal) {
                await window.Swal.fire({
                    icon: 'success',
                    title: 'Notifications enabled',
                    text: 'This device can now receive Pharma Strategies notifications.',
                    confirmButtonText: 'Done',
                });
            }
        } catch (error) {
            const message = error?.message || 'Unable to enable notifications.';
            if (status) status.textContent = message;
            if (window.Swal) {
                await window.Swal.fire({
                    icon: 'error',
                    title: 'Notifications could not be enabled',
                    text: message,
                    confirmButtonText: 'OK',
                });
            }
            enable.disabled = Notification.permission === 'denied';
        }
    });

    getRegistration().catch(() => {});
    loadNotifications();
    syncGrantedPushSubscription();

    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') {
            syncGrantedPushSubscription();
            loadNotifications().catch(() => {});
        }
    });
}

let workplaceNotificationChannel = null;

function initialiseRealtimeNotifications() {
    const userId = window.PharmaStrategiesConfig?.userId;
    if (!userId || !window.Echo?.private || workplaceNotificationChannel) return;

    // Subscribe independently from the notification dropdown UI. A user should
    // receive a live toast even if the dropdown is closed or its API refresh is
    // slow/unavailable.
    workplaceNotificationChannel = window.Echo.private(`App.Models.User.${userId}`);
    workplaceNotificationChannel
        .listen('.workplace.notification', notification => {
            // The toast is deliberately rendered FIRST. Do not await the
            // notification-centre API here; Slack-style alerts must feel
            // instantaneous and must not be blocked by a database/API request.
            showInAppWorkplaceToast(notification);
            incrementLiveNotificationCount();
            prependLiveNotificationToCentre(notification);
            window.dispatchEvent(new CustomEvent('pharma:notification', {detail: notification}));

            // The UI is updated immediately. A background sync reconciles the
            // durable record without ever delaying the live alert.
            loadNotifications().catch(() => {});

            if (document.visibilityState !== 'visible' && Notification.permission === 'granted') {
                showLocalWorkplaceNotification(notification).catch(() => {});
            }
        });
}

function initialisePresence() {
    const presenceConfig = window.PharmaStrategiesConfig;
    if (!presenceConfig?.companyId || !presenceConfig?.routes?.heartbeat || !window.Echo?.join) return;

    const heartbeat = () => fetch(presenceConfig.routes.heartbeat, {
        method: 'POST',
        credentials: 'same-origin',
        keepalive: true,
        headers: {'Accept': 'application/json', 'X-CSRF-TOKEN': csrf()},
    }).catch(() => {});

    heartbeat();
    window.setInterval(heartbeat, 30000);
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') heartbeat();
    });
    window.addEventListener('pagehide', heartbeat);

    const presence = window.PharmaStrategiesPresence || (window.PharmaStrategiesPresence = window.Echo.join(`company.${presenceConfig.companyId}.presence`));
    presence
        .here(users => users.forEach(user => window.dispatchEvent(new CustomEvent('pharma:presence', {detail:{type:'here', user}}))))
        .joining(user => window.dispatchEvent(new CustomEvent('pharma:presence', {detail:{type:'joining', user}})))
        .leaving(user => window.dispatchEvent(new CustomEvent('pharma:presence', {detail:{type:'leaving', user}})));
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-history-back]').forEach(link => {
        link.addEventListener('click', event => {
            try {
                const referrer = document.referrer ? new URL(document.referrer) : null;
                if (referrer && referrer.origin === window.location.origin && window.history.length > 1) {
                    event.preventDefault();
                    window.history.back();
                }
            } catch (_) {}
        });
    });
    initialiseNotifications();
    initialiseRealtimeNotifications();
    initialisePresence();
});
