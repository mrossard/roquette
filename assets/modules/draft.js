/**
 * Handles saving and restoring channel message drafts in sessionStorage.
 */

export function getActiveChannelSlug() {
    const badge = document.getElementById('mercure-status');
    return badge ? badge.getAttribute('data-active-channel-slug') : null;
}

export function restoreDraftForActiveChannel() {
    const slug = getActiveChannelSlug();
    if (!slug) return;
    const draft = sessionStorage.getItem('draft:' + slug);
    const textarea = document.getElementById('message');
    if (draft && textarea) {
        textarea.value = draft;
        textarea.dispatchEvent(new Event('input', { bubbles: true }));
    }
}

export function clearDraftForActiveChannel() {
    const slug = getActiveChannelSlug();
    if (slug) {
        sessionStorage.removeItem('draft:' + slug);
    }
}

export function initDraftPersistence() {
    document.addEventListener('input', (e) => {
        if (e.target.id !== 'message') return;
        const slug = getActiveChannelSlug();
        if (!slug) return;
        const text = e.target.value;
        if (text.trim()) {
            sessionStorage.setItem('draft:' + slug, text);
        } else {
            sessionStorage.removeItem('draft:' + slug);
        }
    });
}
