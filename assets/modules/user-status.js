/**
 * Ferme tous les menus déroulants de statut utilisateur.
 */
export function closeAllStatusDropdowns() {
    document.querySelectorAll('details.user-status-dropdown-container').forEach(c => {
        c.removeAttribute('open');
    });
}

function handleDropdownKeyDown(e) {
    const container = e.currentTarget;
    const menu = container.querySelector('.user-status-dropdown-menu');
    if (!menu || menu.style.display === 'none') return;

    const options = Array.from(menu.querySelectorAll('.user-status-option'));
    const index = options.indexOf(document.activeElement);

    if (e.key === 'Escape') {
        closeAllStatusDropdowns();
        const trigger = container.querySelector('.user-status-trigger');
        if (trigger) trigger.focus();
        e.preventDefault();
        e.stopPropagation();
    } else if (e.key === 'ArrowDown') {
        const nextIndex = (index + 1) % options.length;
        options[nextIndex].focus();
        e.preventDefault();
        e.stopPropagation();
    } else if (e.key === 'ArrowUp') {
        const prevIndex = (index - 1 + options.length) % options.length;
        options[prevIndex].focus();
        e.preventDefault();
        e.stopPropagation();
    } else if (e.key === 'Tab') {
        closeAllStatusDropdowns();
    }
}

/**
 * Met à jour le statut d'un élément (pastilles, overlay, labels) dans le DOM.
 *
 * @param {HTMLElement} element
 * @param {string} status ('online' | 'offline' | 'busy' | 'away' etc.)
 * @param {string} label
 */
export function updateElementStatus(element, status, label) {
    if (element.classList.contains('status-dot') || element.classList.contains('status-dot-overlay')) {
        const expectedClass = element.classList.contains('status-dot') ? 'status-dot ' + status : 'status-dot-overlay ' + status;
        if (element.className !== expectedClass) {
            element.className = expectedClass;
        }
        if (element.getAttribute('title') !== label) {
            element.setAttribute('title', label);
        }
    }

    // Find matching container
    const container = element.closest('.user-status-selector-container, .user-status-dropdown-container, .avatar-container, .member-card, .feed-item-user-link');
    if (container) {
        const overlay = container.querySelector('.status-dot-overlay');
        if (overlay && overlay !== element) {
            const expectedOverlayClass = 'status-dot-overlay ' + status;
            if (overlay.className !== expectedOverlayClass) {
                overlay.className = expectedOverlayClass;
            }
            if (overlay.getAttribute('title') !== label) {
                overlay.setAttribute('title', label);
            }
        }

        const dot = container.querySelector('.status-dot');
        if (dot && dot !== element) {
            const expectedDotClass = 'status-dot ' + status;
            if (dot.className !== expectedDotClass) {
                dot.className = expectedDotClass;
            }
            if (dot.getAttribute('title') !== label) {
                dot.setAttribute('title', label);
            }
        }

        const textLabel = container.querySelector('.status-label');
        if (textLabel && textLabel.textContent !== label) {
            textLabel.textContent = label;
        }

        // Update active class on dropdown option if inside dropdown
        const dropdown = container.closest('.user-status-dropdown-container');
        if (dropdown) {
            const statusOverride = dropdown.querySelector('.status-dot')?.getAttribute('data-status-override') || 'auto';
            const options = dropdown.querySelectorAll('.user-status-option');
            options.forEach(opt => {
                const val = opt.getAttribute('data-status-value');
                const isSelected = val === statusOverride;
                if (isSelected && !opt.classList.contains('active')) {
                    opt.classList.add('active');
                } else if (!isSelected && opt.classList.contains('active')) {
                    opt.classList.remove('active');
                }
            });
        }
    }
}

// Automatically close details dropdowns when clicking outside or when selecting an option
document.addEventListener('click', (e) => {
    const details = e.target.closest('details.user-status-dropdown-container');
    if (!details || e.target.closest('.user-status-option')) {
        closeAllStatusDropdowns();
    }
});

// Handle details elements toggle event to auto-focus active option and register key bindings
document.addEventListener('toggle', (e) => {
    if (e.target.tagName === 'DETAILS' && e.target.classList.contains('user-status-dropdown-container')) {
        if (e.target.open) {
            // Close other details dropdowns
            document.querySelectorAll('details.user-status-dropdown-container').forEach(other => {
                if (other !== e.target) {
                    other.removeAttribute('open');
                }
            });

            // Focus the active or first option
            const menu = e.target.querySelector('.user-status-dropdown-menu');
            if (menu) {
                const activeOpt = menu.querySelector('.user-status-option.active') || menu.querySelector('.user-status-option');
                if (activeOpt) {
                    setTimeout(() => activeOpt.focus(), 50);
                }
            }

            e.target.addEventListener('keydown', handleDropdownKeyDown);
        } else {
            e.target.removeEventListener('keydown', handleDropdownKeyDown);
        }
    }
}, true); // Capture phase is required because toggle event does not bubble

// Sync aria-expanded on all details/summary toggles
document.addEventListener('toggle', (e) => {
    if (e.target.tagName === 'DETAILS') {
        const summary = e.target.querySelector(':scope > summary');
        if (summary) {
            summary.setAttribute('aria-expanded', e.target.open ? 'true' : 'false');
        }
    }
}, true);

// Inactivity check: automatically sets users to offline if no activity for 5 minutes
setInterval(() => {
    const now = Math.floor(Date.now() / 1000);
    document.querySelectorAll('[data-last-active]').forEach(el => {
        const override = el.getAttribute('data-status-override');
        if (override && override !== 'auto' && override !== '') {
            return;
        }
        const lastActive = parseInt(el.getAttribute('data-last-active'), 10);
        const offlineLabel = window.trans ? window.trans('status.offline') : 'Hors ligne';
        const onlineLabel = window.trans ? window.trans('status.online') : 'En ligne';

        if (!lastActive) {
            updateElementStatus(el, 'offline', offlineLabel);
            return;
        }
        if (now - lastActive > 300) {
            updateElementStatus(el, 'offline', offlineLabel);
        } else {
            updateElementStatus(el, 'online', onlineLabel);
        }
    });
}, 15000);
