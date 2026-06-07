import './styles/app.css';
import htmx from 'htmx.org';
window.htmx = htmx;
import 'htmx-ext-sse';
import {initializeChannelScroll, adjustScrollForLinkPreview} from './scroll.js';

import hljs from 'highlight.js';
window.hljs = hljs;

import { Idiomorph } from 'idiomorph';
window.Idiomorph = Idiomorph;

// Register Idiomorph as a custom swap extension for HTMX
if (window.htmx && window.Idiomorph) {
    function createMorphConfig(swapStyle) {
        if (swapStyle === "morph" || swapStyle === "morph:outerHTML") {
            return { morphStyle: "outerHTML" };
        } else if (swapStyle === "morph:innerHTML") {
            return { morphStyle: "innerHTML" };
        } else if (swapStyle.startsWith("morph:")) {
            return Function("return (" + swapStyle.slice(6) + ")")();
        }
    }

    window.htmx.defineExtension("morph", {
        isInlineSwap: function (swapStyle) {
            let config = createMorphConfig(swapStyle);
            return config?.morphStyle === "outerHTML" || config?.morphStyle == null;
        },
        handleSwap: function (swapStyle, target, fragment) {
            let config = createMorphConfig(swapStyle);
            if (config) {
                return window.Idiomorph.morph(target, fragment.children, config);
            }
        },
    });
}

if (document.querySelector('meta[name="frankenphp-hot-reload:url"]')) {
    import('frankenphp-hot-reload');
}

// Import our structured modules
import './ui.js';
import './mercure.js';
import './notifications.js';
import './editor.js';
import './autocomplete.js';
import { buildEmojiPickerDOM } from './emoji.js';
import './thread.js';
import './offline.js';
import './poll.js';

console.log('Roquette application initialized! 🚀');

function initAutoResizeTextarea() {
    // Managed natively by CSS field-sizing: content
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

    const isShowing = !picker.classList.contains('show');
    picker.classList.toggle('show');

    if (isShowing) {
        // Dynamically move/initialize the full emoji picker inside this picker
        let emojiPickerContainer = document.getElementById('shared-reaction-emoji-picker');
        if (!emojiPickerContainer) {
            // Build the local custom emoji picker DOM
            const { element, focusSearch: focusSearchFn } = buildEmojiPickerDOM(emoji => {
                const msgId = emojiPickerContainer.dataset.messageId;
                if (emoji && msgId) {
                    const targetFeedItem = document.querySelector(`.feed-item[data-message-id="${msgId}"]`);
                    if (targetFeedItem) {
                        htmx.ajax('POST', `/messages/${msgId}/react/${encodeURIComponent(emoji)}`, {
                            target: targetFeedItem,
                            swap: 'outerHTML'
                        });
                    }
                }
                // Close the picker
                const activePicker = emojiPickerContainer.closest('.reaction-picker');
                if (activePicker) {
                    activePicker.classList.remove('show');
                }
            });
            emojiPickerContainer = element;
            emojiPickerContainer.id = 'shared-reaction-emoji-picker';

            // Store focusSearch function on the DOM element for subsequent clicks
            emojiPickerContainer.focusSearch = focusSearchFn;
        }

        emojiPickerContainer.dataset.messageId = messageId;
        picker.appendChild(emojiPickerContainer);

        // Reset positioning styles
        picker.style.left = '0';
        picker.style.right = 'auto';
        picker.style.top = '100%';
        picker.style.bottom = 'auto';
        picker.style.marginTop = '4px';
        picker.style.marginBottom = '0';

        // Handle horizontal overflow (avoid going off screen to the right)
        let rect = picker.getBoundingClientRect();
        if (rect.right > window.innerWidth) {
            picker.style.left = 'auto';
            picker.style.right = '0';
        }

        // Handle vertical overflow (avoid going off screen or under the message composer at the bottom)
        let bottomThreshold = window.innerHeight;
        const container = picker.closest('#live-feed, .thread-content');
        if (container && container.nextElementSibling) {
            const nextEl = container.nextElementSibling;
            if (nextEl.classList.contains('chat-input-area') || nextEl.classList.contains('thread-input-area')) {
                bottomThreshold = nextEl.getBoundingClientRect().top;
            }
        }

        if (rect.bottom > bottomThreshold) {
            picker.style.top = 'auto';
            picker.style.bottom = '100%';
            picker.style.marginTop = '0';
            picker.style.marginBottom = '8px';
        }

        if (emojiPickerContainer.focusSearch) {
            emojiPickerContainer.focusSearch();
        }
    }
};

// Global click/escape handlers to close picker
document.addEventListener('click', (e) => {
    // Close message reaction pickers when clicking outside
    if (!e.target.closest('.reaction-picker') && !e.target.closest('.btn-add-reaction')) {
        document.querySelectorAll('.reaction-picker.show').forEach(p => {
            p.classList.remove('show');
        });
    }
    // Close message actions list when clicking outside
    if (!e.target.closest('.feed-item-actions')) {
        document.querySelectorAll('.feed-item-actions-list.show').forEach(list => {
            list.classList.remove('show');
        });
    }
});

document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        document.querySelectorAll('.reaction-picker.show').forEach(p => {
            p.classList.remove('show');
        });
        document.querySelectorAll('.feed-item-actions-list.show').forEach(list => {
            list.classList.remove('show');
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
    // Show skeletons during page/channel navigation (when target is app-container or BODY)
    const target = evt.detail.target;
    if (target && (target.classList.contains('app-container') || target.tagName === 'BODY')) {
        const chatPanel = document.querySelector('.chat-panel');
        if (chatPanel) {
            chatPanel.classList.add('channel-loading');
        }
        const settingsPanel = document.querySelector('.settings-panel');
        if (settingsPanel) {
            settingsPanel.classList.add('settings-loading');
        }
    }

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
    // Remove loading skeletons classes
    const chatPanel = document.querySelector('.chat-panel');
    if (chatPanel) {
        chatPanel.classList.remove('channel-loading');
    }
    const settingsPanel = document.querySelector('.settings-panel');
    if (settingsPanel) {
        settingsPanel.classList.remove('settings-loading');
    }

    const progressWrapper = document.getElementById('file-upload-progress');
    if (progressWrapper) progressWrapper.style.display = 'none';
    const threadProgressWrapper = document.getElementById('thread-file-upload-progress');
    if (threadProgressWrapper) threadProgressWrapper.style.display = 'none';

    // Restore buttons if request finished
    const elt = evt.detail.elt;
    if (elt) {
        const submitBtn = elt.querySelector('button[type="submit"]');
        if (submitBtn) submitBtn.disabled = false;

        // Reset the file input if the form submission was successful
        if (evt.detail.successful) {
            const isMainForm = elt.classList.contains('chat-message-form') && !elt.classList.contains('thread-message-form');
            const isThreadForm = elt.classList.contains('thread-message-form');
            if (isMainForm) {
                const fileInput = document.getElementById('file-upload');
                if (fileInput) {
                    fileInput.value = '';
                    fileInput.dispatchEvent(new Event('change'));
                }
            } else if (isThreadForm) {
                const fileInput = document.getElementById('thread-file-upload');
                if (fileInput) {
                    fileInput.value = '';
                    fileInput.dispatchEvent(new Event('change'));
                }
            }
        }
    }
    const clearBtn = document.getElementById('btn-clear-file');
    if (clearBtn) clearBtn.style.display = '';
    const threadClearBtn = document.getElementById('thread-btn-clear-file');
    if (threadClearBtn) threadClearBtn.style.display = '';
});

document.body.addEventListener('htmx:beforeSwap', (evt) => {
    // Allow swapping for validation/rate limit errors (400, 422, 429)
    if (evt.detail.xhr.status === 400 || evt.detail.xhr.status === 422 || evt.detail.xhr.status === 429) {
        evt.detail.shouldSwap = true;
        evt.detail.isError = false;
    }
});

document.addEventListener('DOMContentLoaded', () => {
    // Initial connection
    if (window.connectMercure) window.connectMercure();
    if (window.updateEditButtonsVisibility) window.updateEditButtonsVisibility();
    if (window.highlightAllCodeBlocks) {
        window.highlightAllCodeBlocks();
    }
    if (window.initEmojiPickers) window.initEmojiPickers();
    if (window.initEmojiAutocomplete) window.initEmojiAutocomplete();
    initAutoResizeTextarea();
    if (window.initFileUpload) window.initFileUpload();
    if (window.setupNotificationHeaderButton) window.setupNotificationHeaderButton();
    if (window.updateSettingsPageUI) window.updateSettingsPageUI();
    if (window.initTypingIndicator) window.initTypingIndicator();
    if (window.initChannelReordering) window.initChannelReordering();
    if (window.initUnreadFilter) window.initUnreadFilter();
    if (window.initSidebarToggles) window.initSidebarToggles();
    if (window.initConfirmModals) window.initConfirmModals();
    if (window.initMessageHistoryCapture) window.initMessageHistoryCapture();
    if (window.initOfflineQueue) window.initOfflineQueue();
    if (window.initGlobalSearch) window.initGlobalSearch();
    if (window.initMobileSidebar) window.initMobileSidebar();
    if (window.initFaviconNotificationBadge) window.initFaviconNotificationBadge();


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

    // Focus message input on load (unless on mobile)
    const messageInput = document.getElementById('message');
    const isMobile = window.matchMedia('(max-width: 1024px)').matches || ('ontouchstart' in window) || (navigator.maxTouchPoints > 0);
    if (messageInput && !isMobile) {
        messageInput.focus();
    }
    checkJumpToMessage();
    initializeChannelScroll();


    document.body.addEventListener('htmx:afterSettle', (evt) => {
        const target = evt.detail.target;
        const isChannelSwitch = target && (target.tagName === 'BODY' || target.classList.contains('app-container'));

        // ── Skip / early-return cases ──────────────────────────────────────────
        if (target && target.id === 'global-search-results') {
            return;
        }
        if (target && (target.id === 'load-more-trigger' || target.classList.contains('load-more-container'))) {
            return;
        }

        // ── SSE message appended to #live-feed ────────────────────────────────
        // Only run lightweight per-item initialisation; scrolling is already
        // handled by the htmx:sseMessage listener in mercure.js (+50ms delay).
        if (target && target.id === 'live-feed') {
            if (window.updateEditButtonsVisibility) window.updateEditButtonsVisibility();
            if (window.highlightAllCodeBlocks) window.highlightAllCodeBlocks();
            if (window.initEmojiPickers) window.initEmojiPickers();
            return;
        }

        // ── Form morph after sending a message ───────────────────────────────
        // The textarea was cleared by idiomorph. Only re-init the form itself;
        // avoid expensive full-page operations that cause visible repaints.
        if (target && target.classList.contains('chat-message-form')) {
            initAutoResizeTextarea();
            if (window.initFileUpload) window.initFileUpload();
            if (window.initTypingIndicator) window.initTypingIndicator();
            if (window.initMessageHistoryCapture) window.initMessageHistoryCapture();


            const isMobileDevice = window.matchMedia('(max-width: 1024px)').matches || ('ontouchstart' in window) || (navigator.maxTouchPoints > 0);
            if (!isMobileDevice) {
                const threadPanel = document.getElementById('thread-panel');
                const threadTextarea = document.getElementById('thread-message');
                if (threadPanel && threadPanel.style.display !== 'none' && threadTextarea) {
                    threadTextarea.focus();
                } else {
                    const messageInputAfterSettle = document.getElementById('message');
                    if (messageInputAfterSettle) messageInputAfterSettle.focus();
                }
            }
            return;
        }

        // ── Single feed-item swap (edit/view/reaction) ───────────────────────
        if (target && target.classList.contains('feed-item')) {
            if (window.updateEditButtonsVisibility) window.updateEditButtonsVisibility();
            if (window.highlightAllCodeBlocks) window.highlightAllCodeBlocks();
            if (window.initEmojiPickers) window.initEmojiPickers();
            return;
        }

        // ── Text preview swap ─────────────────────────────────────────────────
        if (target && (target.classList.contains('text-preview-container') || target.querySelector('.text-preview-code'))) {
            const activeTarget = target.id ? (document.getElementById(target.id) || target) : target;
            if (window.highlightAllCodeBlocks) window.highlightAllCodeBlocks(activeTarget);
            setTimeout(() => {
                activeTarget.scrollIntoView({behavior: 'smooth', block: 'nearest'});
            }, 80);
            return;
        }

        // ── Link preview swap ──────────────────────────────────────────────────
        if (target && (target.classList.contains('link-preview-card') || target.querySelector('.link-preview-card'))) {
            const previewCard = target.classList.contains('link-preview-card') ? target : target.querySelector('.link-preview-card');
            adjustScrollForLinkPreview(previewCard);
            return;
        }

        if (window.updateEditButtonsVisibility) window.updateEditButtonsVisibility();
        if (window.highlightAllCodeBlocks) window.highlightAllCodeBlocks();
        if (window.initEmojiPickers) window.initEmojiPickers();
        if (window.initEmojiAutocomplete) window.initEmojiAutocomplete();
        initAutoResizeTextarea();
        if (window.initFileUpload) window.initFileUpload();
        if (window.setupNotificationHeaderButton) window.setupNotificationHeaderButton();
        if (window.updateSettingsPageUI) window.updateSettingsPageUI();
        if (window.initTypingIndicator) window.initTypingIndicator();
        if (window.initChannelReordering) window.initChannelReordering();
        if (window.initUnreadFilter) window.initUnreadFilter();
        if (window.initSidebarToggles) window.initSidebarToggles();
        if (window.initMessageHistoryCapture) window.initMessageHistoryCapture();
        if (window.renderChannelOfflineMessages) window.renderChannelOfflineMessages();
        if (window.initFaviconNotificationBadge) window.initFaviconNotificationBadge();


        // Refocus appropriate input after channel switches
        const isMobileDevice = window.matchMedia('(max-width: 1024px)').matches || ('ontouchstart' in window) || (navigator.maxTouchPoints > 0);
        if (isChannelSwitch && !isMobileDevice) {
            const threadPanel = document.getElementById('thread-panel');
            const threadTextarea = document.getElementById('thread-message');
            if (threadPanel && threadPanel.style.display !== 'none' && threadTextarea) {
                threadTextarea.focus();
            } else {
                const messageInputAfterSettle = document.getElementById('message');
                if (messageInputAfterSettle) messageInputAfterSettle.focus();
            }
        }
        if (isChannelSwitch) {
            initializeChannelScroll();
        }
        checkJumpToMessage();
    });

    // Diagnosing focus steals on main message input
    const messageEl = document.getElementById('message');
    if (messageEl) {
        messageEl.addEventListener('focus', () => {
            console.trace('[Diagnostic] Message input #message gained focus!');
        });
    }
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

// Prevent view transitions for non-boosted requests (like typing indicators, SSE messages, reactions, etc.)
// to avoid page-wide flickering/blinking. Only allow them for boosted page/channel transitions.
document.body.addEventListener('htmx:beforeTransition', (event) => {
    if (!event.detail.boosted) {
        event.preventDefault();
    }
});

// Run syntax highlighting on code blocks swapped via OOB
document.body.addEventListener('htmx:oobAfterSwap', (evt) => {
    if (window.highlightAllCodeBlocks && evt.detail.target) {
        window.highlightAllCodeBlocks(evt.detail.target);
    }
});



