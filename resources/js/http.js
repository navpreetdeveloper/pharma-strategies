/** Returns a Response so callers can distinguish validation, access and transport errors. */
export function createHttpClient(window, document) {
    return (url, options = {}) => {
        const {headers = {}, ...request} = options;
        return window.fetch(url, {
            ...request,
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                ...(request.body && !(request.body instanceof window.FormData) ? {'Content-Type': 'application/json'} : {}),
                ...headers,
            },
        });
    };
}
