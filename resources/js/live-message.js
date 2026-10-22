/** Render an authorized open-thread message before scheduling sidebar work. */
export async function handleIncomingMessage(notification, {conversationId, receive, deliver, refresh}) {
    const data = notification.data || {};
    try {
        if (data.message_id && conversationId != null && Number(conversationId) === Number(data.conversation_id)) {
            const message = await receive(data.message_id);
            if (message) deliver(data.conversation_id, message);
        }
    } catch {
        // The bounded catch-up request repairs a failed live read.
    } finally {
        refresh(notification);
    }
}
