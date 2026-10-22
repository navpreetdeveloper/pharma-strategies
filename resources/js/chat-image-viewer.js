/** Delegation covers initial attachments and messages received without a page reload. */
export function initializeChatImageViewer(document, window) {
    const dialog = document.createElement('dialog');
    dialog.className = 'nc-image-viewer';
    dialog.setAttribute('aria-label', 'Shared image');
    const toolbar = document.createElement('div');
    toolbar.className = 'nc-image-viewer-toolbar';
    const name = document.createElement('span');
    name.className = 'nc-image-viewer-name';
    const download = document.createElement('a');
    download.className = 'nc-btn-secondary';
    download.textContent = 'Download';
    const close = document.createElement('button');
    close.type = 'button';
    close.className = 'nc-btn-secondary';
    close.textContent = 'Close';
    const stage = document.createElement('div');
    stage.className = 'nc-image-viewer-stage';
    const image = document.createElement('img');
    const error = document.createElement('p');
    error.textContent = 'This image is unavailable.';
    error.hidden = true;
    error.setAttribute('role', 'status');
    stage.append(image, error);
    toolbar.append(name, download, close);
    dialog.append(toolbar, stage);
    document.body.append(dialog);

    const dismiss = () => { if (dialog.open) dialog.close(); };
    close.addEventListener('click', dismiss);
    dialog.addEventListener('click', event => { if (event.target === dialog) dismiss(); });
    dialog.addEventListener('close', () => { image.removeAttribute('src'); });
    image.addEventListener('error', () => { image.hidden = true; error.hidden = false; });
    window.addEventListener('popstate', dismiss);
    window.addEventListener('pharma:close-image', dismiss);

    document.addEventListener('click', event => {
        const link = event.target.closest?.('[data-attachment-preview-link]');
        if (!link || event.button > 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        if (!dialog.showModal) return;
        const url = new URL(link.href, window.location.href);
        if (url.origin !== window.location.origin) return;
        event.preventDefault();
        const title = link.querySelector('img')?.alt || 'Shared image';
        name.textContent = title;
        image.alt = title;
        image.hidden = false;
        error.hidden = true;
        const source = link.closest('.nc-attachment')?.querySelector('.nc-attachment-download');
        download.hidden = !source;
        if (source) { download.href = source.href; download.setAttribute('download', title); }
        dialog.showModal();
        image.src = url.href;
        close.focus();
    });
}
