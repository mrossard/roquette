let eventSource = null;
let currentHubUrl = null;
let reconnectTimeout = null;
let reconnectAttempts = 0;
const maxReconnectDelay = 30000;

let isUnloading = false;
window.addEventListener('beforeunload', () => {
    isUnloading = true;
});

let typingUsers = new Map();
let typingUserTimeouts = new Map();
let typingTimeout = null;
let isCurrentlyTyping = false;

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

function handleMessageDeleted(data) {
    const messageId = data.messageId;
    const activeFeedContainer = document.getElementById('live-feed');
    if (activeFeedContainer) {
        const feedItem = activeFeedContainer.querySelector(`[data-message-id="${messageId}"]`);
        if (feedItem) {
            feedItem.style.transition = 'all 0.3s ease';
            feedItem.style.opacity = '0';
            feedItem.style.transform = 'translateX(-20px)';
            setTimeout(() => {
                feedItem.remove();
            }, 300);
        }
    }
    const pinnedBanner = document.getElementById('pinned-banner-container');
    if (pinnedBanner) {
        const jumpBtn = pinnedBanner.querySelector(`button[onclick*="scrollToMessage(${messageId})"]`);
        if (jumpBtn) {
            pinnedBanner.innerHTML = '';
        }
    }
}

function handleUserStatusChanged(data) {
    const username = data.username;
    const newStatus = data.status;
    const label = data.statusLabel;
    console.log(`Updating status for user ${username} to ${newStatus}`);
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
}

function handleThreadReply(data, activeChannelSlug, unreadFilterActive, unreadFilterBtn) {
    // Update thread replies pane if the current thread matches
    const threadPanel = document.getElementById('thread-panel');
    const threadContent = threadPanel ? threadPanel.querySelector('.thread-content') : null;
    if (threadPanel && threadContent && parseInt(threadContent.dataset.parentId) === data.parentId) {
        const repliesFeed = document.getElementById('thread-replies-feed');
        if (repliesFeed) {
            // Remove empty state
            const emptyState = repliesFeed.querySelector('.thread-empty-state');
            if (emptyState) emptyState.remove();

            // Parse and append new reply
            const tempDiv = document.createElement('div');
            tempDiv.innerHTML = data.html.trim();
            const newReply = tempDiv.firstChild;
            const repliesList = repliesFeed.querySelector('.thread-replies-list');
            if (repliesList && newReply) {
                // Deduplication: skip if already injected (e.g. via HTMX OOB for the author)
                const messageId = newReply.getAttribute ? newReply.getAttribute('data-message-id') : null;
                const alreadyExists = messageId && repliesList.querySelector(`[data-message-id="${messageId}"]`);
                if (!alreadyExists) {
                    repliesList.appendChild(newReply);
                    if (window.htmx) window.htmx.process(newReply);
                    if (window.updateEditButtonsVisibility) {
                        window.updateEditButtonsVisibility();
                    }
                    if (window.highlightAllCodeBlocks) {
                        window.highlightAllCodeBlocks(newReply);
                    }
                    if (window.initLinkPreviews) {
                        window.initLinkPreviews(newReply);
                    }
                }
            }

            // Scroll thread feed to bottom
            repliesFeed.scrollTo({ top: repliesFeed.scrollHeight, behavior: 'smooth' });
        }
    }

    // Update reply count badge on the parent message (if visible in feed)
    const parentFeedItem = document.querySelector(`[data-message-id="${data.parentId}"]`);
    if (parentFeedItem) {
        const badgeContainer = parentFeedItem.querySelector(`#message-thread-badge-${data.parentId}`);
        if (badgeContainer) {
            const badgeText = badgeContainer.querySelector('.thread-badge-text');
            if (badgeText) {
                const currentCount = data.replyCount || 1;
                badgeText.textContent = `${currentCount} ${currentCount > 1 ? 'réponses' : 'réponse'}`;
                badgeContainer.style.display = 'block';
            }
        }
    } else if (unreadFilterActive) {
        // Parent not in the feed yet → refresh the unread filter to show it
        const unreadUrl = unreadFilterBtn.getAttribute('hx-get') || unreadFilterBtn.getAttribute('data-hx-get');
        if (unreadUrl && window.htmx) {
            window.htmx.ajax('GET', unreadUrl, { target: '#live-feed', swap: 'innerHTML' });
        }
    }

    if (window.updateChannelLastMessageDate) {
        window.updateChannelLastMessageDate(data.channelSlug);
    }
}

function handleFeedItemUpdateOrAppend(data, activeFeedContainer) {
    // Create a temporary container to parse HTML string safely
    const tempDiv = document.createElement('div');
    tempDiv.innerHTML = data.html.trim();
    const newFeedItem = tempDiv.firstChild;
    const messageId = newFeedItem.getAttribute('data-message-id');

    // Check if this message already exists in the feed (for edits)
    const existingFeedItem = (messageId && messageId !== '') ? activeFeedContainer.querySelector(`[data-message-id="${messageId}"]`) : null;

    if (existingFeedItem) {
        // Replace the existing feed item
        activeFeedContainer.replaceChild(newFeedItem, existingFeedItem);
    } else {
        // Remove empty state if present
        const emptyState = document.getElementById('feed-empty-state');
        if (emptyState) {
            emptyState.remove();
        }

        // Append new feed item to the bottom of the feed (chronological)
        activeFeedContainer.appendChild(newFeedItem);
    }

    // Initialize HTMX on the new element
    if (window.htmx) window.htmx.process(newFeedItem);

    // Correct edit button visibility
    if (window.updateEditButtonsVisibility) {
        window.updateEditButtonsVisibility();
    }

    // Highlight code blocks
    if (window.highlightAllCodeBlocks) {
        window.highlightAllCodeBlocks(newFeedItem);
    }

    // Initialize link previews
    if (window.initLinkPreviews) {
        window.initLinkPreviews(newFeedItem);
    }

    if (!existingFeedItem) {
        // Scroll to bottom smoothly for new messages
        if (window.scrollToBottom) {
            window.scrollToBottom(true);
        }

        // Update last message date in the sidebar
        if (window.updateChannelLastMessageDate) {
            window.updateChannelLastMessageDate(data.channelSlug);
        }
    }
}

function handleHtmlUpdate(data) {
    const statusBadge = document.getElementById('mercure-status');
    const activeChannelSlug = statusBadge ? statusBadge.getAttribute('data-active-channel-slug') : null;
    const searchActive = statusBadge ? statusBadge.hasAttribute('data-search-active') : false;
    const unreadFilterBtn = document.getElementById('btn-unread-filter');
    const unreadFilterActive = unreadFilterBtn && unreadFilterBtn.classList.contains('active');
    
    if (data.channelSlug === activeChannelSlug && !searchActive) {
        // If this is a thread reply, update thread pane (if open) and reply count badge
        if (data.parentId) {
            handleThreadReply(data, activeChannelSlug, unreadFilterActive, unreadFilterBtn);
            return;
        }

        // Find feed container again to ensure it exists in active DOM
        const activeFeedContainer = document.getElementById('live-feed');
        if (!activeFeedContainer) return;

        handleFeedItemUpdateOrAppend(data, activeFeedContainer);
    } else if (data.parentId) {
        // Thread reply on a channel we're NOT currently viewing:
        // delegate to handleGlobalNotification which already filters own messages
        // via data.author === currentUsername (now included in the payload).
        if (window.handleGlobalNotification) {
            window.handleGlobalNotification(data);
        }
        if (window.updateChannelLastMessageDate) {
            window.updateChannelLastMessageDate(data.channelSlug);
        }
    }
}

export function connectMercure(isReconnect = false) {
    const statusBadge = document.getElementById('mercure-status');
    const feedContainer = document.getElementById('live-feed');
    
    if (!statusBadge || !feedContainer) {
        if (eventSource) {
            console.log('Closing Mercure EventSource (elements no longer exist)...');
            eventSource.close();
            eventSource = null;
            currentHubUrl = null;
        }
        return;
    }

    const hubUrl = statusBadge.getAttribute('data-hub');
    const topicUrl = statusBadge.getAttribute('data-topic');

    if (!isReconnect && hubUrl === currentHubUrl && eventSource && eventSource.readyState !== EventSource.CLOSED) {
        // Already connected to the correct hub topic, but the DOM element might have been replaced (e.g. by HTMX)
        // update the status UI accordingly
        if (eventSource.readyState === EventSource.OPEN) {
            updateMercureStatus(true, 'Connecté au Hub');
            showOfflineBanner(false);
        } else if (eventSource.readyState === EventSource.CONNECTING) {
            updateMercureStatus(false, 'Connexion au Hub...', 'connecting');
            showOfflineBanner(true, 'Connexion au serveur instable. Reconnexion en cours...');
        }
        return;
    }

    if (eventSource) {
        console.log('Closing existing Mercure EventSource connection...');
        eventSource.close();
        eventSource = null;
    }

    // Reset reconnect state on new hub URL connection or fresh load
    if (!isReconnect) {
        if (reconnectTimeout) {
            clearTimeout(reconnectTimeout);
            reconnectTimeout = null;
        }
        reconnectAttempts = 0;
        showOfflineBanner(false);
    }

    if (!hubUrl) {
        console.error('Mercure Hub URL is missing. Make sure MERCURE_PUBLIC_URL is configured.');
        updateMercureStatus(false, 'Erreur: Hub non configuré');
        return;
    }

    currentHubUrl = hubUrl;
    
    let connectionUrl = hubUrl;
    const lastEventId = localStorage.getItem('mercureLastEventId');
    if (lastEventId) {
        try {
            const urlObj = new URL(hubUrl, window.location.origin);
            urlObj.searchParams.set('Last-Event-ID', lastEventId);
            connectionUrl = urlObj.toString();
            console.log(`Resuming Mercure connection from Last-Event-ID: ${lastEventId}`);
        } catch (urlErr) {
            console.error('Error appending Last-Event-ID:', urlErr);
        }
    }
    
    console.log(`Connecting to Mercure Hub at: ${connectionUrl} for topic: ${topicUrl}`);
    
    try {
        eventSource = new EventSource(connectionUrl, { withCredentials: true });

        eventSource.onopen = () => {
            console.log('Successfully connected to Mercure Hub!');
            reconnectAttempts = 0;
            if (reconnectTimeout) {
                clearTimeout(reconnectTimeout);
                reconnectTimeout = null;
            }
            updateMercureStatus(true, 'Connecté au Hub');
            showOfflineBanner(false);
            if (typeof window.scrollToBottom === 'function') {
                window.scrollToBottom(false); // Initial scroll to bottom (immediate)
            }
        };

        eventSource.onmessage = (event) => {
            if (event.lastEventId) {
                localStorage.setItem('mercureLastEventId', event.lastEventId);
            }
            try {
                const data = JSON.parse(event.data);
                console.log('Received Mercure Update:', data);

                if (data.type === 'invitation_received') {
                    if (window.handleInvitationNotification) {
                        window.handleInvitationNotification(data);
                    }
                } else if (data.type === 'channel_deleted') {
                    if (window.handleChannelDeletedNotification) {
                        window.handleChannelDeletedNotification(data);
                    }
                } else if (data.type === 'pin_change') {
                    handlePinChange(data);
                } else if (data.type === 'message_deleted') {
                    handleMessageDeleted(data);
                } else if (data.type === 'user_typing') {
                    handleUserTyping(data);
                } else if (data.type === 'user_status_changed') {
                    handleUserStatusChanged(data);
                } else if (data.html) {
                    handleHtmlUpdate(data);
                } else if (data.channelSlug) {
                    // Global message notification (personal topic, no html)
                    if (window.handleGlobalNotification) {
                        window.handleGlobalNotification(data);
                    }
                    if (window.updateChannelLastMessageDate) {
                        window.updateChannelLastMessageDate(data.channelSlug);
                    }
                }
            } catch (err) {
                console.error('Error parsing Mercure message event data:', err);
            }
        };

        eventSource.onerror = (err) => {
            if (isUnloading) return;
            console.error('Mercure EventSource error:', err);
            if (eventSource.readyState === EventSource.CLOSED) {
                updateMercureStatus(false, 'Connexion interrompue', 'disconnected');
                showOfflineBanner(true, 'Connexion au serveur perdue. Tentative de reconnexion...');
                handleReconnect();
            } else {
                updateMercureStatus(false, 'Reconnexion en cours...', 'connecting');
                showOfflineBanner(true, 'Connexion au serveur instable. Reconnexion en cours...');
            }
        };

    } catch (e) {
        console.error('Error establishing Mercure EventSource:', e);
        updateMercureStatus(false, 'Erreur de connexion');
        showOfflineBanner(true, 'Impossible d\'établir la connexion.');
        handleReconnect();
    }
}

export function handleReconnect() {
    if (isUnloading) return;
    if (reconnectTimeout) return;

    const delay = Math.min(1000 * Math.pow(2, reconnectAttempts), maxReconnectDelay);
    reconnectAttempts++;

    console.log(`Scheduling Mercure reconnect in ${delay}ms (attempt ${reconnectAttempts})`);
    
    const activeStatusBadge = document.getElementById('mercure-status');
    if (activeStatusBadge) {
        const dot = activeStatusBadge.querySelector('.status-dot');
        const label = activeStatusBadge.querySelector('.status-text');
        if (dot && label) {
            dot.className = 'status-dot connecting';
            label.textContent = 'Tentative de reconnexion...';
        }
    }

    reconnectTimeout = setTimeout(() => {
        reconnectTimeout = null;
        connectMercure(true);
    }, delay);
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
        // If we got redirected to login page, or response is 401/403/etc.
        if (response.redirected && response.url.includes('/login')) {
            window.location.href = '/login';
            return;
        }
        if (!response.ok) {
            // Check if it's HTML (likely Symfony's redirect to login page returned as HTML)
            const contentType = response.headers.get('content-type');
            if (contentType && contentType.includes('text/html')) {
                window.location.href = '/login';
                return;
            }
            throw new Error('Session invalid');
        }
        
        // Session is valid, attempt to reconnect Mercure immediately
        console.log('Session is valid. Retrying Mercure connection immediately...');
        if (button) {
            button.textContent = 'Connexion...';
        }
        connectMercure(false);
    })
    .catch(err => {
        console.error('Manual reconnect failed checking session:', err);
        // If session check fails or is invalid, redirect to login to renew session
        window.location.href = '/login';
    });
}

export function initTypingIndicator() {
    const messageInput = document.getElementById('message');
    if (!messageInput) return;

    const statusBadge = document.getElementById('mercure-status');
    const channelSlug = statusBadge ? statusBadge.getAttribute('data-active-channel-slug') : null;
    if (!channelSlug) return;

    // Reset typing states on channel initialization
    typingUsers.clear();
    typingUserTimeouts.forEach(timeout => clearTimeout(timeout));
    typingUserTimeouts.clear();
    updateTypingIndicatorUI();

    messageInput.addEventListener('keydown', (e) => {
        // Submit on Enter key (unless Shift or Alt is pressed)
        if (e.key === 'Enter' && !e.shiftKey && !e.altKey) {
            if (isCurrentlyTyping) {
                isCurrentlyTyping = false;
                sendTypingStatus(channelSlug, false);
            }
            return;
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
            // Textarea was cleared
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

export function handleUserTyping(data) {
    const statusBadge = document.getElementById('mercure-status');
    const activeChannelSlug = statusBadge ? statusBadge.getAttribute('data-active-channel-slug') : null;
    const currentUsername = statusBadge ? statusBadge.getAttribute('data-current-username') : null;

    if (data.channelSlug !== activeChannelSlug || data.username === currentUsername) {
        return;
    }

    if (typingUserTimeouts.has(data.username)) {
        clearTimeout(typingUserTimeouts.get(data.username));
        typingUserTimeouts.delete(data.username);
    }

    if (data.isTyping) {
        typingUsers.set(data.username, data.displayName);

        // Auto remove typing status if connection gets lost or user stops typing without triggering event
        const timeout = setTimeout(() => {
            typingUsers.delete(data.username);
            typingUserTimeouts.delete(data.username);
            updateTypingIndicatorUI();
        }, 6000);

        typingUserTimeouts.set(data.username, timeout);
    } else {
        typingUsers.delete(data.username);
    }

    updateTypingIndicatorUI();
}

export function updateTypingIndicatorUI() {
    const indicator = document.getElementById('typing-indicator');
    if (!indicator) return;

    if (typingUsers.size === 0) {
        indicator.style.display = 'none';
        indicator.innerHTML = '';
        return;
    }

    const names = Array.from(typingUsers.values());
    let text = '';
    if (names.length === 1) {
        text = `<strong>${escapeHtml(names[0])}</strong> est en train d'écrire...`;
    } else if (names.length === 2) {
        text = `<strong>${escapeHtml(names[0])}</strong> et <strong>${escapeHtml(names[1])}</strong> sont en train d'écrire...`;
    } else if (names.length > 2) {
        text = `Plusieurs personnes sont en train d'écrire...`;
    }

    indicator.innerHTML = `
        <div class="typing-dots-container">
            <span class="typing-dot"></span>
            <span class="typing-dot"></span>
            <span class="typing-dot"></span>
        </div>
        <span class="typing-text">${text}</span>
    `;
    indicator.style.display = 'flex';
}

function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

export function handlePinChange(data) {
    const statusBadge = document.getElementById('mercure-status');
    const activeChannelSlug = statusBadge ? statusBadge.getAttribute('data-active-channel-slug') : null;
    if (data.channelSlug !== activeChannelSlug) {
        return;
    }

    const bannerContainer = document.getElementById('pinned-banner-container');
    if (bannerContainer) {
        bannerContainer.innerHTML = data.bannerHtml || '';
        if (window.htmx) window.htmx.process(bannerContainer);
    }

    if (data.messageHtml && data.messageId) {
        const activeFeedContainer = document.getElementById('live-feed');
        if (activeFeedContainer) {
            const existingFeedItem = activeFeedContainer.querySelector(`[data-message-id="${data.messageId}"]`);
            if (existingFeedItem) {
                const tempDiv = document.createElement('div');
                tempDiv.innerHTML = data.messageHtml.trim();
                const newFeedItem = tempDiv.firstChild;
                activeFeedContainer.replaceChild(newFeedItem, existingFeedItem);
                if (window.htmx) window.htmx.process(newFeedItem);
            }
        }
    }

    if (data.previousMessageHtml && data.previousMessageId) {
        const activeFeedContainer = document.getElementById('live-feed');
        if (activeFeedContainer) {
            const existingFeedItem = activeFeedContainer.querySelector(`[data-message-id="${data.previousMessageId}"]`);
            if (existingFeedItem) {
                const tempDiv = document.createElement('div');
                tempDiv.innerHTML = data.previousMessageHtml.trim();
                const newFeedItem = tempDiv.firstChild;
                activeFeedContainer.replaceChild(newFeedItem, existingFeedItem);
                if (window.htmx) window.htmx.process(newFeedItem);
            }
        }
    }

    if (typeof window.updateEditButtonsVisibility === 'function') {
        window.updateEditButtonsVisibility();
    }
}

// Global window binds
window.connectMercure = connectMercure;
window.initTypingIndicator = initTypingIndicator;
window.handleUserTyping = handleUserTyping;
window.handlePinChange = handlePinChange;
window.showOfflineBanner = showOfflineBanner;
window.manualReconnect = manualReconnect;
