import { initializePageUi } from './page-ui';
import { initializeSessionAccess } from './session-access';
import { createMessageReceiver } from './message-receiver';
import { handleIncomingMessage } from './live-message';
import { getPushSubscription } from './push-subscription';
import { initializeLogout } from './logout';
import { initializeChatImageViewer } from './chat-image-viewer';
import { createHttpClient } from './http';
import { initializeChatViewport } from './chat-viewport';
import { getNotificationSupport } from './notification-support';
import { initializeChatNavigation } from './chat-navigation';
import { initializeEmployeeEditors } from './employee-editors';
import { isImportantNotification } from './notification-policy';
import { initializePresenceState } from './presence-state';
import { initializePresenceHeartbeat } from './presence-heartbeat';

document.addEventListener('DOMContentLoaded', () => initializeChatImageViewer(document, window), {once: true});
initializePageUi(document, window);
initializeChatViewport(document, window);
document.addEventListener('DOMContentLoaded', () => initializeChatNavigation(document, window), {once: true});
document.addEventListener('DOMContentLoaded', () => initializeEmployeeEditors(document, window), {once: true});
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
window.Pusher = Pusher;

const config = window.PharmaStrategiesConfig || {};
const reverbKey = import.meta.env.VITE_REVERB_APP_KEY || 'pharma-strategies-local-key';
const configuredReverbHost = import.meta.env.VITE_REVERB_HOST;
const reverbHost = !configuredReverbHost || ['127.0.0.1', 'localhost', '::1'].includes(configuredReverbHost)
    ? window.location.hostname : configuredReverbHost;
const reverbPort = Number(import.meta.env.VITE_REVERB_PORT || 8081);
const reverbTLS = (import.meta.env.VITE_REVERB_SCHEME || 'http') === 'https';

window.Echo = config.userId ? new Echo({
    broadcaster: 'reverb',
    key: reverbKey,
    wsHost: reverbHost,
    wsPort: reverbPort,
    wssPort: reverbPort,
    forceTLS: reverbTLS,
    enabledTransports: reverbTLS ? ['wss'] : ['ws'],
    authEndpoint: '/broadcasting/auth',
    auth: {
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || '',
        },
    },
}) : null;

let workspaceRevoked = false;
const readAuthorizedMessage = createMessageReceiver(window, () => revokeWorkspace(), () => workspaceRevoked);
async function receiveAuthorizedMessage(event, callback) {
    const message = await readAuthorizedMessage(event.id);
    if (message && !workspaceRevoked) callback(message);
}
function revokeWorkspace() {
    workspaceRevoked = true;
    window.dispatchEvent(new CustomEvent('pharma:workspace-revoked'));
    window.Swal?.close();
    window.dispatchEvent(new CustomEvent('pharma:close-image'));
    window.Echo?.disconnect();
    document.getElementById('messages')?.replaceChildren();
    document.getElementById('composer')?.querySelectorAll('input,button').forEach(node=>node.disabled=true);
    if (!document.getElementById('workspace-revoked')) {
        const banner=document.createElement('p');banner.id='workspace-revoked';banner.className='nc-alert';banner.setAttribute('role','alert');
        banner.textContent='Your workspace access changed. Sign out and contact your administrator.';
        document.querySelector('main')?.replaceChildren(banner);
        document.getElementById('notification-list')?.replaceChildren();
        document.getElementById('pharma-live-notifications')?.replaceChildren();
        document.getElementById('forward-modal')?.remove();
    }
}

window.PharmaStrategies = {
    listenConversation(id, callback) {
        return window.Echo?.private(`conversation.${id}`).listen('.message.sent', event=>receiveAuthorizedMessage(event,callback).catch(()=>{}));
    },
    listenConversationEdits(id, callback) {
        return window.Echo?.private(`conversation.${id}`).listen('.message.edited', event=>receiveAuthorizedMessage(event,callback).catch(()=>{}));
    },
    listenConversationDeletes(id, callback) {
        return window.Echo?.private(`conversation.${id}`).listen('.message.deleted', callback);
    },
};

const api = createHttpClient(window, document);

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

async function enablePushNotifications({requestPermission = true, renew = false} = {}) {
    const pushConfig = window.PharmaStrategiesConfig;
    if (!('Notification' in window)) throw new Error('This browser does not support device notifications.');

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

    let applicationServerKey;
    try {
        applicationServerKey = urlBase64ToUint8Array(pushConfig.pushPublicKey);
    } catch (_) {
        throw new Error('The VAPID public key is not valid Base64URL. Generate a valid VAPID key pair and update .env.');
    }
    const subscription = await getPushSubscription(registration.pushManager, applicationServerKey, {
        renew,
        replaced: previous => api(pushConfig.routes.unsubscribe, {method: 'POST', body: JSON.stringify({endpoint: previous.endpoint})}),
    });

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
        const enable = document.getElementById('enable-push');
        if (enable) { enable.textContent = 'Enable notifications'; enable.disabled = false; }
        const status = document.getElementById('notification-status');
        if (status) { status.textContent = 'Could not connect notifications. Try again.'; status.classList.remove('hidden'); }
    }
}

let notificationCursor=null;
async function loadNotifications(append=false) {
    const appConfig = window.PharmaStrategiesConfig;
    const list = document.getElementById('notification-list');
    const count = document.getElementById('notification-count');
    if (!appConfig || !list || !count) return;

    try {
        const response = await api(appConfig.routes.notifications+(append&&notificationCursor?'?cursor='+encodeURIComponent(notificationCursor):''));
        const payload = await response.json().catch(() => ({}));
        if (!response.ok) {
            throw new Error(payload.message || (payload.errors ? Object.values(payload.errors).flat().join(' ') : 'Unable to load notifications.'));
        }

        notificationCursor=payload.next_cursor;
        document.getElementById('older-notifications')?.classList.toggle('hidden',!notificationCursor);
        const offset=append?list.children.length:0;
        const unread = Number(payload.unread_count || 0);
        count.textContent = unread > 99 ? '99+' : String(unread);
        count.classList.toggle('hidden', unread === 0);

        if (!payload.notifications?.length) {
            list.innerHTML = '<div class="p-5 text-sm nc-muted nc-notification-empty">No notifications yet.</div>';
            return;
        }

        const markup = payload.notifications.map((n) => {
            const conversation = n.data?.conversation_id;
            const href = conversation ? `/chat?conversation=${encodeURIComponent(conversation)}` : '/chat';
            return `<a href="${href}" class="nc-notification-item ${n.read_at ? '' : 'unread'}" data-notification-id="${n.id}">
                <div class="nc-notification-title"></div>
                <div class="nc-notification-body"></div>
                <div class="nc-notification-time"></div>
            </a>`;
        }).join('');

        if(append)list.insertAdjacentHTML('beforeend',markup);else list.innerHTML=markup;
        payload.notifications.forEach((n, index) => {
            const item = list.children[offset+index];
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
        await registration.showNotification('Pharma Strategies', {
            body: 'You have a new workplace message.',
            icon: '/icons/icon-192.png',
            badge: '/icons/icon-192.png',
            tag: conversationId ? `conversation-${conversationId}` : (messageId ? `message-${messageId}` : 'pharma-strategies'),
            renotify: false,
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
    if (!button || !menu) return;
    const notificationSupport = getNotificationSupport(window);
    if (enable) enable.classList.toggle('hidden', !notificationSupport.available);
    if (!notificationSupport.available && status) {
        status.textContent = notificationSupport.message;
        status.classList.remove('hidden');
    }

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
            const response = await api(window.PharmaStrategiesConfig.routes.readAll, {method:'POST'});
            if (!response.ok) throw new Error('Notifications could not be updated.');
            await loadNotifications();
            window.PharmaFeedback?.success('Notifications marked as read.');
        } catch (error) {
            window.PharmaFeedback?.error(error.message);
        } finally { markAll.disabled = false; }
    });

    clearAll?.addEventListener('click', async () => {
        const result = await window.Swal.fire({
            icon: 'warning', title: 'Clear notifications?',
            text: 'This removes your notification history from this notification centre. It does not delete chat messages.',
            showCancelButton: true, confirmButtonText: 'Clear notifications', cancelButtonText: 'Keep notifications',
            reverseButtons: true, buttonsStyling: false,
            customClass: {popup:'nc-swal-popup', title:'nc-swal-title', htmlContainer:'nc-swal-text', confirmButton:'nc-swal-confirm danger', cancelButton:'nc-swal-cancel'}
        });
        if (!result.isConfirmed) return;
        clearAll.disabled = true;
        try {
            const response = await api(window.PharmaStrategiesConfig.routes.clear, {method:'DELETE'});
            if (!response.ok) throw new Error('Notifications could not be cleared.');
            await loadNotifications();
            window.PharmaFeedback?.success('Notifications cleared.');
        } catch (error) {
            if (window.Swal) await window.Swal.fire({icon:'error', title:'Unable to clear notifications', text:error.message || 'Please try again.'});
        } finally { clearAll.disabled = false; }
    });

    document.getElementById('install-app')?.addEventListener('click', async () => {
        if (!deferredInstallPrompt) return;
        deferredInstallPrompt.prompt();
        await deferredInstallPrompt.userChoice;
        deferredInstallPrompt = null;
        document.getElementById('install-app')?.classList.add('hidden');
    });

    const syncPushPermissionState = () => {
        if (!enable || !('Notification' in window)) return;
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
            const subscription = await enablePushNotifications({renew: true});
            enable.textContent = subscription ? 'Notifications enabled' : 'Notifications allowed';
            if (status) status.textContent = subscription
                ? 'This device is connected to workplace push notifications.'
                : 'Device notifications are enabled for this browser. Server push is not configured on this environment.';
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
            enable.disabled = !('Notification' in window) || Notification.permission === 'denied';
        }
    });

    getRegistration().catch(() => {});
    syncGrantedPushSubscription();

    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') {
            syncGrantedPushSubscription();
            if (!menu.classList.contains('hidden')) loadNotifications().catch(() => {});
        }
    });
}

let workplaceNotificationChannel = null;
const receivedNotifications = new Set();

function initialiseRealtimeNotifications() {
    const userId = window.PharmaStrategiesConfig?.userId;
    if (!userId || !window.Echo?.private || workplaceNotificationChannel) return;

    // Subscribe independently from the notification dropdown UI. A user should
    // receive a live toast even if the dropdown is closed or its API refresh is
    // slow/unavailable.
    workplaceNotificationChannel = window.Echo.private(`App.Models.User.${userId}`);
    workplaceNotificationChannel
        .listen('.workplace.notification', notification => {
            const data = notification.data || {};
            const key = data.notification_id || (data.message_id ? `message-${data.message_id}` : null);
            if (key && receivedNotifications.has(key)) return;
            if (key) { receivedNotifications.add(key); if (receivedNotifications.size > 500) receivedNotifications.delete(receivedNotifications.values().next().value); }
            const selected = document.querySelector('[data-chat-config]');
            handleIncomingMessage(notification, {
                conversationId: selected ? JSON.parse(selected.textContent).conversationId : null,
                receive: readAuthorizedMessage,
                deliver: (conversationId, message) => window.dispatchEvent(new CustomEvent('pharma:message', {detail: {conversationId, message}})),
                refresh: detail => { if (!workspaceRevoked) window.dispatchEvent(new CustomEvent('pharma:notification', {detail})); },
            });
            if (document.visibilityState !== 'visible' && 'Notification' in window && window.Notification.permission === 'granted') {
                showLocalWorkplaceNotification(notification).catch(() => {});
            }
            if (!isImportantNotification(notification)) return;
            // The toast is deliberately rendered FIRST. Do not await the
            // notification-centre API here; Slack-style alerts must feel
            // instantaneous and must not be blocked by a database/API request.
            const readingConversation = document.visibilityState === 'visible' && selected && Number(JSON.parse(selected.textContent).conversationId) === Number(data.conversation_id);
            if (!readingConversation) showInAppWorkplaceToast(notification);
            incrementLiveNotificationCount();
            prependLiveNotificationToCentre(notification);

            // The UI is updated immediately. A background sync reconciles the
            // durable record without ever delaying the live alert.
            loadNotifications().catch(() => {});

        });
}

function initialisePresence() {
    const presenceConfig = window.PharmaStrategiesConfig;
    if (presenceConfig?.presenceEnabled === false || !presenceConfig?.companyId || !presenceConfig?.routes?.heartbeat) return;

    initializePresenceHeartbeat(document, window, api);

    if (!window.Echo?.join) return;
    const presence = window.PharmaStrategiesPresence || (window.PharmaStrategiesPresence = window.Echo.join(`company.${presenceConfig.companyId}.presence`));
    presence
        .here(users => window.dispatchEvent(new CustomEvent('pharma:presence', {detail:{type:'snapshot', users}})))
        .joining(user => window.dispatchEvent(new CustomEvent('pharma:presence', {detail:{type:'joining', user}})))
        .leaving(user => window.dispatchEvent(new CustomEvent('pharma:presence', {detail:{type:'leaving', user}})));
}

document.addEventListener('DOMContentLoaded', () => {
    initialiseNotifications();
    initialiseRealtimeNotifications();
    initializePresenceState(document, window);
    initialisePresence();
});

if (config.userId) initializeSessionAccess(document, window, api, revokeWorkspace);
initializeLogout(document, window, api);

document.getElementById('older-notifications')?.addEventListener('click',async event=>{const button=event.currentTarget;button.disabled=true;try{await loadNotifications(true);}finally{button.disabled=false;}});
