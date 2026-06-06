const pendingScrollContainers = new Set();

export function scrollToBottom(smooth = true, containerId = 'live-feed') {
    const feedContainer = document.getElementById(containerId);
    if (!feedContainer) return;

    const isPageActive = (document.visibilityState === 'visible' && document.hasFocus());

    if (!isPageActive) {
        // Queue a scroll when the page becomes active/focused
        pendingScrollContainers.add(containerId);

        // Also do an instant scroll now, as 'auto' behavior is more reliable in the background than 'smooth'
        feedContainer.scrollTo({
            top: feedContainer.scrollHeight,
            behavior: 'auto'
        });
        return;
    }

    feedContainer.scrollTo({
        top: feedContainer.scrollHeight,
        behavior: smooth ? 'smooth' : 'auto'
    });

    // Handle images that are not loaded yet to prevent layout shifts from breaking scroll-to-bottom
    const images = feedContainer.querySelectorAll('img');
    images.forEach(img => {
        if (!img.complete && !img.dataset.scrollListener) {
            img.dataset.scrollListener = 'true';
            const handleImageLoad = () => {
                // If user is close to the bottom, scroll to the new bottom
                const threshold = 150; // pixels
                const isCloseToBottom = (feedContainer.scrollHeight - feedContainer.scrollTop - feedContainer.clientHeight) < threshold;
                if (isCloseToBottom) {
                    const currentActive = (document.visibilityState === 'visible' && document.hasFocus());
                    feedContainer.scrollTo({
                        top: feedContainer.scrollHeight,
                        behavior: currentActive ? 'smooth' : 'auto'
                    });
                }
            };
            img.addEventListener('load', handleImageLoad, { once: true });
            img.addEventListener('error', handleImageLoad, { once: true });
        }
    });
}

function triggerPendingScrolls() {
    if (document.visibilityState === 'visible' && document.hasFocus()) {
        pendingScrollContainers.forEach(containerId => {
            scrollToBottom(true, containerId);
        });
        pendingScrollContainers.clear();

        // Remove unread separators after a small delay (e.g. 3 seconds) to let user see them first
        setTimeout(() => {
            if (document.visibilityState === 'visible' && document.hasFocus()) {
                const separators = document.querySelectorAll('.unread-separator');
                separators.forEach(sep => {
                    sep.style.transition = 'opacity 0.5s ease-out';
                    sep.style.opacity = '0';
                    setTimeout(() => sep.remove(), 500);
                });
            }
        }, 3000);
    }
}

window.addEventListener('focus', triggerPendingScrolls);
document.addEventListener('visibilitychange', triggerPendingScrolls);


export function highlightAllCodeBlocks(container = document) {
    if (window.hljs) {
        const blocks = container.querySelectorAll('pre code');
        blocks.forEach(block => {
            window.hljs.highlightElement(block);
        });
    }
}

export function formatBytes(bytes, decimals = 2) {
    if (bytes === 0) return '0 B';
    const k = 1024;
    const dm = decimals < 0 ? 0 : decimals;
    const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(dm)) + ' ' + sizes[i];
}

function setupDragAndDrop(chatPanel, dragOverlay) {
    if (!chatPanel || !dragOverlay || chatPanel.classList.contains('drag-drop-bound')) return;

    chatPanel.classList.add('drag-drop-bound');
    let dragCounter = 0;

    const isDragSourceFiles = (e) => {
        if (!e.dataTransfer) return false;
        if (e.dataTransfer.types) {
            for (let i = 0; i < e.dataTransfer.types.length; i++) {
                if (e.dataTransfer.types[i] === 'Files') {
                    return true;
                }
            }
        }
        return false;
    };

    const preventDefaults = (e) => {
        e.preventDefault();
        e.stopPropagation();
    };

    ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
        chatPanel.addEventListener(eventName, preventDefaults, false);
    });

    chatPanel.addEventListener('dragenter', (e) => {
        if (!isDragSourceFiles(e)) return;
        dragCounter++;
        if (dragCounter === 1) {
            dragOverlay.classList.add('active');
        }
    }, false);

    chatPanel.addEventListener('dragleave', (e) => {
        if (!isDragSourceFiles(e)) return;
        dragCounter--;
        if (dragCounter === 0) {
            dragOverlay.classList.remove('active');
        }
    }, false);

    chatPanel.addEventListener('drop', (e) => {
        if (!isDragSourceFiles(e)) return;
        dragCounter = 0;
        dragOverlay.classList.remove('active');

        const files = e.dataTransfer.files;
        if (files && files.length > 0) {
            const currentFileInput = document.getElementById('file-upload');
            if (currentFileInput) {
                currentFileInput.files = files;
                currentFileInput.dispatchEvent(new Event('change'));
            }
        }
    }, false);
}

function handlePasteUpload(event, fileInput) {
    const items = (event.clipboardData || event.originalEvent.clipboardData).items;
    for (let item of items) {
        if (item.kind === 'file') {
            const file = item.getAsFile();
            const dataTransfer = new DataTransfer();
            dataTransfer.items.add(file);
            fileInput.files = dataTransfer.files;
            fileInput.dispatchEvent(new Event('change'));
            event.preventDefault(); // Don't paste file contents as text
            break;
        }
    }
}

export function initFileUpload() {
    const fileInput = document.getElementById('file-upload');
    const textarea = document.getElementById('message');
    const previewContainer = document.getElementById('file-preview-container');
    const previewName = document.getElementById('file-preview-name');
    const clearBtn = document.getElementById('btn-clear-file');

    if (!fileInput || !textarea || !previewContainer || !previewName) return;

    if (fileInput.dataset.initialized === 'true') return;
    fileInput.dataset.initialized = 'true';

    // Paste event handler
    textarea.addEventListener('paste', (event) => {
        handlePasteUpload(event, fileInput);
    });

    // Change event handler for file input
    fileInput.addEventListener('change', () => {
        if (fileInput.files && fileInput.files.length > 0) {
            const file = fileInput.files[0];
            const MAX_SIZE = 10485760; // 10MB
            if (file.size > MAX_SIZE) {
                alert(`Le fichier "${file.name}" dépasse la taille maximale autorisée de 10 Mo.`);
                fileInput.value = '';
                previewContainer.style.display = 'none';
                if (textarea.value.trim() === '') {
                    textarea.setAttribute('required', 'required');
                }
                return;
            }
            previewName.textContent = `${file.name} (${formatBytes(file.size)})`;
            previewContainer.style.display = 'flex';
            textarea.removeAttribute('required');
        } else {
            previewContainer.style.display = 'none';
            // Only require textarea if empty
            if (textarea.value.trim() === '') {
                textarea.setAttribute('required', 'required');
            }
        }
    });

    // Clear button event handler
    if (clearBtn) {
        clearBtn.addEventListener('click', () => {
            fileInput.value = '';
            fileInput.dispatchEvent(new Event('change'));
        });
    }

    // Also monitor textarea inputs to update required attribute when a file is NOT selected
    textarea.addEventListener('input', () => {
        if (!fileInput.files || fileInput.files.length === 0) {
            if (textarea.value.trim() === '') {
                textarea.setAttribute('required', 'required');
            } else {
                textarea.removeAttribute('required');
            }
        }
    });

    // Drag & drop handlers
    const chatPanel = document.querySelector('.chat-panel');
    const dragOverlay = document.getElementById('drag-drop-overlay');
    setupDragAndDrop(chatPanel, dragOverlay);

    // Prevent default drag/drop behaviors globally to avoid browser page navigation on stray drops
    if (!window.dragAndDropGlobalBound) {
        window.dragAndDropGlobalBound = true;
        ['dragover', 'drop'].forEach(eventName => {
            window.addEventListener(eventName, (e) => {
                e.preventDefault();
            }, false);
        });
    }
}

export function toggleStatusDropdown(event) {
    event.stopPropagation();
    const trigger = event.currentTarget;
    const container = trigger.closest('.user-status-dropdown-container');
    if (!container) return;
    const menu = container.querySelector('.user-status-dropdown-menu');
    if (!menu) return;

    const isOpen = container.classList.contains('open');
    closeAllStatusDropdowns();

    if (!isOpen) {
        container.classList.add('open');
        menu.style.display = 'flex';
        trigger.setAttribute('aria-expanded', 'true');

        // Focus the active or first option
        const activeOpt = menu.querySelector('.user-status-option.active') || menu.querySelector('.user-status-option');
        if (activeOpt) {
            setTimeout(() => activeOpt.focus(), 50);
        }

        container.addEventListener('keydown', handleDropdownKeyDown);
    }
}

export function closeAllStatusDropdowns() {
    document.querySelectorAll('.user-status-dropdown-container').forEach(c => {
        c.classList.remove('open');
        const m = c.querySelector('.user-status-dropdown-menu');
        if (m) m.style.display = 'none';
        const trigger = c.querySelector('.user-status-trigger');
        if (trigger) {
            trigger.setAttribute('aria-expanded', 'false');
        }
        c.removeEventListener('keydown', handleDropdownKeyDown);
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

export function handleBusyOptionClick(event) {
    event.preventDefault();
    event.stopPropagation();

    closeAllStatusDropdowns();

    if (window.openBusyStatusModal) {
        window.openBusyStatusModal(function() {
            if (window.htmx) {
                window.htmx.ajax('POST', '/user/update-status', {
                    values: { status: 'busy' },
                    swap: 'none'
                });
            } else {
                fetch('/user/update-status', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: 'status=busy'
                });
            }
        });
    }
}
window.handleBusyOptionClick = handleBusyOptionClick;

// Close dropdowns on document click
document.addEventListener('click', () => {
    closeAllStatusDropdowns();
});

export function clearChannelSearch() {
    const input = document.getElementById('channel-search-input');
    if (input) {
        input.value = '';
        const statusBadge = document.getElementById('mercure-status');
        if (statusBadge) {
            statusBadge.removeAttribute('data-search-active');
        }
        // Trigger htmx request to restore feed
        if (window.htmx) window.htmx.trigger(input, 'input');
    }
}

export function toggleUnreadFilter(btn, event) {
    const isActive = btn.classList.contains('active');
    if (isActive) {
        // Already active: clear the filter, cancel HTMX request
        if (event) { event.preventDefault(); event.stopPropagation(); }
        clearUnreadFilter();
        return false;
    }
    btn.classList.add('active');
    // Clear the search input to avoid conflicts
    const input = document.getElementById('channel-search-input');
    if (input) { input.value = ''; }
}

export function clearUnreadFilter() {
    const btn = document.getElementById('btn-unread-filter');
    if (btn) { btn.classList.remove('active'); }
    // Use htmx.ajax directly to bypass the 'changed' modifier on the search input
    const input = document.getElementById('channel-search-input');
    if (input && window.htmx) {
        input.value = '';
        const searchUrl = input.getAttribute('hx-get') || input.getAttribute('data-hx-get');
        if (searchUrl) {
            window.htmx.ajax('GET', searchUrl, { target: '#live-feed', swap: 'innerHTML' });
        }
    }
}

export function scrollToMessage(messageId) {
    const el = document.querySelector(`[data-message-id="${messageId}"]`);
    if (el) {
        el.scrollIntoView({ behavior: 'smooth', block: 'center' });
        el.classList.add('highlight-pinned-message');
        setTimeout(() => {
            el.classList.remove('highlight-pinned-message');
        }, 2000);
    } else {
        alert("Ce message n'est pas présent dans l'historique chargé.");
    }
}

export function updateChannelLastMessageDate(channelSlug) {
    const channelLink = document.querySelector(`.channel-link[data-channel-slug="${channelSlug}"]`);
    if (!channelLink) return;

    // Get current time formatted as H:i
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
        // Insert it before the unread badge if exists, or append it
        const badge = channelLink.querySelector('.unread-badge');
        if (badge) {
            channelLink.insertBefore(dateSpan, badge);
        } else {
            channelLink.appendChild(dateSpan);
        }
    }
    dateSpan.textContent = formattedTime;
    dateSpan.title = `Dernier message : ${fullDateTime}`;
}

let draggedItem = null;

function preventNavigation(e) {
    e.preventDefault();
    e.stopPropagation();
}

export function initChannelReordering() {
    const sidebarPanel = document.querySelector('.sidebar-panel');
    const toggleBtn = document.getElementById('btn-edit-order-trigger');
    const lists = document.querySelectorAll('.channel-list[data-list-type]');

    if (!sidebarPanel || !toggleBtn || !lists.length) return;

    // Remove any existing click listener by cloning the button (to avoid multiple registrations)
    const newToggleBtn = toggleBtn.cloneNode(true);
    toggleBtn.parentNode.replaceChild(newToggleBtn, toggleBtn);

    // Initial check for cleanup (reset link drag states)
    const links = document.querySelectorAll('.channel-link');
    links.forEach(link => {
        link.draggable = false;
        link.removeEventListener('click', preventNavigation, true);
    });

    newToggleBtn.addEventListener('click', () => {
        const isActive = sidebarPanel.classList.toggle('reorder-active');
        newToggleBtn.classList.toggle('reorder-active-btn', isActive);
        newToggleBtn.textContent = isActive ? '✔️' : '⇅';
        newToggleBtn.title = isActive ? 'Terminer l\'organisation' : 'Ordonner les canaux';

        const currentLinks = document.querySelectorAll('.channel-link');
        if (isActive) {
            currentLinks.forEach(link => {
                link.draggable = true;
                link.addEventListener('click', preventNavigation, true);
            });
        } else {
            currentLinks.forEach(link => {
                link.draggable = false;
                link.removeEventListener('click', preventNavigation, true);
            });
            saveChannelOrder();
        }
    });

    lists.forEach(list => {
        list.addEventListener('dragstart', (e) => {
            if (!sidebarPanel.classList.contains('reorder-active')) {
                e.preventDefault();
                return;
            }
            const channelLink = e.target.closest('.channel-link');
            if (!channelLink) return;
            draggedItem = channelLink;
            channelLink.classList.add('dragging');
            e.dataTransfer.setData('text/plain', channelLink.getAttribute('data-channel-id'));
        });

        list.addEventListener('dragend', (e) => {
            const channelLink = e.target.closest('.channel-link');
            if (channelLink) {
                channelLink.classList.remove('dragging');
            }
            draggedItem = null;
        });

        list.addEventListener('dragover', (e) => {
            e.preventDefault();
            if (!sidebarPanel.classList.contains('reorder-active') || !draggedItem) return;

            const targetList = e.currentTarget;
            const sourceListType = draggedItem.parentElement.getAttribute('data-list-type');
            const targetListType = targetList.getAttribute('data-list-type');
            if (sourceListType !== targetListType) return;

            const afterElement = getDragAfterElement(targetList, e.clientY);
            if (afterElement == null) {
                targetList.appendChild(draggedItem);
            } else {
                targetList.insertBefore(draggedItem, afterElement);
            }
        });
    });
}

function getDragAfterElement(container, y) {
    const draggableElements = [...container.querySelectorAll('.channel-link:not(.dragging)')];
    return draggableElements.reduce((closest, child) => {
        const box = child.getBoundingClientRect();
        const offset = y - box.top - box.height / 2;
        if (offset < 0 && offset > closest.offset) {
            return { offset: offset, element: child };
        } else {
            return closest;
        }
    }, { offset: Number.NEGATIVE_INFINITY }).element;
}

function saveChannelOrder() {
    const allLinks = document.querySelectorAll('.channel-link[data-channel-id]');
    const order = Array.from(allLinks).map(el => parseInt(el.getAttribute('data-channel-id'), 10));

    fetch('/channels/reorder', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: JSON.stringify({ order: order })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            console.log('Channel order updated successfully!');
        } else {
            console.error('Failed to update channel order:', data.error);
        }
    })
    .catch(err => {
        console.error('Error updating channel order:', err);
    });
}

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
    // Remove old listeners first to avoid duplication on HTMX swaps
    const newFilterBtn = filterBtn.cloneNode(true);
    filterBtn.parentNode.replaceChild(newFilterBtn, filterBtn);

    newFilterBtn.addEventListener('click', () => {
        const active = sidebarPanel.classList.toggle('filter-unread-active');
        newFilterBtn.classList.toggle('filter-unread-active-btn', active);
        localStorage.setItem('filterUnreadOnly', active ? 'true' : 'false');
    });
}

export function showCustomConfirm(message, callback) {
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

    const lowerMsg = message.toLowerCase();
    titleEl.className = 'confirmation-title';
    okBtn.className = 'btn-confirm-action';

    if (lowerMsg.includes('supprimer') || lowerMsg.includes('perdu')) {
        iconEl.textContent = '🗑️';
        titleEl.textContent = 'Supprimer ?';
        okBtn.textContent = 'Supprimer';
    } else if (lowerMsg.includes('quitter')) {
        iconEl.textContent = '🚪';
        titleEl.textContent = 'Quitter ?';
        okBtn.textContent = 'Quitter';
        titleEl.classList.add('warning-type');
        okBtn.classList.add('warning-type');
    } else {
        iconEl.textContent = '❓';
        titleEl.textContent = 'Confirmer ?';
        okBtn.textContent = 'Confirmer';
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

export function initConfirmModals() {
    // Intercept HTMX confirm requests (only when hx-confirm is actually set)
    document.body.addEventListener('htmx:confirm', function(evt) {
        if (!evt.detail.question) return;
        evt.preventDefault();
        showCustomConfirm(evt.detail.question, function() {
            evt.detail.issueRequest(true);
        });
    });

    // Intercept standard form submissions with data-confirm
    document.addEventListener('submit', function(evt) {
        const form = evt.target;
        if (form.hasAttribute('data-confirm')) {
            const message = form.getAttribute('data-confirm');
            if (!form.dataset.confirmed) {
                evt.preventDefault();
                showCustomConfirm(message, function() {
                    form.dataset.confirmed = 'true';
                    form.submit();
                });
            } else {
                delete form.dataset.confirmed;
            }
        }
    });
}

// Inactivity check: automatically sets users to offline if no activity for 5 minutes
setInterval(() => {
    const now = Math.floor(Date.now() / 1000);
    document.querySelectorAll('[data-last-active]').forEach(el => {
        const override = el.getAttribute('data-status-override');
        if (override && override !== 'auto' && override !== '') {
            return;
        }
        const lastActive = parseInt(el.getAttribute('data-last-active'), 10);
        if (!lastActive) {
            updateElementStatus(el, 'offline', 'Hors ligne');
            return;
        }
        if (now - lastActive > 300) {
            updateElementStatus(el, 'offline', 'Hors ligne');
        } else {
            updateElementStatus(el, 'online', 'En ligne');
        }
    });
}, 15000);



export function openGlobalSearch() {
    const modal = document.getElementById('global-search-modal');
    const input = document.getElementById('global-search-input');
    if (modal && input) {
        modal.style.display = 'flex';
        input.focus();
        input.select();
    }
}

export function closeGlobalSearch() {
    const modal = document.getElementById('global-search-modal');
    if (modal && modal.style.display !== 'none' && modal.style.display !== '') {
        modal.style.display = 'none';
        const messageInput = document.getElementById('message');
        const isMobile = window.matchMedia('(max-width: 1024px)').matches || ('ontouchstart' in window) || (navigator.maxTouchPoints > 0);
        if (messageInput && !isMobile) messageInput.focus();
    }
}

export function handleGlobalSearchBackdropClick(event) {
    if (event.target.id === 'global-search-modal') {
        closeGlobalSearch();
    }
}

export function initGlobalSearch() {
    document.addEventListener('keydown', (e) => {
        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
            e.preventDefault();
            openGlobalSearch();
        }
        if (e.key === 'Escape') {
            const modal = document.getElementById('global-search-modal');
            if (modal && modal.style.display === 'flex') {
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

        // Also close sidebar when clicking a channel link inside the sidebar list on mobile
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

// Global window binds
window.scrollToBottom = scrollToBottom;
window.highlightAllCodeBlocks = highlightAllCodeBlocks;
window.formatBytes = formatBytes;
window.initFileUpload = initFileUpload;
window.toggleStatusDropdown = toggleStatusDropdown;
window.updateElementStatus = updateElementStatus;
window.clearChannelSearch = clearChannelSearch;
window.toggleUnreadFilter = toggleUnreadFilter;
window.clearUnreadFilter = clearUnreadFilter;
window.scrollToMessage = scrollToMessage;
window.updateChannelLastMessageDate = updateChannelLastMessageDate;
window.initChannelReordering = initChannelReordering;
window.initUnreadFilter = initUnreadFilter;
window.showCustomConfirm = showCustomConfirm;
window.showCustomAlert = showCustomAlert;
window.initConfirmModals = initConfirmModals;

window.openGlobalSearch = openGlobalSearch;
window.closeGlobalSearch = closeGlobalSearch;
window.handleGlobalSearchBackdropClick = handleGlobalSearchBackdropClick;
window.initGlobalSearch = initGlobalSearch;
window.initMobileSidebar = initMobileSidebar;

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
window.toggleMobileChannelDetails = toggleMobileChannelDetails;

export function toggleMessageActions(button, event) {
    event.stopPropagation();
    const actionsList = button.nextElementSibling;
    if (!actionsList) return;

    // Close other open message action lists first
    document.querySelectorAll('.feed-item-actions-list.show').forEach(list => {
        if (list !== actionsList) {
            list.classList.remove('show');
        }
    });

    actionsList.classList.toggle('show');
}
window.toggleMessageActions = toggleMessageActions;


