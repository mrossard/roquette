/**
 * Floating toast notification system triggered by HTMX events or JavaScript.
 */
export function initToastNotifications() {
    // Listen for HTMX showToast event
    document.body.addEventListener('showToast', (evt) => {
        const detail = evt.detail;
        if (!detail || !detail.message) return;
        showToast(detail.message, detail.type || 'info');
    });

    // Expose globally
    window.showToast = showToast;
}

export function showToast(message, type = 'info') {
    let container = document.getElementById('toast-container');
    if (!container) {
        container = document.createElement('div');
        container.id = 'toast-container';
        container.className = 'toast-container';
        document.body.appendChild(container);
    }

    const toast = document.createElement('div');
    toast.className = `toast-notification toast-${type}`;
    toast.innerHTML = `<span class="toast-message">${message}</span>`;

    container.appendChild(toast);

    setTimeout(() => {
        toast.classList.add('toast-fade-out');
        setTimeout(() => {
            if (toast.parentNode) {
                toast.remove();
            }
        }, 300);
    }, 4000);
}
