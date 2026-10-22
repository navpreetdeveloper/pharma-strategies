export function getNotificationSupport(window) {
    if (!window.isSecureContext) {
        return {available: false, message: 'Device notifications are available on the secure website. You can still view updates here.'};
    }
    if (!('Notification' in window)) {
        return {available: false, message: 'This browser does not support device notifications. You can still view updates here.'};
    }
    return {available: true, message: ''};
}
