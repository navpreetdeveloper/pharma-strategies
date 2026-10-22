export function initializeChatViewport(document, window) {
    if (!document.querySelector('.nc-chat-layout')) return;
    const update = () => {
        const height = window.visualViewport?.height || window.innerHeight;
        const top = window.visualViewport?.offsetTop || 0;
        document.documentElement.style.setProperty('--nc-viewport-top', Math.floor(top) + 'px');
        document.documentElement.style.setProperty('--nc-viewport-height', Math.floor(height) + 'px');
    };
    update();
    window.addEventListener('resize', update);
    window.visualViewport?.addEventListener('resize', update);
    window.visualViewport?.addEventListener('scroll', update);
}
