import './styles/app.css';
import htmx from 'htmx.org';
window.htmx = htmx;

// Import our structured modules
import './ui.js';
import './mercure.js';
import './notifications.js';
import './editor.js';
import './autocomplete.js';
import './emoji.js';
import './thread.js';
import './offline.js';

console.log('Roquette application initialized! 🚀');

// Auto-resize textarea setup
function initAutoResizeTextarea() {
    const textarea = document.getElementById('message');
    if (!textarea) return;

    if (textarea.hasAttribute('data-autoresize-initialized')) return;
    textarea.setAttribute('data-autoresize-initialized', 'true');

    const adjustHeight = () => {
        textarea.style.height = 'auto';
        textarea.style.height = textarea.scrollHeight + 'px';
    };

    textarea.addEventListener('input', adjustHeight);
    adjustHeight();
}

window.toggleMessageReactionPicker = function(event, messageId) {
    event.stopPropagation();
    const picker = document.getElementById(`reaction-picker-${messageId}`);
    if (!picker) return;
    
    // Close other open reaction pickers first
    document.querySelectorAll('.reaction-picker.show').forEach(p => {
        if (p !== picker) {
            p.classList.remove('show');
        }
    });
    
    picker.classList.toggle('show');
};

// Global click/escape handlers to close picker
document.addEventListener('click', (e) => {
    // Close message reaction pickers when clicking outside
    if (!e.target.closest('.reaction-picker') && !e.target.closest('.btn-add-reaction')) {
        document.querySelectorAll('.reaction-picker.show').forEach(p => {
            p.classList.remove('show');
        });
    }
});

document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        document.querySelectorAll('.reaction-picker.show').forEach(p => {
            p.classList.remove('show');
        });
    }
});

document.addEventListener('htmx:configRequest', (evt) => {
    const statusBadge = document.getElementById('mercure-status');
    if (statusBadge) {
        const activeChannelSlug = statusBadge.getAttribute('data-active-channel-slug');
        if (activeChannelSlug) {
            evt.detail.headers['X-Previous-Channel'] = activeChannelSlug;
        }
    }
});

// Handle file upload loading indicator and progress updates
document.body.addEventListener('htmx:beforeRequest', (evt) => {
    const elt = evt.detail.elt;
    if (!elt) return;

    const isMainForm = elt.classList.contains('chat-message-form') && !elt.classList.contains('thread-message-form');
    const isThreadForm = elt.classList.contains('thread-message-form');

    if (isMainForm) {
        const fileInput = document.getElementById('file-upload');
        if (fileInput && fileInput.files && fileInput.files.length > 0) {
            const progressWrapper = document.getElementById('file-upload-progress');
            const progressBar = document.getElementById('file-upload-progress-bar');
            const progressPercent = document.getElementById('file-upload-progress-percent');
            if (progressWrapper && progressBar && progressPercent) {
                progressWrapper.style.display = 'block';
                progressBar.style.width = '0%';
                progressPercent.textContent = '0%';
            }
            const submitBtn = elt.querySelector('button[type="submit"]');
            if (submitBtn) submitBtn.disabled = true;
            const clearBtn = document.getElementById('btn-clear-file');
            if (clearBtn) clearBtn.style.display = 'none';
        }
    } else if (isThreadForm) {
        const fileInput = document.getElementById('thread-file-upload');
        if (fileInput && fileInput.files && fileInput.files.length > 0) {
            const progressWrapper = document.getElementById('thread-file-upload-progress');
            const progressBar = document.getElementById('thread-file-upload-progress-bar');
            const progressPercent = document.getElementById('thread-file-upload-progress-percent');
            if (progressWrapper && progressBar && progressPercent) {
                progressWrapper.style.display = 'block';
                progressBar.style.width = '0%';
                progressPercent.textContent = '0%';
            }
            const submitBtn = elt.querySelector('button[type="submit"]');
            if (submitBtn) submitBtn.disabled = true;
            const clearBtn = document.getElementById('thread-btn-clear-file');
            if (clearBtn) clearBtn.style.display = 'none';
        }
    }
});

document.body.addEventListener('htmx:xhr:progress', (evt) => {
    const elt = evt.detail.elt;
    if (!elt) return;

    const isMainForm = elt.classList.contains('chat-message-form') && !elt.classList.contains('thread-message-form');
    const isThreadForm = elt.classList.contains('thread-message-form');

    if (isMainForm) {
        const fileInput = document.getElementById('file-upload');
        if (fileInput && fileInput.files && fileInput.files.length > 0) {
            const progressBar = document.getElementById('file-upload-progress-bar');
            const progressPercent = document.getElementById('file-upload-progress-percent');
            if (progressBar && progressPercent && (evt.detail.lengthComputable || evt.detail.total > 0)) {
                const percent = Math.round((evt.detail.loaded / evt.detail.total) * 100);
                progressBar.style.width = percent + '%';
                progressPercent.textContent = percent + '%';
            }
        }
    } else if (isThreadForm) {
        const fileInput = document.getElementById('thread-file-upload');
        if (fileInput && fileInput.files && fileInput.files.length > 0) {
            const progressBar = document.getElementById('thread-file-upload-progress-bar');
            const progressPercent = document.getElementById('thread-file-upload-progress-percent');
            if (progressBar && progressPercent && (evt.detail.lengthComputable || evt.detail.total > 0)) {
                const percent = Math.round((evt.detail.loaded / evt.detail.total) * 100);
                progressBar.style.width = percent + '%';
                progressPercent.textContent = percent + '%';
            }
        }
    }
});

document.body.addEventListener('htmx:afterRequest', (evt) => {
    const progressWrapper = document.getElementById('file-upload-progress');
    if (progressWrapper) progressWrapper.style.display = 'none';
    const threadProgressWrapper = document.getElementById('thread-file-upload-progress');
    if (threadProgressWrapper) threadProgressWrapper.style.display = 'none';

    // Restore buttons if request finished
    const elt = evt.detail.elt;
    if (elt) {
        const submitBtn = elt.querySelector('button[type="submit"]');
        if (submitBtn) submitBtn.disabled = false;
    }
    const clearBtn = document.getElementById('btn-clear-file');
    if (clearBtn) clearBtn.style.display = '';
    const threadClearBtn = document.getElementById('thread-btn-clear-file');
    if (threadClearBtn) threadClearBtn.style.display = '';
});

// Preserve scroll position when loading older messages (prepending to top of #live-feed)
let loadMoreScrollTracker = null;

document.body.addEventListener('htmx:beforeSwap', (evt) => {
    // Allow swapping for validation/rate limit errors (400, 422, 429)
    if (evt.detail.xhr.status === 400 || evt.detail.xhr.status === 422 || evt.detail.xhr.status === 429) {
        evt.detail.shouldSwap = true;
        evt.detail.isError = false;
    }

    const target = evt.detail.target;
    if (target && (target.id === 'load-more-trigger' || target.classList.contains('load-more-container'))) {
        const feed = document.getElementById('live-feed');
        if (feed) {
            loadMoreScrollTracker = {
                scrollHeight: feed.scrollHeight,
                scrollTop: feed.scrollTop
            };
        }
    }
});

document.body.addEventListener('htmx:afterSwap', (evt) => {
    const target = evt.detail.target;
    if (target && (target.id === 'load-more-trigger' || target.classList.contains('load-more-container')) && loadMoreScrollTracker) {
        const feed = document.getElementById('live-feed');
        if (feed) {
            const heightDifference = feed.scrollHeight - loadMoreScrollTracker.scrollHeight;
            feed.scrollTop = loadMoreScrollTracker.scrollTop + heightDifference;
        }
        loadMoreScrollTracker = null;
    }
});

document.addEventListener('DOMContentLoaded', () => {
    // Initial connection
    if (window.connectMercure) window.connectMercure();
    if (window.scrollToBottom) window.scrollToBottom(false);
    if (window.updateEditButtonsVisibility) window.updateEditButtonsVisibility();
    if (window.highlightAllCodeBlocks) {
        window.highlightAllCodeBlocks();
    }
    if (window.initLinkPreviews) window.initLinkPreviews();
    if (window.initEmojiPickers) window.initEmojiPickers();
    if (window.initEmojiAutocomplete) window.initEmojiAutocomplete();
    initAutoResizeTextarea();
    if (window.initFileUpload) window.initFileUpload();
    if (window.setupNotificationHeaderButton) window.setupNotificationHeaderButton();
    if (window.updateSettingsPageUI) window.updateSettingsPageUI();
    if (window.initTypingIndicator) window.initTypingIndicator();
    if (window.initChannelReordering) window.initChannelReordering();
    if (window.initUnreadFilter) window.initUnreadFilter();
    if (window.initConfirmModals) window.initConfirmModals();
    if (window.initMessageHistoryCapture) window.initMessageHistoryCapture();
    if (window.initOfflineQueue) window.initOfflineQueue();
    if (window.initGlobalSearch) window.initGlobalSearch();

    // Heartbeat to keep user status online
    if (document.getElementById('mercure-status')) {
        const sendHeartbeat = () => {
            fetch('/user/ping', {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            }).catch(err => console.error('Heartbeat failed:', err));
        };
        sendHeartbeat();
        setInterval(sendHeartbeat, 60000);
    }

    // Focus message input on load
    const messageInput = document.getElementById('message');
    if (messageInput) {
        messageInput.focus();
    }
    checkJumpToMessage();

    // Reconnect and scroll when HTMX swaps content (e.g. switching channels)
    document.body.addEventListener('htmx:afterSwap', (evt) => {
        if (evt.detail.target && evt.detail.target.id === 'global-search-results') {
            return;
        }
        if (window.scrollToBottom) window.scrollToBottom(false);
    });

    document.body.addEventListener('htmx:afterSettle', (evt) => {
        if (evt.detail.target && evt.detail.target.id === 'global-search-results') {
            return;
        }
        console.log('HTMX content settled. Checking Mercure connection...');
        // Cancel any pending inline edit when switching channels
        if (window.cancelInlineEdit) {
            window.cancelInlineEdit();
        }
        if (window.connectMercure) window.connectMercure();
        if (window.scrollToBottom) window.scrollToBottom(false);
        if (window.updateEditButtonsVisibility) window.updateEditButtonsVisibility();
        if (window.highlightAllCodeBlocks) {
            window.highlightAllCodeBlocks();
        }
        if (window.initLinkPreviews) window.initLinkPreviews();
        if (window.initEmojiPickers) window.initEmojiPickers();
        if (window.initEmojiAutocomplete) window.initEmojiAutocomplete();
        initAutoResizeTextarea();
        if (window.initFileUpload) window.initFileUpload();
        if (window.setupNotificationHeaderButton) window.setupNotificationHeaderButton();
        if (window.updateSettingsPageUI) window.updateSettingsPageUI();
        if (window.initTypingIndicator) window.initTypingIndicator();
        if (window.initChannelReordering) window.initChannelReordering();
        if (window.initUnreadFilter) window.initUnreadFilter();
        if (window.initMessageHistoryCapture) window.initMessageHistoryCapture();
        if (window.renderChannelOfflineMessages) window.renderChannelOfflineMessages();

        // Scroll thread replies to bottom if thread panel is open (handles OOB-injected replies)
        if (window.scrollToBottom) {
            window.scrollToBottom(true, 'thread-replies-feed');
        }

        // Refocus appropriate input after content swap/settle (unless search input is active)
        const searchInput = document.getElementById('channel-search-input');
        const globalSearchInput = document.getElementById('global-search-input');
        const isSearching = (searchInput && document.activeElement === searchInput) || 
                            (globalSearchInput && document.activeElement === globalSearchInput);
        if (!isSearching) {
            // If the thread panel is open, keep focus on the thread input
            const threadPanel = document.getElementById('thread-panel');
            const threadTextarea = document.getElementById('thread-message');
            if (threadPanel && threadPanel.style.display !== 'none' && threadTextarea) {
                threadTextarea.focus();
            } else {
                const messageInputAfterSettle = document.getElementById('message');
                if (messageInputAfterSettle) {
                    messageInputAfterSettle.focus();
                }
            }
        }
        checkJumpToMessage();
    });
});

// Global HTMX listener to toggle data-search-active when searching
document.body.addEventListener('htmx:configRequest', (evt) => {
    if (evt.detail.elt && evt.detail.elt.id === 'channel-search-input') {
        const query = evt.detail.elt.value.trim();
        const statusBadge = document.getElementById('mercure-status');
        if (statusBadge) {
            if (query !== '') {
                statusBadge.setAttribute('data-search-active', 'true');
            } else {
                statusBadge.removeAttribute('data-search-active');
            }
        }
    }
});

function checkJumpToMessage() {
    const urlParams = new URLSearchParams(window.location.search);
    const jumpTo = urlParams.get('jumpTo');
    if (jumpTo && window.scrollToMessage) {
        setTimeout(() => {
            window.scrollToMessage(parseInt(jumpTo));
            const cleanUrl = window.location.pathname;
            window.history.replaceState({}, document.title, cleanUrl);
        }, 300);
    }
}

