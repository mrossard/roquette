/**
 * Piège le focus à l'intérieur d'un élément <dialog> pour l'accessibilité.
 *
 * @param {HTMLDialogElement} dialog
 */
export function trapFocus(dialog) {
    const selector = 'a[href], button:not([disabled]), textarea:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), [tabindex]:not([tabindex="-1"])';

    function getFocusable() {
        return Array.from(dialog.querySelectorAll(selector))
            .filter(el => el.offsetParent !== null);
    }

    function onKeydown(e) {
        if (e.key !== 'Tab') return;

        const focusable = getFocusable();
        if (focusable.length === 0) return;

        const first = focusable[0];
        const last = focusable[focusable.length - 1];

        if (e.shiftKey && document.activeElement === first) {
            e.preventDefault();
            last.focus();
        } else if (!e.shiftKey && document.activeElement === last) {
            e.preventDefault();
            first.focus();
        }
    }

    dialog.addEventListener('keydown', onKeydown);
    dialog.addEventListener('close', () => {
        dialog.removeEventListener('keydown', onKeydown);
    }, { once: true });
}

/**
 * Ouvre une boîte de dialogue modale et piège le focus.
 *
 * @param {string} modalId
 * @param {HTMLElement|null} trigger
 */
export function openModal(modalId, trigger = null) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal._triggerElement = trigger || document.activeElement;
        modal.showModal();
        trapFocus(modal);

        // Auto-focus first input or button inside the modal
        const focusEl = modal.querySelector('input[type="text"], input[type="search"], select, textarea, [autofocus]');
        if (focusEl) {
            setTimeout(() => focusEl.focus(), 50);
        }
    }
}

/**
 * Ferme une boîte de dialogue modale.
 *
 * @param {string} modalId
 */
export function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal && modal.open) {
        modal.close();
    }
}

/**
 * Affiche une boîte de confirmation personnalisée réutilisant #custom-confirm-dialog.
 *
 * @param {string} message
 * @param {Function} callback
 * @param {object} options
 */
export function showCustomConfirm(message, callback, options = {}) {
    const dialog = document.getElementById('custom-confirm-dialog');
    if (!dialog) {
        if (confirm(message)) {
            callback();
        }
        return;
    }

    const iconEl = document.getElementById('confirm-dialog-icon');
    const titleEl = document.getElementById('confirm-dialog-title');
    const messageEl = document.getElementById('confirm-dialog-message');
    const cancelBtn = document.getElementById('confirm-dialog-cancel');
    const okBtn = document.getElementById('confirm-dialog-ok');

    titleEl.className = 'confirmation-title';
    okBtn.className = 'btn-confirm-action';

    if (options.variant === 'danger' || options.variant === 'delete') {
        iconEl.textContent = options.icon || '🗑️';
        titleEl.textContent = options.title || (window.trans ? window.trans('Supprimer ?') : 'Supprimer ?');
        okBtn.textContent = options.okText || (window.trans ? window.trans('Supprimer') : 'Supprimer');
    } else if (options.variant === 'warning') {
        iconEl.textContent = options.icon || '🚪';
        titleEl.textContent = options.title || (window.trans ? window.trans('Quitter ?') : 'Quitter ?');
        okBtn.textContent = options.okText || (window.trans ? window.trans('Quitter') : 'Quitter');
        titleEl.classList.add('warning-type');
        okBtn.classList.add('warning-type');
    } else {
        iconEl.textContent = options.icon || '❓';
        titleEl.textContent = options.title || (window.trans ? window.trans('Confirmer ?') : 'Confirmer ?');
        okBtn.textContent = options.okText || (window.trans ? window.trans('Confirmer') : 'Confirmer');
        titleEl.classList.add('info-type');
        okBtn.classList.add('info-type');
    }

    messageEl.textContent = message;

    dialog.showModal();

    okBtn.onclick = () => {
        dialog.close();
        callback();
    };

    cancelBtn.onclick = () => {
        dialog.close();
    };
}

/**
 * Affiche une alerte personnalisée via #custom-alert-dialog.
 *
 * @param {string} message
 * @param {string} title
 * @param {string} icon
 * @param {Function|null} onCloseCallback
 */
export function showCustomAlert(message, title = 'Attention', icon = '⚠️', onCloseCallback = null) {
    const dialog = document.getElementById('custom-alert-dialog');
    if (!dialog) {
        alert(message);
        if (onCloseCallback) onCloseCallback();
        return;
    }

    const iconEl = document.getElementById('alert-dialog-icon');
    const titleEl = document.getElementById('alert-dialog-title');
    const messageEl = document.getElementById('alert-dialog-message');
    const okBtn = document.getElementById('alert-dialog-ok');

    iconEl.textContent = icon;
    titleEl.textContent = title;
    messageEl.textContent = message;

    titleEl.className = 'confirmation-title info-type';
    okBtn.className = 'btn-confirm-action info-type';

    dialog.showModal();

    okBtn.onclick = () => {
        dialog.close();
        if (onCloseCallback) {
            onCloseCallback();
        }
    };
}

/**
 * Intercepte les événements HTMX confirm et les formulaires avec l'attribut data-confirm.
 */
export function initConfirmModals() {
    // Intercept HTMX confirm requests (only when hx-confirm is actually set)
    document.body.addEventListener('htmx:confirm', function (evt) {
        if (!evt.detail.question) return;
        evt.preventDefault();
        showCustomConfirm(evt.detail.question, function () {
            evt.detail.issueRequest(true);
        });
    });

    // Intercept standard form submissions with data-confirm
    document.addEventListener('submit', function (evt) {
        const form = evt.target;
        if (form.hasAttribute('data-confirm')) {
            const message = form.getAttribute('data-confirm');
            if (!form.dataset.confirmed) {
                evt.preventDefault();
                showCustomConfirm(message, function () {
                    form.dataset.confirmed = 'true';
                    form.submit();
                });
            } else {
                delete form.dataset.confirmed;
            }
        }
    });
}

/**
 * Ouvre le lightbox natif (dialog) pour une image externe (URL directe).
 * Réutilise #image-lightbox s'il existe déjà dans le DOM, sinon le crée.
 *
 * @param {string} url
 */
export function openExternalImageLightbox(url) {
    let dialog = document.getElementById('image-lightbox');
    if (!dialog) {
        dialog = document.createElement('dialog');
        dialog.id = 'image-lightbox';
        dialog.className = 'modal-backdrop-dialog';
        dialog.setAttribute('role', 'dialog');
        dialog.setAttribute('aria-modal', 'true');
        dialog.setAttribute('aria-label', window.trans ? window.trans("Aperçu de l'image") : "Aperçu de l'image");
        document.body.appendChild(dialog);

        // Fermer en cliquant sur le backdrop
        dialog.addEventListener('click', (e) => {
            if (e.target === dialog) closeModal('image-lightbox');
        });
    }

    // Build safely via DOM API to prevent XSS from injected URLs
    dialog.innerHTML = '';
    const content = document.createElement('div');
    content.className = 'lightbox-content';

    const closeBtn = document.createElement('button');
    closeBtn.type = 'button';
    closeBtn.className = 'btn-close-lightbox';
    closeBtn.setAttribute('aria-label', window.trans ? window.trans("Fermer l'aperçu") : "Fermer l'aperçu");
    closeBtn.innerHTML = '&times;';
    closeBtn.addEventListener('click', () => closeModal('image-lightbox'));

    const img = document.createElement('img');
    img.setAttribute('src', url);
    img.setAttribute('alt', 'Image');

    const caption = document.createElement('div');
    caption.className = 'lightbox-caption';

    const link = document.createElement('a');
    link.className = 'btn-lightbox-download';
    link.setAttribute('href', url);
    link.setAttribute('target', '_blank');
    link.setAttribute('rel', 'noopener noreferrer');
    link.textContent = window.trans ? window.trans('Ouvrir') : 'Ouvrir';

    caption.appendChild(link);
    content.appendChild(closeBtn);
    content.appendChild(img);
    content.appendChild(caption);
    dialog.appendChild(content);

    trapFocus(dialog);
    dialog.showModal();
}

// Automatically show modal dialogs when loaded dynamically via HTMX
document.addEventListener('htmx:afterSwap', (e) => {
    if (e.detail.target) {
        let dialog = null;
        if (e.detail.target.id === 'modal-container') {
            dialog = e.detail.target.querySelector('dialog');
        } else if (e.detail.target.tagName === 'DIALOG') {
            dialog = e.detail.target;
        } else if (e.detail.target.classList && e.detail.target.classList.contains('modal-backdrop-dialog')) {
            dialog = e.detail.target;
        }

        if (dialog && !dialog.open) {
            dialog.showModal();
            trapFocus(dialog);
            const focusEl = dialog.querySelector('input[type="text"], input[type="search"], select, textarea, [autofocus]');
            if (focusEl) {
                setTimeout(() => focusEl.focus(), 50);
            }
        }
    }
});

// Modal tab switching support via data-modal-tab
document.addEventListener('click', (e) => {
    const tabBtn = e.target.closest('[data-modal-tab]');
    if (!tabBtn) return;

    const modal = tabBtn.closest('dialog') || tabBtn.closest('.modal-content');
    if (!modal) return;
    const targetTab = tabBtn.dataset.modalTab;

    modal.querySelectorAll('[data-modal-tab]').forEach(btn => {
        const isSelected = btn === tabBtn;
        btn.setAttribute('aria-selected', isSelected ? 'true' : 'false');
        btn.classList.toggle('active', isSelected);
    });

    modal.querySelectorAll('[data-modal-tab-panel]').forEach(panel => {
        const isMatch = panel.dataset.modalTabPanel === targetTab;
        panel.style.display = isMatch ? 'flex' : 'none';
        panel.classList.toggle('active', isMatch);
    });

    const saveBtn = modal.querySelector('.btn-save-settings');
    if (saveBtn) {
        if (targetTab === 'danger') {
            saveBtn.style.visibility = 'hidden';
            saveBtn.style.pointerEvents = 'none';
        } else {
            saveBtn.style.visibility = 'visible';
            saveBtn.style.pointerEvents = 'auto';
        }
    }

    const activeTabInput = modal.querySelector('#edit-modal-active-tab');
    if (activeTabInput) {
        activeTabInput.value = targetTab;
    }
});

// Modal tab keyboard navigation (left/right arrows)
document.addEventListener('keydown', (e) => {
    const activeTab = document.activeElement?.closest?.('[data-modal-tab]');
    if (!activeTab || (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft')) return;

    const nav = activeTab.closest('[role="tablist"]');
    if (!nav) return;

    const tabs = Array.from(nav.querySelectorAll('[data-modal-tab]'));
    const index = tabs.indexOf(activeTab);
    if (index === -1) return;

    e.preventDefault();
    const nextIndex = e.key === 'ArrowRight'
        ? (index + 1) % tabs.length
        : (index - 1 + tabs.length) % tabs.length;
    tabs[nextIndex].focus();
    tabs[nextIndex].click();
});

// Auto-clean modal-container on close event
document.addEventListener('close', (e) => {
    if (e.target.tagName === 'DIALOG' && e.target.closest('#modal-container')) {
        setTimeout(() => {
            const container = document.getElementById('modal-container');
            if (container) container.innerHTML = '';
        }, 100);
    }
}, true); // Capture phase is required because the close event does not bubble

// Global delegated click handler for modal backdrop closing
document.addEventListener('click', (e) => {
    if (e.target.tagName === 'DIALOG' && e.target.classList.contains('modal-backdrop-dialog')) {
        closeModal(e.target.id);
    }
});

// Global close listener to restore focus on dialog close
document.addEventListener('close', (e) => {
    if (e.target.tagName === 'DIALOG') {
        const modal = e.target;
        if (modal._triggerElement && typeof modal._triggerElement.focus === 'function') {
            modal._triggerElement.focus();
            modal._triggerElement = null;
        }
    }
}, true); // Capture phase is required because the close event does not bubble
