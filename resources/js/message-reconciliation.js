/** Repair current rows without reviving tombstones or interrupting unchanged media. */
export function reconcileMessageRows({box, seen, messages, removed, buildRow, schedule}) {
    for (const id of removed) {
        box.querySelector('[data-message-id="' + Number(id) + '"]')?.remove();
        seen.add(String(id));
    }
    for (const message of messages) {
        const row = box.querySelector('[data-message-id="' + Number(message.id) + '"]');
        if (!row || (row.querySelector('.nc-edit-form') && !message.deleted_at)) continue;
        if (row.classList.contains('is-deleted') && !message.deleted_at) continue;
        if (!message.deleted_at && row.dataset.editedAt && (!message.edited_at || Date.parse(message.edited_at) < Date.parse(row.dataset.editedAt))) continue;
        if (message.revision && row.dataset.revision === message.revision) continue;
        const next = buildRow(message);
        row.replaceWith(next);
        schedule(next);
    }
}
