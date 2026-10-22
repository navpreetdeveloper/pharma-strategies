/** Presence survives HTML replacement; neither DOM nodes nor secrets belong in state. */
export function createPresenceStore() {
    const users = new Map();
    return {
        get: id => users.get(Number(id)),
        set(id, user) { users.set(Number(id), {...user}); },
        snapshot(members) {
            users.forEach((user, id) => users.set(id, {...user, online: false}));
            members.forEach(user => users.set(Number(user.id), {...user, online: true}));
        },
    };
}
