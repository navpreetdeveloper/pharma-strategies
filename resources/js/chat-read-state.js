export function initializeChatReadState(document, window, box, config, signal) {
    let acknowledged = Math.max(0, ...Array.from(box.querySelectorAll('[data-message-id]'), row => Number(row.dataset.messageId)));
    let pending = false;
    const mark = async () => {
        if (!config.readUrl || pending || signal.aborted || document.visibilityState !== 'visible' || box.scrollHeight - box.scrollTop - box.clientHeight > 140) return;
        const ids = Array.from(box.querySelectorAll('[data-message-id]'), row => Number(row.dataset.messageId));
        const latest = Math.max(0, ...ids.filter(id => config.recoveryCursor == null || id <= config.recoveryCursor));
        if (latest <= acknowledged) return;
        pending = true;
        let success = false;
        try {
            const response = await window.fetch(config.readUrl, {
                method: 'POST', credentials: 'same-origin', signal,
                headers: {Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || ''},
                body: JSON.stringify({last_message_id: latest}),
            });
            if (response.ok) { acknowledged = latest; success = true; window.dispatchEvent(new CustomEvent('pharma:chat-list-refresh')); }
        } catch { /* Retry on the next message, scroll or return to the tab. */ }
        finally { pending = false; }
        if (success) await mark();
    };
    document.addEventListener('visibilitychange', mark, {signal});
    box.addEventListener('scroll', mark, {signal, passive: true});
    return mark;
}
