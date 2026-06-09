function isCurrentUserBusy() {
    const statusBadge = document.getElementById('mercure-status');
    if (!statusBadge) return false;
    const currentUsername = statusBadge.getAttribute('data-current-username');
    if (!currentUsername) return false;
    const currentUserDot = document.querySelector(`.status-dot[data-username="${currentUsername}"]`);
    return currentUserDot && currentUserDot.classList.contains('busy');
}

function playNotificationSound() {
    const soundEnabled = localStorage.getItem('roquette_notifications_sound') !== 'false';
    if (!soundEnabled) return;
    try {
        const ctx = new (window.AudioContext || window.webkitAudioContext)();
        const osc = ctx.createOscillator();
        const gain = ctx.createGain();
        osc.connect(gain);
        gain.connect(ctx.destination);
        osc.frequency.value = 400;
        gain.gain.setValueAtTime(0.3, ctx.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.15);
        osc.start(ctx.currentTime);
        osc.stop(ctx.currentTime + 0.15);
    } catch (e) {
        // Silently ignore audio errors
    }
}

export function sendDesktopNotification(title, body, icon = null, tag = null, url = null) {
    if (!('Notification' in window)) return;
    if (Notification.permission !== 'granted') return;
    if (isCurrentUserBusy()) return;

    // Check user preference in localStorage
    const enabled = localStorage.getItem('roquette_notifications_enabled') !== 'false';
    if (!enabled) return;

    playNotificationSound();

    // Use a custom rocket icon if none provided
    const notificationIcon = icon || 'data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 128 128"><text y="0.9em" font-size="90">🚀</text></svg>';

    const options = {
        body: body,
        icon: notificationIcon,
        tag: tag,
        renotify: tag ? true : false
    };

    try {
        const n = new Notification(title, options);
        if (url) {
            n.onclick = function(e) {
                e.preventDefault();
                window.focus();
                // Check if we are already on that URL to avoid reloading
                if (window.location.pathname !== url) {
                    if (window.htmx) {
                        window.htmx.ajax('GET', url, { target: 'body', pushUrl: true });
                    } else {
                        window.location.href = url;
                    }
                }
            };
        }
    } catch (e) {
        console.error('Error displaying notification:', e);
    }
}

export function setupNotificationHeaderButton() {
    let btn = document.getElementById('header-notification-btn');
    if (!btn) return;

    if (!('Notification' in window)) {
        btn.style.display = 'none';
        return;
    }

    btn.style.display = 'inline-flex';

    function updateButtonUI() {
        const permission = Notification.permission;
        const enabled = localStorage.getItem('roquette_notifications_enabled') !== 'false';
        const bell = btn.querySelector('.bell-icon') || document.createElement('span');
        bell.className = 'bell-icon';

        if (btn.querySelector('.bell-icon') === null) {
            btn.appendChild(bell);
        }

        if (permission === 'default') {
            bell.textContent = '🔔';
            bell.classList.add('bell-ring-active');
            let text = btn.querySelector('.btn-text');
            if (!text) {
                text = document.createElement('span');
                text.className = 'btn-text';
                btn.appendChild(text);
            }
            text.textContent = 'Activer les notifications';
            btn.title = "Activer les notifications de bureau";
            btn.style.border = '1px solid var(--accent-cyan)';
            btn.style.boxShadow = '0 0 8px rgba(0, 229, 255, 0.2)';
            btn.style.opacity = '1';
        } else {
            bell.classList.remove('bell-ring-active');
            const text = btn.querySelector('.btn-text');
            if (text) text.remove(); // hide text when authorized to keep it clean

            if (permission === 'granted') {
                if (enabled) {
                    bell.textContent = '🔔';
                    btn.title = "Notifications actives (cliquez pour désactiver)";
                    btn.style.border = '1px solid var(--border-glass)';
                    btn.style.boxShadow = 'none';
                    btn.style.opacity = '1';
                } else {
                    bell.textContent = '🔕';
                    btn.title = "Notifications désactivées (cliquez pour activer)";
                    btn.style.border = '1px solid var(--border-glass)';
                    btn.style.boxShadow = 'none';
                    btn.style.opacity = '0.6';
                }
            } else if (permission === 'denied') {
                bell.textContent = '🔕';
                btn.title = "Notifications bloquées. Modifiez les autorisations de votre navigateur.";
                btn.style.border = '1px solid var(--border-glass)';
                btn.style.boxShadow = 'none';
                btn.style.opacity = '0.4';
                btn.style.cursor = 'not-allowed';
            }
        }
    }

    // Expose updateUI function on the button itself so settings page can update it
    btn.updateUI = updateButtonUI;

    updateButtonUI();

    btn.addEventListener('click', (e) => {
        e.preventDefault();
        const permission = Notification.permission;

        if (permission === 'default') {
            Notification.requestPermission().then(newPermission => {
                if (newPermission === 'granted') {
                    localStorage.setItem('roquette_notifications_enabled', 'true');
                    sendDesktopNotification(
                        'Notifications activées ! 🚀',
                        'Vous recevrez désormais des alertes pour les nouveaux messages et invitations.'
                    );
                }
                updateButtonUI();
                updateSettingsPageUI();
            });
        } else if (permission === 'granted') {
            const currentlyEnabled = localStorage.getItem('roquette_notifications_enabled') !== 'false';
            localStorage.setItem('roquette_notifications_enabled', (!currentlyEnabled).toString());
            updateButtonUI();
            updateSettingsPageUI();

            if (!currentlyEnabled) {
                sendDesktopNotification(
                    'Notifications réactivées ! 🔔',
                    'Les alertes de bureau sont à nouveau actives.'
                );
            }
        }
    });
}

export function updateSettingsPageUI() {
    const container = document.getElementById('notification-settings-content');
    if (!container) return;

    if (!('Notification' in window)) {
        container.innerHTML = `
            <div class="notification-status-badge denied">
                <span>⚠️ Les notifications ne sont pas supportées par votre navigateur.</span>
            </div>
        `;
        return;
    }

    const permission = Notification.permission;
    const enabled = localStorage.getItem('roquette_notifications_enabled') !== 'false';

    if (permission === 'default') {
        container.innerHTML = `
            <div class="notification-status-badge default">
                <span>🔔 Les notifications ne sont pas encore configurées.</span>
            </div>
            <p style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 1.25rem;">
                Pour recevoir des alertes de bureau, vous devez autoriser Roquette à vous envoyer des notifications.
            </p>
            <button id="enable-notifications-btn" class="btn-submit" style="width: 100%;">
                Autoriser les notifications
            </button>
        `;

        document.getElementById('enable-notifications-btn')?.addEventListener('click', () => {
            Notification.requestPermission().then(newPermission => {
                if (newPermission === 'granted') {
                    localStorage.setItem('roquette_notifications_enabled', 'true');
                    sendDesktopNotification(
                        'Notifications activées ! 🚀',
                        'Vous recevrez désormais des alertes pour les nouveaux messages et invitations.'
                    );
                }
                updateSettingsPageUI();
                const headerBtn = document.getElementById('header-notification-btn');
                if (headerBtn && headerBtn.updateUI) {
                    headerBtn.updateUI();
                }
            });
        });
    } else if (permission === 'granted') {
        const soundEnabled = localStorage.getItem('roquette_notifications_sound') !== 'false';

        container.innerHTML = `
            <div class="notification-status-badge granted">
                <span>✅ Les notifications sont autorisées dans votre navigateur.</span>
            </div>

            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.75rem; padding: 0.75rem 1rem; background: rgba(0, 0, 0, 0.2); border-radius: 0.75rem; border: 1px solid var(--border-glass);">
                <span style="font-size: 0.9rem; color: var(--text-primary);">🔔 Notifications de bureau</span>
                <label class="switch" style="position: relative; display: inline-block; width: 44px; height: 24px;">
                    <input type="checkbox" id="notification-toggle-checkbox" ${enabled ? 'checked' : ''} style="opacity: 0; width: 0; height: 0;">
                    <span class="slider round" style="position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: ${enabled ? 'var(--accent-cyan)' : 'rgba(255, 255, 255, 0.1)'}; transition: .3s; border-radius: 24px; border: 1px solid var(--border-glass);"></span>
                </label>
            </div>

            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 1.25rem; padding: 0.75rem 1rem; background: rgba(0, 0, 0, 0.2); border-radius: 0.75rem; border: 1px solid var(--border-glass);">
                <span style="font-size: 0.9rem; color: var(--text-primary);">🔊 Son de notification</span>
                <label class="switch" style="position: relative; display: inline-block; width: 44px; height: 24px;">
                    <input type="checkbox" id="notification-sound-checkbox" ${soundEnabled ? 'checked' : ''} style="opacity: 0; width: 0; height: 0;">
                    <span class="slider round" style="position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: ${soundEnabled ? 'var(--accent-cyan)' : 'rgba(255, 255, 255, 0.1)'}; transition: .3s; border-radius: 24px; border: 1px solid var(--border-glass);"></span>
                </label>
            </div>

            <div style="display: flex; gap: 0.5rem;">
                <button id="test-notification-btn" class="btn-submit" style="flex: 1; background: rgba(255,255,255,0.05); border: 1px solid var(--border-glass); color: var(--text-primary);" ${!enabled ? 'disabled style="opacity: 0.5; cursor: not-allowed;"' : ''}>
                    Envoyer un test
                </button>
            </div>
        `;

        const checkbox = document.getElementById('notification-toggle-checkbox');
        const soundCheckbox = document.getElementById('notification-sound-checkbox');
        const slider = container.querySelector('.slider');
        const testBtn = document.getElementById('test-notification-btn');

        checkbox?.addEventListener('change', (e) => {
            const isChecked = e.target.checked;
            localStorage.setItem('roquette_notifications_enabled', isChecked.toString());

            if (slider) {
                slider.style.backgroundColor = isChecked ? 'var(--accent-cyan)' : 'rgba(255,255,255,0.1)';
            }

            if (testBtn) {
                if (isChecked) {
                    testBtn.removeAttribute('disabled');
                    testBtn.style.opacity = '1';
                    testBtn.style.cursor = 'pointer';
                } else {
                    testBtn.setAttribute('disabled', 'disabled');
                    testBtn.style.opacity = '0.5';
                    testBtn.style.cursor = 'not-allowed';
                }
            }

            const headerBtn = document.getElementById('header-notification-btn');
            if (headerBtn && headerBtn.updateUI) {
                headerBtn.updateUI();
            }
        });

        soundCheckbox?.addEventListener('change', (e) => {
            localStorage.setItem('roquette_notifications_sound', e.target.checked.toString());
            const soundSlider = soundCheckbox.nextElementSibling;
            if (soundSlider) {
                soundSlider.style.backgroundColor = e.target.checked ? 'var(--accent-cyan)' : 'rgba(255,255,255,0.1)';
            }
        });

        testBtn?.addEventListener('click', () => {
            sendDesktopNotification(
                'Test de notification 🚀',
                'Ceci est une notification de test de Roquette.'
            );
        });
    } else if (permission === 'denied') {
        container.innerHTML = `
            <div class="notification-status-badge denied">
                <span>❌ Les notifications sont bloquées par votre navigateur.</span>
            </div>
            <p style="font-size: 0.85rem; color: var(--text-muted); margin-top: 0.5rem; margin-bottom: 0;">
                Veuillez autoriser les notifications pour ce site dans les paramètres de votre navigateur afin de pouvoir recevoir les alertes.
            </p>
        `;
    }
}

export function handleGlobalNotification(data) {
    if (isCurrentUserBusy()) return;
    const statusBadge = document.getElementById('mercure-status');
    if (!statusBadge) return;

    const activeChannelSlug = statusBadge.getAttribute('data-active-channel-slug');
    const currentUsername = statusBadge.getAttribute('data-current-username');

    if (data.author === currentUsername) {
        // Ignore messages authored by the current user
        return;
    }

    // Trigger desktop notification if not looking at the active channel, or if looking but the page is blurred
    const isViewingActiveChannel = (data.channelSlug === activeChannelSlug);
    const isPageActive = (document.visibilityState === 'visible' && document.hasFocus());

    // If it's a mention, we notify unless the page is visible and active on the channel or mention notifications are disabled
    const shouldNotify = (!isViewingActiveChannel || !isPageActive) && (
        (data.isMention && data.isMentionNotificationAllowed !== false) ||
        data.notificationsEnabled
    );

    if (shouldNotify) {
        const title = data.isMention ? `Mention dans ${data.channelName}` : (data.channelName || 'Nouveau message 🚀');
        const body = data.isMention ? `@${data.authorDisplayName || data.author} vous a mentionné : ${data.content}` : `@${data.authorDisplayName || data.author}: ${data.content || 'Nouveau message'}`;

        sendDesktopNotification(
            title,
            body,
            null,
            `channel-${data.channelSlug}`,
            `/channels/${data.channelSlug}`
        );
    }

    if (data.channelSlug === activeChannelSlug && isPageActive) {
        // We are currently viewing this channel and the page is active, so mark it as read in the DB in background
        const readUrl = `/channels/${data.channelSlug}/read`;
        fetch(readUrl, {method: 'POST', headers: {'X-Requested-With': 'XMLHttpRequest'}});
    } else {
        // We are on another channel, show/increment the unread badge
        const channelLink = document.querySelector(`.channel-link[data-channel-slug="${data.channelSlug}"]`);
        if (channelLink) {
            channelLink.classList.add('unread');
            if (data.isMention) {
                channelLink.classList.add('has-mention');
            }
            let badge = channelLink.querySelector('.unread-badge');
            if (!badge) {
                badge = document.createElement('span');
                badge.className = 'unread-badge';
                badge.textContent = '0';
                channelLink.appendChild(badge);
            }
            const currentCount = parseInt(badge.textContent, 10) || 0;
            badge.textContent = (currentCount + 1).toString();
            badge.style.display = 'inline-flex';
        } else if (data.isSubChannel && data.parentChannelId) {
            // Check if this sub-channel is already in the DOM
            const subChannelLink = document.querySelector(`.channel-link[data-channel-slug="${data.channelSlug}"]`);
            if (!subChannelLink) {
                // Fetch and insert it after all matching parent channel links in the sidebar
                const parentLinks = document.querySelectorAll(`.channel-link[data-channel-id="${data.parentChannelId}"]:not(.subchannel-link)`);
                if (parentLinks.length > 0) {
                    fetch(`/channels/${data.channelSlug}/sidebar-item`)
                        .then(response => response.text())
                        .then(html => {
                            parentLinks.forEach(parentLink => {
                                // Double check if it wasn't added in the meantime
                                const parentContainer = parentLink.parentNode;
                                if (parentContainer && !parentContainer.querySelector(`.channel-link[data-channel-slug="${data.channelSlug}"]`)) {
                                    parentLink.insertAdjacentHTML('afterend', html);
                                }
                            });
                            // Process HTMX for the new items
                            const sectionChannels = document.getElementById('section-channels');
                            if (sectionChannels) htmx.process(sectionChannels);
                            const favorites = document.getElementById('section-favorites');
                            if (favorites) htmx.process(favorites);
                            const dms = document.getElementById('section-dms');
                            if (dms) htmx.process(dms);
                        });
                }
            }
        } else if (data.isDm) {
            const dmsList = document.getElementById('section-dms');
            if (dmsList && !document.querySelector(`.channel-link[data-channel-slug="${data.channelSlug}"]`)) {
                const emptyState = dmsList.querySelector('p');
                if (emptyState && emptyState.textContent.includes('Aucun message direct')) {
                    emptyState.remove();
                }
                htmx.ajax('GET', `/channels/${data.channelSlug}/sidebar-item`, {
                    target: '#section-dms',
                    swap: 'beforeend'
                });
            }
        }
    }
}

export function handleInvitationNotification(data) {
    const statusBadge = document.getElementById('mercure-status');
    if (!statusBadge) return;

    const currentUsername = statusBadge.getAttribute('data-current-username');
    if (data.invitedUsername !== currentUsername) {
        return; // Invitation is not for us
    }

    // Show desktop notification
    const sender = data.senderName || 'Quelqu\'un';
    sendDesktopNotification(
        'Nouvelle invitation 🔒',
        `${sender} vous a invité à rejoindre le canal #${data.channelName}`,
        null,
        `invitation-${data.invitationId}`,
        `/channels/${data.channelSlug}`
    );

    // Check if channel is already present in sidebar
    const existingLink = document.querySelector(`.channel-link[data-channel-slug="${data.channelSlug}"]`);
    if (existingLink) {
        return;
    }

    // Check if invitation is already present in sidebar
    const existingInvite = document.getElementById(`invite-${data.invitationId}`);
    if (existingInvite) {
        return;
    }

    // Find the invitations list
    const invitationsSection = document.getElementById('invitations-section');
    const invitationsList = document.querySelector('.invitations-list');
    if (!invitationsSection || !invitationsList) return;

    // Parse the HTML string using a temp div
    const tempDiv = document.createElement('div');
    tempDiv.innerHTML = data.html.trim();
    const newInviteItem = tempDiv.firstChild;

    // Append to invitations list
    invitationsList.appendChild(newInviteItem);

    // Make sure section is visible
    invitationsSection.style.display = 'block';

    // Process with HTMX so it works when clicked
    if (window.htmx) {
        window.htmx.process(newInviteItem);
    }
}

export function handleChannelDeletedNotification(data) {
    // Remove the channel from the sidebar list
    const channelLink = document.querySelector(`.channel-link[data-channel-slug="${data.channelSlug}"]`);
    if (channelLink) {
        channelLink.remove();
    }

    // If the user is currently viewing the deleted channel, redirect them to '/'
    const statusBadge = document.getElementById('mercure-status');
    if (statusBadge) {
        const activeChannelSlug = statusBadge.getAttribute('data-active-channel-slug');
        if (data.channelSlug === activeChannelSlug) {
            console.log('Active channel was deleted. Redirecting to home...');
            window.location.href = '/';
        }
    }
}

// Expose functions globally to keep window compatibility
window.sendDesktopNotification = sendDesktopNotification;
window.setupNotificationHeaderButton = setupNotificationHeaderButton;
window.updateSettingsPageUI = updateSettingsPageUI;
window.handleGlobalNotification = handleGlobalNotification;
window.handleInvitationNotification = handleInvitationNotification;
window.handleChannelDeletedNotification = handleChannelDeletedNotification;

let originalFaviconHref = null;
let faviconImage = null;
let faviconObserver = null;
let observedTarget = null;
let lastTotalUnread = null;
let focusListenersInitialized = false;

export function markActiveChannelAsReadIfFocused() {
    const isPageActive = (document.visibilityState === 'visible' && document.hasFocus());
    if (!isPageActive) return;

    const statusBadge = document.getElementById('mercure-status');
    if (!statusBadge) return;

    const activeChannelSlug = statusBadge.getAttribute('data-active-channel-slug');
    if (!activeChannelSlug) return;

    const channelLink = document.querySelector(`.channel-link[data-channel-slug="${activeChannelSlug}"]`);
    if (!channelLink) return;

    const badge = channelLink.querySelector('.unread-badge');
    const isUnread = channelLink.classList.contains('unread') || (badge && badge.style.display !== 'none');

    if (isUnread) {
        channelLink.classList.remove('unread');
        channelLink.classList.remove('has-mention');
        if (badge) {
            badge.textContent = '0';
            badge.style.display = 'none';
        }

        const readUrl = `/channels/${activeChannelSlug}/read`;
        fetch(readUrl, {method: 'POST', headers: {'X-Requested-With': 'XMLHttpRequest'}});
        updateFaviconUnreadCount();
    }
}

export function updateFaviconUnreadCount() {
    const faviconLink = document.querySelector('link[rel="icon"]') || document.querySelector('link[rel="shortcut icon"]');
    if (!faviconLink) return;

    if (!originalFaviconHref) {
        originalFaviconHref = faviconLink.getAttribute('href');
    }

    let totalUnread = 0;
    document.querySelectorAll('.channel-link .unread-badge').forEach(badge => {
        // Check if the badge is visible and its parent is not active (or active but the page doesn't have focus)
        const channelLink = badge.closest('.channel-link');
        const isChannelActive = channelLink && channelLink.classList.contains('active');
        const isPageActive = (document.visibilityState === 'visible' && document.hasFocus());

        if (badge.style.display !== 'none' && (!isChannelActive || !isPageActive)) {
            const count = parseInt(badge.textContent, 10) || 0;
            totalUnread += count;
        }
    });

    if (totalUnread === lastTotalUnread) {
        return;
    }
    lastTotalUnread = totalUnread;

    // Update document title
    const cleanTitle = document.title.replace(/\s*\(\d+\s*messages?\s*non\s*lus\)/gi, '').replace(/^\(\d+\)\s*/, '');
    if (totalUnread > 0) {
        document.title = `(${totalUnread}) ${cleanTitle} (${totalUnread} message${totalUnread > 1 ? 's' : ''} non lus)`;
    } else {
        document.title = cleanTitle;
    }

    if (totalUnread <= 0) {
        faviconLink.href = originalFaviconHref;
        return;
    }

    const drawBadge = (img) => {
        const canvas = document.createElement('canvas');
        canvas.width = 64;
        canvas.height = 64;
        const ctx = canvas.getContext('2d');

        // Draw original favicon
        ctx.drawImage(img, 0, 0, 64, 64);

        // Draw a clean, bright red notification dot in the bottom-right corner (empty space)
        const badgeRadius = 10;
        const centerX = 50;
        const centerY = 50;

        ctx.beginPath();
        ctx.arc(centerX, centerY, badgeRadius, 0, 2 * Math.PI, false);
        ctx.fillStyle = '#ef4444'; // Red-500
        ctx.fill();

        // Dark/white border
        ctx.lineWidth = 3;
        ctx.strokeStyle = '#1e1b4b'; // matching the dark background color of Roquette
        ctx.stroke();

        faviconLink.href = canvas.toDataURL('image/png');
    };

    if (faviconImage) {
        drawBadge(faviconImage);
    } else {
        const img = new Image();
        img.crossOrigin = 'anonymous';
        img.src = originalFaviconHref;
        img.onload = () => {
            faviconImage = img;
            drawBadge(img);
        };
        img.onerror = () => {
            console.error('Failed to load favicon image');
        };
    }
}

export function initFaviconNotificationBadge() {
    lastTotalUnread = null; // Force refresh on initialization
    updateFaviconUnreadCount();

    if (!focusListenersInitialized) {
        window.addEventListener('focus', markActiveChannelAsReadIfFocused);
        document.addEventListener('visibilitychange', markActiveChannelAsReadIfFocused);
        focusListenersInitialized = true;
    }

    const currentTarget = document.getElementById('sidebar-panel') || document.body;

    // Check if the observer is already watching the correct element and it's still in the DOM
    if (faviconObserver && observedTarget && observedTarget.isConnected && observedTarget === currentTarget) {
        return;
    }

    if (faviconObserver) {
        faviconObserver.disconnect();
    }

    const observer = new MutationObserver(() => {
        updateFaviconUnreadCount();
    });
    observer.observe(currentTarget, {
        childList: true,
        subtree: true,
        characterData: true,
        attributes: true,
        attributeFilter: ['style', 'class']
    });
    faviconObserver = observer;
    observedTarget = currentTarget;
}

window.updateFaviconUnreadCount = updateFaviconUnreadCount;
window.initFaviconNotificationBadge = initFaviconNotificationBadge;
window.markActiveChannelAsReadIfFocused = markActiveChannelAsReadIfFocused;

