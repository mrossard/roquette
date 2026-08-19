import { trapFocus } from './modal.js';

/**
 * Ouvre la modale de recherche globale et place le focus dans l'input.
 */
export function openGlobalSearch() {
    const modal = document.getElementById('global-search-modal');
    const input = document.getElementById('global-search-input');
    if (modal && input) {
        modal.showModal();
        trapFocus(modal);
        input.focus();
        input.select();
    }
}

/**
 * Ferme la modale de recherche globale et restaure le focus sur le champ de message.
 */
export function closeGlobalSearch() {
    const modal = document.getElementById('global-search-modal');
    if (modal && modal.open) {
        modal.close();
        const messageInput = document.getElementById('message');
        const isMobile = window.matchMedia('(max-width: 1024px)').matches && window.matchMedia('(pointer: coarse)').matches;
        if (messageInput && !isMobile) messageInput.focus();
    }
}

/**
 * Initialise les raccourcis (Ctrl+K, Echap) et écouteurs de la modale de recherche globale.
 */
export function initGlobalSearch() {
    const modal = document.getElementById('global-search-modal');
    modal?.addEventListener('click', (e) => {
        if (e.target.id === 'global-search-modal') {
            closeGlobalSearch();
        }
    });

    document.addEventListener('keydown', (e) => {
        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
            e.preventDefault();
            openGlobalSearch();
        }
        if (e.key === 'Escape') {
            if (modal && modal.open) {
                closeGlobalSearch();
            }
        }
    });

    document.body.addEventListener('htmx:beforeRequest', (evt) => {
        const elt = evt.detail.elt;
        if (elt) {
            // If the element is a link inside the search results, close the modal
            if (elt.tagName === 'A' && elt.closest('#global-search-results')) {
                closeGlobalSearch();
                return;
            }
            // If the request originates from within the global search modal (form, input, buttons), do not close the modal
            if (elt.closest('#global-search-modal')) {
                return;
            }
        }

        // If the target of the swap is the search results, do not close the modal
        if (evt.detail.target && (evt.detail.target.id === 'global-search-results' || evt.detail.target.closest('#global-search-modal'))) {
            return;
        }

        // For all other page requests, close the search modal
        closeGlobalSearch();
    });
}

/**
 * Réinitialise les filtres de recherche de canal et relance la requête HTMX.
 */
export function clearSearchFilters() {
    const input = document.getElementById('channel-search-input');
    if (input) {
        input.value = '';
    }
    const form = document.getElementById('channel-filters-form');
    if (form && window.htmx) {
        const url = form.getAttribute('hx-get');
        if (url) {
            window.htmx.ajax('GET', url, {
                target: form.getAttribute('hx-target') || '#live-feed',
                swap: 'innerHTML',
            });
        }
    }
}

// Delegated member search filtering inside the channel members modal
document.addEventListener('input', (e) => {
    if (e.target.id === 'member-search-input') {
        const query = e.target.value.toLowerCase().trim();
        const items = document.querySelectorAll('.modal-member-item');
        items.forEach(item => {
            const username = item.getAttribute('data-username') || '';
            const name = item.getAttribute('data-name') || '';
            if (username.includes(query) || name.includes(query)) {
                item.style.display = 'flex';
            } else {
                item.style.display = 'none';
            }
        });
    }
});
