import Sortable from 'sortablejs';

/**
 * Met à jour la date/heure du dernier message affichée sur un lien de canal.
 *
 * @param {string} channelSlug
 */
export function updateChannelLastMessageDate(channelSlug) {
    const channelLink = document.querySelector(`.channel-link[data-channel-slug="${channelSlug}"]`);
    if (!channelLink) return;

    const now = new Date();
    const hours = String(now.getHours()).padStart(2, '0');
    const minutes = String(now.getMinutes()).padStart(2, '0');
    const day = String(now.getDate()).padStart(2, '0');
    const month = String(now.getMonth() + 1).padStart(2, '0');
    const year = now.getFullYear();
    const formattedTime = `${hours}:${minutes}`;
    const fullDateTime = `${day}/${month}/${year} ${hours}:${minutes}`;

    let dateSpan = channelLink.querySelector('.channel-last-message-date');
    if (!dateSpan) {
        dateSpan = document.createElement('span');
        dateSpan.className = 'channel-last-message-date';
        const badge = channelLink.querySelector('.unread-badge');
        if (badge) {
            channelLink.insertBefore(dateSpan, badge);
        } else {
            channelLink.appendChild(dateSpan);
        }
    }
    dateSpan.textContent = formattedTime;
    dateSpan.title = `${window.trans ? window.trans('Dernier message :') : 'Dernier message :'} ${fullDateTime}`;
}

let sortableInstances = [];

function preventNavigation(e) {
    e.preventDefault();
    e.stopPropagation();
}

function enforceChannelHierarchy() {
    const lists = document.querySelectorAll('.channel-list[data-list-type]');
    lists.forEach(list => {
        const parentLinks = Array.from(list.querySelectorAll('.channel-link:not(.subchannel-link)'));
        const subchannelLinks = Array.from(list.querySelectorAll('.channel-link.subchannel-link'));

        parentLinks.forEach(parentEl => {
            const parentId = parentEl.getAttribute('data-channel-id');
            if (!parentId) return;

            const childSubchannels = subchannelLinks.filter(sub => sub.getAttribute('data-parent-channel-id') === parentId);

            let referenceEl = parentEl;
            childSubchannels.forEach(subEl => {
                if (referenceEl.nextSibling !== subEl) {
                    referenceEl.parentNode.insertBefore(subEl, referenceEl.nextSibling);
                }
                referenceEl = subEl;
            });
        });
    });
}

function saveChannelOrder() {
    const allLinks = document.querySelectorAll('.channel-link[data-channel-id]');
    const order = Array.from(allLinks).map(el => parseInt(el.getAttribute('data-channel-id'), 10));

    if (window.htmx) {
        window.htmx.ajax('POST', '/channels/reorder', {
            values: { 'order[]': order },
            swap: 'none'
        });
    }
}

/**
 * Initialise le glisser-déposer pour la réorganisation des canaux dans la barre latérale.
 */
export function initChannelReordering() {
    const sidebarPanel = document.querySelector('.sidebar-panel');
    const toggleBtn = document.getElementById('btn-edit-order-trigger');
    const lists = document.querySelectorAll('.channel-list[data-list-type]');

    if (!sidebarPanel || !toggleBtn || !lists.length) return;

    // Clean up any existing instances
    sortableInstances.forEach(inst => inst.destroy());
    sortableInstances = [];

    // Initialize Sortable on each channel list
    lists.forEach(list => {
        const sortable = new Sortable(list, {
            animation: 150,
            draggable: '.channel-group, #section-favorites > .channel-link:not(.subchannel-link)',
            disabled: !sidebarPanel.classList.contains('reorder-active'),
            ghostClass: 'dragging-ghost',
            onEnd: () => {
                enforceChannelHierarchy();
                saveChannelOrder();
            }
        });
        sortableInstances.push(sortable);
    });

    // Remove any existing click listener by cloning the button (to avoid multiple registrations)
    const newToggleBtn = toggleBtn.cloneNode(true);
    toggleBtn.parentNode.replaceChild(newToggleBtn, toggleBtn);

    // Initial check for cleanup (reset link drag states)
    const links = document.querySelectorAll('.channel-link');
    links.forEach(link => {
        link.removeEventListener('click', preventNavigation, true);
    });
    lists.forEach(list => {
        Array.from(list.children).forEach(child => {
            child.draggable = false;
        });
    });

    newToggleBtn.addEventListener('click', () => {
        const isActive = sidebarPanel.classList.toggle('reorder-active');
        newToggleBtn.classList.toggle('reorder-active-btn', isActive);
        const iconSpan = newToggleBtn.querySelector('.option-icon');
        if (iconSpan) {
            iconSpan.textContent = isActive ? '✔️' : '⇅';
        } else {
            newToggleBtn.textContent = isActive ? '✔️' : '⇅';
        }
        newToggleBtn.title = isActive ? (window.trans ? window.trans('Terminer l\'organisation') : 'Terminer l\'organisation') : (window.trans ? window.trans('Ordonner les canaux') : 'Ordonner les canaux');

        if (isActive) {
            lists.forEach(list => {
                Array.from(list.children).forEach(child => {
                    child.draggable = true;
                });
            });
            const currentLinks = document.querySelectorAll('.channel-link');
            currentLinks.forEach(link => {
                link.addEventListener('click', preventNavigation, true);
            });
        } else {
            lists.forEach(list => {
                Array.from(list.children).forEach(child => {
                    child.draggable = false;
                });
            });
            const currentLinks = document.querySelectorAll('.channel-link');
            currentLinks.forEach(link => {
                link.removeEventListener('click', preventNavigation, true);
            });
        }

        // Toggle Sortable instances disabled state
        sortableInstances.forEach(inst => {
            inst.option('disabled', !isActive);
        });
    });
}

/**
 * Initialise le filtre pour n'afficher que les canaux ayant des messages non lus.
 */
export function initUnreadFilter() {
    const filterBtn = document.getElementById('btn-filter-unread');
    const sidebarPanel = document.querySelector('.sidebar-panel');
    if (!filterBtn || !sidebarPanel) return;

    // Check saved filter state
    const isFiltered = localStorage.getItem('filterUnreadOnly') === 'true';
    if (isFiltered) {
        sidebarPanel.classList.add('filter-unread-active');
        filterBtn.classList.add('filter-unread-active-btn');
    } else {
        sidebarPanel.classList.remove('filter-unread-active');
        filterBtn.classList.remove('filter-unread-active-btn');
    }

    // Bind event listener
    const newFilterBtn = filterBtn.cloneNode(true);
    filterBtn.parentNode.replaceChild(newFilterBtn, filterBtn);

    newFilterBtn.addEventListener('click', () => {
        const active = sidebarPanel.classList.toggle('filter-unread-active');
        newFilterBtn.classList.toggle('filter-unread-active-btn', active);
        localStorage.setItem('filterUnreadOnly', active ? 'true' : 'false');
    });
}

function getActiveChannelSlug() {
    const badge = document.getElementById('mercure-status');
    return badge ? badge.getAttribute('data-active-channel-slug') : null;
}

/**
 * Initialise le filtre pour masquer les tâches terminées (todo list) dans le canal actif.
 */
export function initHideCompletedTasks() {
    const btn = document.querySelector('.btn-hide-completed');
    const feed = document.getElementById('live-feed');
    if (!btn || !feed) return;

    const slug = btn.getAttribute('data-channel-slug') || getActiveChannelSlug();
    if (!slug) {
        console.error("Could not find active channel slug for todo filter");
        return;
    }

    const storageKey = `hide-completed-tasks:${slug}`;
    const isHidden = localStorage.getItem(storageKey) === 'true';

    if (isHidden) {
        feed.classList.add('hide-todo-completed');
        btn.classList.add('active');
    } else {
        feed.classList.remove('hide-todo-completed');
        btn.classList.remove('active');
    }

    if (btn.dataset.listenerBound === 'true') return;
    btn.dataset.listenerBound = 'true';

    btn.addEventListener('click', () => {
        const active = feed.classList.toggle('hide-todo-completed');
        btn.classList.toggle('active', active);
        localStorage.setItem(storageKey, active ? 'true' : 'false');
    });
}

/**
 * Restaure l'état replié/déplié des sections de la barre latérale.
 */
export function initSidebarToggles() {
    document.querySelectorAll('details.sidebar-section-details').forEach(details => {
        const sectionName = details.getAttribute('data-section');
        const stored = localStorage.getItem(`roquette-section-${sectionName}-collapsed`);
        if (stored !== null) {
            if (stored === 'true') {
                details.removeAttribute('open');
            } else {
                details.setAttribute('open', '');
            }
        }
    });
}

// Watch user clicks on summary to save state in localStorage
document.addEventListener('click', (e) => {
    const summary = e.target.closest('summary');
    if (!summary) return;
    const details = summary.parentElement;
    if (details && details.tagName === 'DETAILS' && details.classList.contains('sidebar-section-details')) {
        const sectionName = details.getAttribute('data-section');
        if (sectionName) {
            setTimeout(() => {
                localStorage.setItem(`roquette-section-${sectionName}-collapsed`, !details.open ? 'true' : 'false');
            }, 0);
        }
    }
});

/**
 * Initialise le comportement de la barre latérale sur mobile (ouverture/fermeture et backdrop).
 */
export function initMobileSidebar() {
    document.addEventListener('click', (e) => {
        const toggleBtn = e.target.closest('#mobile-sidebar-toggle');
        if (toggleBtn) {
            const dashboardGrid = document.querySelector('.dashboard-grid');
            if (dashboardGrid) {
                const isOpen = dashboardGrid.classList.toggle('sidebar-open');
                toggleBtn.classList.toggle('active', isOpen);
            }
            return;
        }

        const backdrop = e.target.closest('#mobile-sidebar-backdrop');
        if (backdrop) {
            const dashboardGrid = document.querySelector('.dashboard-grid');
            const toggleBtn = document.getElementById('mobile-sidebar-toggle');
            if (dashboardGrid) {
                dashboardGrid.classList.remove('sidebar-open');
            }
            if (toggleBtn) {
                toggleBtn.classList.remove('active');
            }
            return;
        }

        // Close sidebar when clicking a channel link on mobile
        const channelLink = e.target.closest('.sidebar-panel .channel-link, .sidebar-panel .btn-sidebar-item');
        if (channelLink && window.innerWidth < 768) {
            const dashboardGrid = document.querySelector('.dashboard-grid');
            const toggleBtn = document.getElementById('mobile-sidebar-toggle');
            if (dashboardGrid) {
                dashboardGrid.classList.remove('sidebar-open');
            }
            if (toggleBtn) {
                toggleBtn.classList.remove('active');
            }
        }
    });
}

/**
 * Affiche ou masque les détails de l'en-tête du canal sur mobile.
 */
export function toggleMobileChannelDetails() {
    const details = document.getElementById('chat-header-details');
    const btn = document.getElementById('btn-channel-details-toggle');
    if (details) {
        details.classList.toggle('show');
        if (btn) {
            btn.classList.toggle('active');
        }
    }
}

/**
 * Initialise l'état du volet des sous-canaux.
 */
export function initSubChannelsSidebar() {
    const panel = document.getElementById('subchannels-sidebar-panel');
    const grid = document.getElementById('main-content');
    if (panel && grid) {
        const isOpen = localStorage.getItem('subchannels_sidebar_open') === 'true';
        if (isOpen) {
            panel.style.display = 'flex';
            panel.classList.add('open');
            grid.classList.add('show-subchannels');
        } else {
            panel.style.display = 'none';
            panel.classList.remove('open');
            grid.classList.remove('show-subchannels');
        }
    }
}

/**
 * Bascule l'affichage du volet des sous-canaux.
 */
export function toggleSubChannelsSidebar() {
    const panel = document.getElementById('subchannels-sidebar-panel');
    const grid = document.getElementById('main-content');
    if (panel && grid) {
        if (panel.style.display === 'none' || panel.style.display === '') {
            panel.style.display = 'flex';
            panel.classList.add('open');
            grid.classList.add('show-subchannels');
            localStorage.setItem('subchannels_sidebar_open', 'true');

            // Close files sidebar if open
            const filesPanel = document.getElementById('files-sidebar-panel');
            if (filesPanel) {
                filesPanel.style.display = 'none';
                filesPanel.classList.remove('open');
                grid.classList.remove('show-files');
                localStorage.setItem('files_sidebar_open', 'false');
            }
        } else {
            panel.style.display = 'none';
            panel.classList.remove('open');
            grid.classList.remove('show-subchannels');
            localStorage.setItem('subchannels_sidebar_open', 'false');
        }
    }
}

/**
 * Initialise l'état du volet des fichiers du canal.
 */
export function initFilesSidebar() {
    const panel = document.getElementById('files-sidebar-panel');
    const grid = document.getElementById('main-content');
    if (panel && grid) {
        const isOpen = localStorage.getItem('files_sidebar_open') === 'true';
        if (isOpen) {
            panel.style.display = 'flex';
            panel.classList.add('open');
            grid.classList.add('show-files');
            const contentContainer = document.getElementById('files-sidebar-list-container');
            if (contentContainer && !contentContainer.querySelector('[data-loaded="true"]')) {
                if (window.htmx) window.htmx.trigger(contentContainer, 'load-files');
            }
        } else {
            panel.style.display = 'none';
            panel.classList.remove('open');
            grid.classList.remove('show-files');
        }
    }
}

/**
 * Bascule l'affichage du volet des fichiers du canal.
 */
export function toggleFilesSidebar() {
    const filesPanel = document.getElementById('files-sidebar-panel');
    const subchannelsPanel = document.getElementById('subchannels-sidebar-panel');
    const grid = document.getElementById('main-content');
    if (filesPanel && grid) {
        const isOpening = filesPanel.style.display === 'none' || filesPanel.style.display === '';

        // Close other sidebar
        if (subchannelsPanel) {
            subchannelsPanel.style.display = 'none';
            subchannelsPanel.classList.remove('open');
            grid.classList.remove('show-subchannels');
            localStorage.setItem('subchannels_sidebar_open', 'false');
        }

        if (isOpening) {
            filesPanel.style.display = 'flex';
            filesPanel.classList.add('open');
            grid.classList.add('show-files');
            localStorage.setItem('files_sidebar_open', 'true');
            const contentContainer = document.getElementById('files-sidebar-list-container');
            if (contentContainer && !contentContainer.querySelector('[data-loaded="true"]')) {
                if (window.htmx) window.htmx.trigger(contentContainer, 'load-files');
            }
        } else {
            filesPanel.style.display = 'none';
            filesPanel.classList.remove('open');
            grid.classList.remove('show-files');
            localStorage.setItem('files_sidebar_open', 'false');
        }
    }
}

/**
 * Filtre les fichiers affichés dans le volet par catégorie.
 *
 * @param {string} category ('all' | 'images' | 'documents' | 'audio' | 'video')
 */
export function filterFiles(category) {
    document.querySelectorAll('.files-sidebar-tab').forEach(tab => {
        if (tab.getAttribute('data-tab') === category) {
            tab.classList.add('active');
        } else {
            tab.classList.remove('active');
        }
    });

    document.querySelectorAll('.files-sidebar-item').forEach(item => {
        if (category === 'all' || item.getAttribute('data-category') === category) {
            item.style.display = 'flex';
        } else {
            item.style.display = 'none';
        }
    });
}
