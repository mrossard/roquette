import { wasAtBottom } from './scroll.js';

const isProd = document.querySelector('meta[name="app-env"]')?.getAttribute('content') === 'prod';
const console = {
    log: (...args) => {
        if (!isProd) window.console.log(...args);
    },
    warn: (...args) => {
        if (!isProd) window.console.warn(...args);
    },
    error: (...args) => {
        if (!isProd) window.console.error(...args);
    }
};

let isRedirecting = false;

function safeRedirectToLogin(reason = '') {
    if (isRedirecting) return;
    isRedirecting = true;
    console.log(`Redirecting to login due to: ${reason}`);
    showOfflineBanner(true, window.trans('Votre session a expiré. Redirection vers la page de connexion dans 5 secondes...'));

    const reconnectBtn = document.querySelector('.offline-reconnect-btn');
    if (reconnectBtn) {
        reconnectBtn.disabled = true;
        reconnectBtn.textContent = window.trans('Session expirée...');
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
    htmx.ajax('POST', `/channel/${channelSlug}/typing`, {
        values: {isTyping: isTyping ? '1' : '0'},
        target: document.getElementById('typing-indicator'),
        swap: 'outerHTML',
    });
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
                <button class="offline-reconnect-btn" onclick="window.manualReconnect(this)">${window.trans('Reconnexion')}</button>
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
        button.textContent = window.trans('Vérification...');
    }

    const el = document.createElement('div');
    el.style.display = 'none';
    document.body.appendChild(el);

    el.addEventListener('htmx:beforeSwap', function onSwap(e) {
        if (e.detail.elt !== el) return;
        el.removeEventListener('htmx:beforeSwap', onSwap);
        e.detail.shouldSwap = false;
        el.remove();

        const xhr = e.detail.xhr;
        if (xhr.responseURL && xhr.responseURL.includes('/login')) {
            safeRedirectToLogin('Redirected to login');
            return;
        }
        const status = xhr.status;
        if (status === 401 || status === 403) {
            safeRedirectToLogin(`Status ${status}`);
            return;
        }
        if (status >= 200 && status < 300) {
            window.location.reload();
            return;
        }
        if (button) {
            button.disabled = false;
            button.textContent = window.trans('Reconnexion');
        }
        showOfflineBanner(true, window.trans('Le serveur ne répond pas. Veuillez réessayer dans quelques instants.'));
    });

    el.addEventListener('htmx:responseError', function onError(e) {
        if (e.detail.elt !== el) return;
        el.removeEventListener('htmx:responseError', onError);
        el.remove();
        if (button) {
            button.disabled = false;
            button.textContent = window.trans('Reconnexion');
        }
        safeRedirectToLogin('Session invalid');
    });

    htmx.ajax('GET', '/user/ping', {target: el, swap: 'innerHTML'});
}

// HTMX SSE connection event listeners
document.body.addEventListener('htmx:sseOpen', () => {
    updateMercureStatus(true, window.AppTranslations?.['Connecté au Hub'] || 'Connecté au Hub');
    showOfflineBanner(false);
});

document.body.addEventListener('htmx:sseError', () => {
    const el = document.createElement('div');
    el.style.display = 'none';
    document.body.appendChild(el);

    const showOffline = () => {
        updateMercureStatus(false, window.AppTranslations?.['Connexion interrompue'] || 'Connexion interrompue', 'disconnected');
        showOfflineBanner(true, window.AppTranslations?.['Connexion au serveur perdue. Tentative de reconnexion...'] || 'Connexion au serveur perdue. Tentative de reconnexion...');
    };

    el.addEventListener('htmx:beforeSwap', function onSwap(e) {
        if (e.detail.elt !== el) return;
        el.removeEventListener('htmx:beforeSwap', onSwap);
        e.detail.shouldSwap = false;
        el.remove();

        const xhr = e.detail.xhr;
        if (xhr.responseURL && xhr.responseURL.includes('/login')) {
            safeRedirectToLogin('Redirected to login');
            return;
        }
        if (xhr.status === 401 || xhr.status === 403) {
            safeRedirectToLogin(`Status ${xhr.status}`);
            return;
        }
        showOffline();
    });

    el.addEventListener('htmx:responseError', function onError(e) {
        if (e.detail.elt !== el) return;
        el.removeEventListener('htmx:responseError', onError);
        el.remove();
        showOffline();
    });

    htmx.ajax('GET', '/user/ping', {target: el, swap: 'innerHTML'});
});

document.body.addEventListener('htmx:sseMessage', (event) => {
    const type = event.detail.type;

    if (type === 'help_stream_update') {
        try {
            const parser = new DOMParser();
            const doc = parser.parseFromString(event.detail.data, 'text/html');
            const oobElem = doc.querySelector('[hx-swap-oob]');
            if (oobElem) {
                const channelSlug = oobElem.getAttribute('data-channel-slug');
                const statusBadge = document.getElementById('mercure-status');
                const activeChannelSlug = statusBadge ? statusBadge.getAttribute('data-active-channel-slug') : null;
                if (channelSlug && channelSlug !== activeChannelSlug) {
                    const channelLink = document.querySelector(`.channel-link[data-channel-slug="${channelSlug}"]`);
                    if (channelLink) {
                        channelLink.classList.add('unread');
                        let badge = channelLink.querySelector('.unread-badge');
                        if (!badge) {
                            badge = document.createElement('span');
                            badge.className = 'unread-badge';
                            badge.textContent = '0';
                            channelLink.appendChild(badge);
                        }
                        if (!window.processedHelpUnread) {
                            window.processedHelpUnread = new Set();
                        }
                        const id = oobElem.id;
                        if (!window.processedHelpUnread.has(id)) {
                            window.processedHelpUnread.add(id);
                            const currentCount = parseInt(badge.textContent, 10) || 0;
                            badge.textContent = (currentCount + 1).toString();
                        }
                        badge.style.display = 'inline-flex';
                    }
                    return;
                }

                const id = oobElem.id;
                const existing = document.getElementById(id);
                if (existing) {
                    if (window.Idiomorph) {
                        window.Idiomorph.morph(existing, oobElem);
                    } else {
                        existing.replaceWith(oobElem);
                    }
                    if (window.highlightAllCodeBlocks) {
                        window.highlightAllCodeBlocks(existing);
                    }
                } else {
                    const liveFeed = document.getElementById('live-feed');
                    if (liveFeed) {
                        const emptyState = document.getElementById('feed-empty-state');
                        if (emptyState) {
                            emptyState.remove();
                        }
                        oobElem.removeAttribute('hx-swap-oob');
                        liveFeed.appendChild(oobElem);
                        if (window.highlightAllCodeBlocks) {
                            window.highlightAllCodeBlocks(oobElem);
                        }
                    }
                }

                if (wasAtBottom) {
                    const feed = document.getElementById('live-feed');
                    if (feed) {
                        feed.scrollTop = feed.scrollHeight;
                    }
                }
            }
        } catch (err) {
            console.error('Error handling help stream update:', err);
        }
        return;
    }

    try {
        const data = JSON.parse(event.detail.data);
        if (type === 'user_status_changed') {
            handleUserStatusChanged(data);
        } else if (type === 'personal_notification' || type === 'channel_notification') {
            if (window.handleGlobalNotification) {
                window.handleGlobalNotification(data);
            }
            if (window.updateChannelLastMessageDate) {
                window.updateChannelLastMessageDate(data.channelSlug);
            }

        } else if (type === 'invitation_received') {
            if (window.handleInvitationNotification) {
                window.handleInvitationNotification(data);
            }
        } else if (type === 'channel_deleted') {
            const channelLink = document.querySelector(
                `.channel-link[data-channel-slug="${data.channelSlug}"]`,
            );
            if (channelLink) {
                channelLink.remove();
            }

            const sidebarItem = document.querySelector(
                `.subchannels-sidebar-item[href*="/${data.channelSlug}"]`,
            );
            if (sidebarItem) {
                sidebarItem.remove();
            }

            const statusBadge = document.getElementById('mercure-status');
            if (statusBadge) {
                const activeChannelSlug = statusBadge.getAttribute('data-active-channel-slug');
                if (data.channelSlug === activeChannelSlug) {
                    window.location.href = data.redirectUrl || '/';
                }
            }
        }
    } catch (err) {
        // Expected for message HTML payloads
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
