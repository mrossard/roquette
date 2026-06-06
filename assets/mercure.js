const isProd = document.querySelector('meta[name="app-env"]')?.getAttribute('content') === 'prod';
const console = {
    log: (...args) => { if (!isProd) window.console.log(...args); },
    warn: (...args) => { if (!isProd) window.console.warn(...args); },
    error: (...args) => {
        if (!isProd) window.console.error(...args);
    }
};

let isRedirecting = false;
function safeRedirectToLogin(reason = '') {
    if (isRedirecting) return;
    isRedirecting = true;
    console.log(`Redirecting to login due to: ${reason}`);
    showOfflineBanner(true, 'Votre session a expiré. Redirection vers la page de connexion dans 5 secondes...');

    const reconnectBtn = document.querySelector('.offline-reconnect-btn');
    if (reconnectBtn) {
        reconnectBtn.disabled = true;
        reconnectBtn.textContent = 'Session expirée...';
    }

    setTimeout(() => {
        window.location.href = '/login';
    }, 5000);
}

let typingTimeout = null;
let isCurrentlyTyping = false;
let currentInitializedChannelSlug = null;

function updateMercureStatus(isConnected, text, stateClass = null) {
    const activeStatusBadge = document.getElementById('mercure-status');
    if (!activeStatusBadge) return;

    const dot = activeStatusBadge.querySelector('.status-dot');
    const label = activeStatusBadge.querySelector('.status-text');

    if (dot && label) {
        if (stateClass) {
            dot.className = `status-dot ${stateClass}`;
        } else {
            dot.className = isConnected ? 'status-dot connected' : 'status-dot disconnected';
        }
        label.textContent = text;
    }
}

function isCurrentUserBusy() {
    const statusBadge = document.getElementById('mercure-status');
    if (!statusBadge) return false;
    const currentUsername = statusBadge.getAttribute('data-current-username');
    if (!currentUsername) return false;
    const currentUserDot = document.querySelector(`.status-dot[data-username="${currentUsername}"]`);
    return currentUserDot && currentUserDot.classList.contains('busy');
}

export function handleUserStatusChanged(data) {
    const username = data.username;
    const newStatus = data.status;
    const label = data.statusLabel;

    const statusBadge = document.getElementById('mercure-status');
    const currentUsername = statusBadge ? statusBadge.getAttribute('data-current-username') : null;

    if (isCurrentUserBusy() && username !== currentUsername) {
        return;
    }

    const wasBusy = (username === currentUsername) ? isCurrentUserBusy() : false;

    document.querySelectorAll(`[data-username="${username}"]`).forEach(el => {
        if (data.statusOverride !== undefined) {
            el.setAttribute('data-status-override', data.statusOverride || 'auto');
        }
        if (data.lastActive !== undefined) {
            el.setAttribute('data-last-active', data.lastActive || '');
        }
        if (window.updateElementStatus) {
            window.updateElementStatus(el, newStatus, label);
        }
    });

    if (username === currentUsername) {
        const isBusyNow = isCurrentUserBusy();
        if (wasBusy && !isBusyNow) {
            window.location.reload();
        }
    }
}

export function initTypingIndicator() {
    const messageInput = document.getElementById('message');
    if (!messageInput || messageInput.dataset.typingInitialized === 'true') return;

    const statusBadge = document.getElementById('mercure-status');
    const channelSlug = statusBadge ? statusBadge.getAttribute('data-active-channel-slug') : null;
    if (!channelSlug) return;

    if (currentInitializedChannelSlug !== channelSlug) {
        currentInitializedChannelSlug = channelSlug;
    }

    messageInput.dataset.typingInitialized = 'true';

    messageInput.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && !e.shiftKey && !e.altKey) {
            if (isCurrentlyTyping) {
                isCurrentlyTyping = false;
                sendTypingStatus(channelSlug, false);
            }
        }
    });

    messageInput.addEventListener('input', () => {
        const hasText = messageInput.value.trim() !== '';

        if (hasText) {
            if (!isCurrentlyTyping) {
                isCurrentlyTyping = true;
                sendTypingStatus(channelSlug, true);
            }

            if (typingTimeout) {
                clearTimeout(typingTimeout);
            }

            typingTimeout = setTimeout(() => {
                isCurrentlyTyping = false;
                sendTypingStatus(channelSlug, false);
            }, 3000);
        } else {
            if (isCurrentlyTyping) {
                isCurrentlyTyping = false;
                if (typingTimeout) {
                    clearTimeout(typingTimeout);
                    typingTimeout = null;
                }
                sendTypingStatus(channelSlug, false);
            }
        }
    });

    const form = messageInput.closest('form');
    if (form) {
        form.addEventListener('submit', () => {
            if (typingTimeout) {
                clearTimeout(typingTimeout);
            }
            isCurrentlyTyping = false;
            sendTypingStatus(channelSlug, false);
        });
    }
}

export function sendTypingStatus(channelSlug, isTyping) {
    const url = `/channel/${channelSlug}/typing`;
    fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: JSON.stringify({ isTyping: isTyping })
    }).catch(err => console.error('Error sending typing status:', err));
}

export function handleHelpStreamUpdate(data) {
    let helpElem = document.getElementById(data.helpMessageId);
    if (!helpElem) {
        const liveFeed = document.getElementById('live-feed');
        const statusBadge = document.getElementById('mercure-status');
        const activeChannelSlug = statusBadge ? statusBadge.getAttribute('data-active-channel-slug') : null;
        if (liveFeed && data.channelSlug === activeChannelSlug) {
            const emptyState = document.getElementById('feed-empty-state');
            if (emptyState) {
                emptyState.remove();
            }
            const tempDiv = document.createElement('div');
            tempDiv.id = data.helpMessageId;
            tempDiv.className = 'feed-item fade-in';
            tempDiv.style.setProperty('--user-hue', '200');

            const timeStr = new Date().toLocaleTimeString([], {hour: '2-digit', minute: '2-digit'});
            tempDiv.innerHTML = `
                <div class="feed-item-header">
                    <div class="feed-item-user-container">
                        <div class="avatar-container">
                            <span class="feed-item-avatar">A</span>
                        </div>
                        <span class="feed-item-user">Assistant Roquette</span>
                    </div>
                    <span class="feed-item-time">${timeStr}</span>
                </div>
                <div class="feed-item-body">
                    ${data.html}
                </div>
            `;
            liveFeed.appendChild(tempDiv);
            helpElem = tempDiv;
            if (window.highlightAllCodeBlocks) {
                window.highlightAllCodeBlocks(helpElem);
            }
            if (window.scrollToBottom) {
                window.scrollToBottom(true);
            }
        }
    } else {
        const bodyElem = helpElem.querySelector('.feed-item-body');
        if (bodyElem) {
            bodyElem.innerHTML = data.html;
            if (window.highlightAllCodeBlocks) {
                window.highlightAllCodeBlocks(helpElem);
            }
            if (window.scrollToBottom) {
                window.scrollToBottom(true);
            }
        }
    }
}

export function showOfflineBanner(show, text = 'Connexion avec le serveur perdue. Tentative de reconnexion...') {
    let banner = document.getElementById('mercure-offline-banner');

    if (show) {
        if (!banner) {
            const feedContainer = document.getElementById('live-feed');
            if (feedContainer) {
                banner = document.createElement('div');
                banner.id = 'mercure-offline-banner';
                banner.className = 'offline-banner';
                feedContainer.parentNode.insertBefore(banner, feedContainer);
            }
        }
        if (banner) {
            banner.innerHTML = `
                <div class="offline-banner-content">
                    <span class="offline-banner-icon">⚠️</span>
                    <span class="offline-banner-text">${text}</span>
                </div>
                <button class="offline-reconnect-btn" onclick="window.manualReconnect(this)">Reconnexion</button>
            `;
            banner.style.display = 'flex';
        }
    } else {
        if (banner) {
            banner.style.display = 'none';
        }
    }
}

export function manualReconnect(button) {
    if (isRedirecting) return;
    if (button) {
        button.disabled = true;
        button.textContent = 'Vérification...';
    }

    fetch('/user/ping', {
        method: 'GET',
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
        .then(response => {
            if (response.redirected && response.url.includes('/login')) {
                safeRedirectToLogin('Redirected to login');
                return;
            }
            if (!response.ok) {
                if (response.status === 401 || response.status === 403) {
                    safeRedirectToLogin(`Status ${response.status}`);
                    return;
                }
                throw new Error('Session invalid');
            }
            // Reload page to re-establish connections cleanly
            window.location.reload();
        })
        .catch(err => {
            console.error('Manual reconnect failed checking session:', err);
            if (button) {
                button.disabled = false;
                button.textContent = 'Reconnexion';
            }
            if (err.message && err.message.includes('Session invalid')) {
                safeRedirectToLogin('Session invalid exception');
            } else {
                showOfflineBanner(true, 'Le serveur ne répond pas. Veuillez réessayer dans quelques instants.');
            }
        });
}

// HTMX SSE connection event listeners
document.body.addEventListener('htmx:sseOpen', () => {
    updateMercureStatus(true, window.AppTranslations?.['Connecté au Hub'] || 'Connecté au Hub');
    showOfflineBanner(false);
});

document.body.addEventListener('htmx:sseError', () => {
    fetch('/user/ping', {
        method: 'GET',
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
        .then(response => {
            if (response.redirected && response.url.includes('/login')) {
                safeRedirectToLogin('Redirected to login');
                return;
            }
            if (!response.ok && (response.status === 401 || response.status === 403)) {
                safeRedirectToLogin(`Status ${response.status}`);
                return;
            }
            updateMercureStatus(false, window.AppTranslations?.['Connexion interrompue'] || 'Connexion interrompue', 'disconnected');
            showOfflineBanner(true, window.AppTranslations?.['Connexion au serveur perdue. Tentative de reconnexion...'] || 'Connexion au serveur perdue. Tentative de reconnexion...');
        })
        .catch(() => {
            updateMercureStatus(false, window.AppTranslations?.['Connexion interrompue'] || 'Connexion interrompue', 'disconnected');
            showOfflineBanner(true, window.AppTranslations?.['Connexion au serveur perdue. Tentative de reconnexion...'] || 'Connexion au serveur perdue. Tentative de reconnexion...');
        });
});

document.body.addEventListener('htmx:sseMessage', (event) => {
    const type = event.detail.type;

    try {
        const data = JSON.parse(event.detail.data);
        if (type === 'user_status_changed') {
            handleUserStatusChanged(data);
        } else if (type === 'personal_notification') {
            if (window.handleGlobalNotification) {
                window.handleGlobalNotification(data);
            }
            if (window.updateChannelLastMessageDate) {
                window.updateChannelLastMessageDate(data.channelSlug);
            }
        } else if (type === 'help_stream_update') {
            handleHelpStreamUpdate(data);
        } else if (type === 'invitation_received') {
            if (window.handleInvitationNotification) {
                window.handleInvitationNotification(data);
            }
        } else if (type === 'channel_deleted') {
            if (window.handleChannelDeletedNotification) {
                window.handleChannelDeletedNotification(data);
            }
        }
    } catch (err) {
        // Expected for message HTML payloads
    }
});

// Scroll to bottom smoothly on new message received
document.body.addEventListener('htmx:sseMessage', (event) => {
    if (event.detail.type.startsWith('message_')) {
        setTimeout(() => {
            if (window.scrollToBottom) {
                window.scrollToBottom(true);
            }
        }, 50);
    }
});

// Global window binds
window.connectMercure = () => {
}; // No-op, managed by HTMX
window.initTypingIndicator = initTypingIndicator;
window.handlePinChange = () => {
}; // No-op, managed by HTMX OOB
window.showOfflineBanner = showOfflineBanner;
window.manualReconnect = manualReconnect;
