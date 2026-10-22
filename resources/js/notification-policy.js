export function isImportantNotification(notification) {
    const data = notification?.data || {};
    return !data.message_id || data.important === true;
}
