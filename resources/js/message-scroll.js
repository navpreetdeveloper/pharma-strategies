export function initializeMessageScroll(box, input, window) {
    const lifecycle = new AbortController();
    let pinned = true;
    let paused = false;
    let frame = null;
    const nearLatest = () => box.scrollHeight - box.scrollTop - box.clientHeight < 100;
    const cancelFrame = () => { if (frame !== null) window.cancelAnimationFrame(frame); frame = null; };
    const settle = () => {
        if (paused || !pinned) return;
        cancelFrame();
        frame = window.requestAnimationFrame(() => {
            frame = null;
            if (!paused && pinned) box.scrollTop = box.scrollHeight;
        });
    };
    const toLatest = () => {
        if (paused) return;
        pinned = true;
        box.scrollTop = box.scrollHeight;
        settle();
    };
    const options = {signal: lifecycle.signal, passive: true};
    box.addEventListener('scroll', () => { if (frame === null && !paused) pinned = nearLatest(); }, options);
    // User gestures take priority over delayed media/layout settling.
    box.addEventListener('touchstart', cancelFrame, options);
    box.addEventListener('wheel', cancelFrame, options);
    box.addEventListener('load', settle, {signal: lifecycle.signal, capture: true});
    box.addEventListener('loadedmetadata', settle, {signal: lifecycle.signal, capture: true});
    input?.addEventListener('focus', toLatest, options);
    input?.addEventListener('input', settle, options);
    window.addEventListener('resize', settle, options);
    window.visualViewport?.addEventListener('resize', settle, options);
    window.visualViewport?.addEventListener('scroll', settle, options);
    const observer = window.ResizeObserver ? new window.ResizeObserver(settle) : null;
    observer?.observe(box);
    if (input) observer?.observe(input);
    toLatest();
    return {
        toLatest,
        pause() { paused = true; cancelFrame(); },
        resume() { paused = false; pinned = nearLatest(); },
        dispose() { lifecycle.abort(); observer?.disconnect(); cancelFrame(); },
    };
}
