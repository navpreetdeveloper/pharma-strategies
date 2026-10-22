function sameKey(stored, expected) {
    if (!stored) return true; // Older browsers may not expose subscription options.
    const a = ArrayBuffer.isView(stored) ? new Uint8Array(stored.buffer, stored.byteOffset, stored.byteLength) : new Uint8Array(stored);
    const b = new Uint8Array(expected);
    return a.length === b.length && a.every((value, index) => value === b[index]);
}

export async function getPushSubscription(manager, key, {now = Date.now(), renew = false, replaced = async () => {}} = {}) {
    let subscription = await manager.getSubscription();
    if (subscription && (renew || (subscription.expirationTime != null && subscription.expirationTime <= now)
        || !sameKey(subscription.options?.applicationServerKey, key))) {
        const previous = subscription;
        if (!await previous.unsubscribe()) throw new Error('Could not refresh notifications. Try again.');
        subscription = null;
        // Browser renewal must still succeed during a transient cleanup outage.
        try { await replaced(previous); } catch { /* A later provider expiry also removes the stale row. */ }
    }
    return subscription || manager.subscribe({userVisibleOnly: true, applicationServerKey: key});
}
