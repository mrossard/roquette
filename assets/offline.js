// assets/offline.js

// Array to hold offline messages in memory (also synced to localStorage)
let offlineQueue = [];

function loadOfflineQueue() {
    try {
        const stored = localStorage.getItem('roquette_offline_queue');
        if (stored) {
            offlineQueue = JSON.parse(stored);
        }
    } catch (e) {
        console.error('Failed to load offline queue from localStorage:', e);
    }
}

function saveOfflineQueue() {
    try {
        localStorage.setItem('roquette_offline_queue', JSON.stringify(offlineQueue));
    } catch (e) {
        console.error('Failed to save offline queue to localStorage:', e);
    }
}

// Check if we are offline or if the connection is down
export function isOffline() {
    if (!navigator.onLine) return true;

    // Also consider offline if the Mercure status indicates disconnected/offline banner is active
    const offlineBanner = document.getElementById('mercure-offline-banner');
    return offlineBanner && offlineBanner.style.display !== 'none';

}

// Function to handle queueing of a message
export function queueOfflineMessage(form) {
    const textarea = form.querySelector('textarea');
    if (!textarea) return;

    const messageText = textarea.value;
    if (!messageText.trim()) return;

    // Determine target from form hx-post attribute
    const actionUrl = form.getAttribute('hx-post') || form.getAttribute('data-hx-post') || form.action || '';
    if (!actionUrl) return;

    let channelSlug = null;
    let postUrl = actionUrl;

    const channelMatch = actionUrl.match(/\/channels\/([^\/]+)\/publish/);
    if (channelMatch) {
        channelSlug = channelMatch[1];
    }

    if (!channelSlug) {
        console.error('Could not determine channel for offline message:', actionUrl);
        return;
    }

    // Get current user details from status-badge
    const statusBadge = document.getElementById('mercure-status');
    const username = statusBadge ? statusBadge.getAttribute('data-current-username') : 'moi';

    const offlineId = 'offline-' + Date.now() + '-' + Math.random().toString(36).substr(2, 9);
    const timestamp = new Date();

    const offlineMsg = {
        id: offlineId,
        channelSlug: channelSlug,
        content: messageText,
        postUrl: postUrl,
        timestamp: timestamp.toISOString(),
        username: username
    };

    // Add to queue
    offlineQueue.push(offlineMsg);
    saveOfflineQueue();

    // Render temporary message in feed
    renderOfflineMessage(offlineMsg);

    // Clear textarea
    textarea.value = '';
    textarea.dispatchEvent(new Event('input', { bubbles: true }));


    // Start sync retries
    startSyncInterval();
}

function renderOfflineMessage(msg) {
    // Check if message is already rendered
    if (document.querySelector(`[data-offline-id="${msg.id}"]`)) return;

    // Render HTML manually to match _feed_item.html.twig layout
    const timeStr = new Date(msg.timestamp).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });
    const formattedContent = escapeHtml(msg.content).replace(/\n/g, '<br>');

    const feedItem = document.createElement('div');
    feedItem.className = 'feed-item offline-pending fade-in';
    feedItem.setAttribute('data-offline-id', msg.id);
    feedItem.style.opacity = '0.7';
    feedItem.style.borderLeft = '3px solid var(--accent-orange, #f59e0b)';

    feedItem.innerHTML = `
        <div class="feed-item-header">
            <div class="feed-item-user-container">
                <div class="avatar-container">
                    <span class="feed-item-avatar">${msg.username.slice(0, 1).toUpperCase()}</span>
                </div>
                <span class="feed-item-user">${msg.username}</span>
            </div>
            <span class="feed-item-time">${timeStr}</span>
            <span class="offline-status-label" style="font-size: 0.75rem; color: var(--accent-orange, #f59e0b); font-weight: 500; margin-left: 0.5rem; display: flex; align-items: center; gap: 4px;">
                <span class="spinner-small" style="border: 2px solid rgba(245, 158, 11, 0.1); border-top-color: var(--accent-orange, #f59e0b); width: 10px; height: 10px; display: inline-block; border-radius: 50%; animation: spin 1s linear infinite;"></span>
                En attente de connexion...
            </span>
        </div>
        <div class="feed-item-body">
            <p>${formattedContent}</p>
        </div>
    `;

    const activeFeedContainer = document.getElementById('live-feed');
    if (activeFeedContainer) {
        const emptyState = document.getElementById('feed-empty-state');
        if (emptyState) emptyState.remove();
        activeFeedContainer.appendChild(feedItem);
    }
}

// Sync queue to server
let isSyncing = false;
let syncInterval = null;

export function syncOfflineMessages() {
    if (isSyncing || offlineQueue.length === 0) return;
    isSyncing = true;

    // Process messages sequentially
    const sendNext = () => {
        if (offlineQueue.length === 0) {
            isSyncing = false;
            stopSyncInterval();
            return;
        }

        const msg = offlineQueue[0];

        // Update status in UI for current message
        const tempEl = document.querySelector(`[data-offline-id="${msg.id}"]`);
        if (tempEl) {
            const statusLabel = tempEl.querySelector('.offline-status-label');
            if (statusLabel) {
                statusLabel.innerHTML = `
                    <span class="spinner-small" style="border: 2px solid rgba(24, 119, 242, 0.1); border-top-color: #1877f2; width: 10px; height: 10px; display: inline-block; border-radius: 50%; animation: spin 1s linear infinite;"></span>
                    Envoi en cours...
                `;
                statusLabel.style.color = '#1877f2';
            }
        }

        fetch(msg.postUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: new URLSearchParams({ message: msg.content })
        })
        .then(res => {
            if (!res.ok) {
                throw new Error(`Sync failed: ${res.status}`);
            }
            return res.text();
        })
        .then(html => {
            // Remove from queue
            offlineQueue.shift();
            saveOfflineQueue();

            // Replace temporary message in the feed with actual HTML returned (if we are in the correct channel)
            const tempEl = document.querySelector(`[data-offline-id="${msg.id}"]`);
            if (tempEl) {
                const tempDiv = document.createElement('div');
                tempDiv.innerHTML = html.trim();
                const actualItem = tempDiv.firstChild;

                if (actualItem && tempEl.parentNode) {
                    if (window.Idiomorph) {
                        window.Idiomorph.morph(tempEl, actualItem);
                        if (window.htmx) window.htmx.process(tempEl);
                    } else {
                        tempEl.parentNode.replaceChild(actualItem, tempEl);
                        if (window.htmx) window.htmx.process(actualItem);
                    }
                } else {
                    tempEl.remove();
                }
            }

            // Continue with next message
            sendNext();
        })
        .catch(err => {
            console.error('Failed to sync offline message:', err);
            isSyncing = false;

            // Revert status label back to waiting
            const tempEl = document.querySelector(`[data-offline-id="${msg.id}"]`);
            if (tempEl) {
                const statusLabel = tempEl.querySelector('.offline-status-label');
                if (statusLabel) {
                    statusLabel.innerHTML = `
                        <span class="spinner-small" style="border: 2px solid rgba(245, 158, 11, 0.1); border-top-color: var(--accent-orange, #f59e0b); width: 10px; height: 10px; display: inline-block; border-radius: 50%; animation: spin 1s linear infinite;"></span>
                        Connexion perdue. Nouvel essai...
                    `;
                    statusLabel.style.color = 'var(--accent-orange, #f59e0b)';
                }
            }
        });
    };

    sendNext();
}

export function startSyncInterval() {
    if (syncInterval) return;
    syncInterval = setInterval(() => {
        if (!isOffline() && offlineQueue.length > 0) {
            syncOfflineMessages();
        }
    }, 5000);
}

export function stopSyncInterval() {
    if (syncInterval) {
        clearInterval(syncInterval);
        syncInterval = null;
    }
}

function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

export function renderChannelOfflineMessages() {
    const statusBadge = document.getElementById('mercure-status');
    const activeChannelSlug = statusBadge ? statusBadge.getAttribute('data-active-channel-slug') : null;
    if (!activeChannelSlug) return;

    offlineQueue.forEach(msg => {
        if (msg.channelSlug === activeChannelSlug) {
            renderOfflineMessage(msg);
        }
    });
}

// Setup listeners
export function initOfflineQueue() {
    loadOfflineQueue();

    // Render any saved offline messages on page load
    renderChannelOfflineMessages();

    if (offlineQueue.length > 0) {
        startSyncInterval();
    }

    // Monitor online/offline status
    window.addEventListener('online', () => {
        console.log('Browser back online. Attempting to sync offline messages...');
        syncOfflineMessages();
    });

    // Intercept submit
    document.addEventListener('submit', (evt) => {
        const form = evt.target;
        if (form && form.classList.contains('chat-message-form')) {
            // Check if we have files. Offline support for file uploads is skipped (needs network)
            const fileInput = form.querySelector('input[type="file"]');
            const hasFiles = fileInput && fileInput.files && fileInput.files.length > 0;

            if (isOffline() && !hasFiles) {
                evt.preventDefault();
                evt.stopImmediatePropagation();
                queueOfflineMessage(form);
            }
        }
    }, true); // Capture phase
}

// Global binds
window.initOfflineQueue = initOfflineQueue;
window.renderChannelOfflineMessages = renderChannelOfflineMessages;
