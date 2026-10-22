export function initializeNavigation(document, window) {
    const header = document.querySelector('.nc-header');
    const toggle = document.getElementById('navigation-toggle');
    const navigation = document.getElementById('account-navigation');
    if (!header || !toggle || !navigation) return;
    const mobile = window.matchMedia('(max-width: 960px)');
    const setOpen = (open, restoreFocus = false) => {
        header.toggleAttribute('data-nav-open', open);
        toggle.setAttribute('aria-expanded', String(open));
        toggle.setAttribute('aria-label', open ? 'Close account navigation' : 'Open account navigation');
        if (restoreFocus) toggle.focus();
    };
    header.setAttribute('data-nav-enhanced', '');
    setOpen(false);
    toggle.addEventListener('click', () => setOpen(toggle.getAttribute('aria-expanded') !== 'true'));
    document.addEventListener('keydown', event => {
        if (event.key === 'Escape' && header.hasAttribute('data-nav-open')) setOpen(false, true);
    });
    document.addEventListener('click', event => {
        if (header.hasAttribute('data-nav-open') && !header.contains(event.target)) setOpen(false);
    });
    navigation.addEventListener('click', event => {
        if (mobile.matches && event.target.closest('a')) setOpen(false);
    });
    mobile.addEventListener('change', () => setOpen(false));
    const measureHeader = () => {
        document.documentElement.style.setProperty('--nc-header-height', header.getBoundingClientRect().height + 'px');
    };
    measureHeader();
    if (window.ResizeObserver) new window.ResizeObserver(measureHeader).observe(header);
    else window.addEventListener('resize', measureHeader);
}
