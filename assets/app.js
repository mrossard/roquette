import './styles/app.css';
import './styles/admin.css';
import htmx from 'htmx.org';
import 'htmx-ext-sse';

// Morph extension via Idiomorph
import { initMorphExtension } from './modules/morph-extension.js';

// Feature modules
import './modules/ui.js';
import './modules/mercure.js';
import './modules/notifications.js';
import './modules/editor.js';
import './modules/autocomplete.js';
import './modules/offline.js';
import './modules/search-builder.js';
import './modules/kanban.js';
import './modules/poll-options.js';

import { initializeChannelScroll, adjustScrollForLinkPreview } from './modules/scroll.js';
import { getFreshCsrfToken } from './modules/csrf.js';
import { initDraftPersistence, restoreDraftForActiveChannel } from './modules/draft.js';
import { initDialogHelpers } from './modules/dialog.js';
import { initReactionPicker } from './modules/reaction-picker.js';
import { initFileUploadUi } from './modules/file-upload-ui.js';

// Expose htmx globally
window.htmx = htmx;

// Register Idiomorph extension for HTMX
initMorphExtension(htmx);

// Hot reload in dev environment
if (document.querySelector('meta[name="frankenphp-hot-reload:url"]')) {
    import('frankenphp-hot-reload');
}

// Service Worker for offline / PWA
if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('/sw.js');
}

// Initialize dedicated feature modules
initDraftPersistence();
initDialogHelpers();
initReactionPicker();
initFileUploadUi();

function initAutoResizeTextarea() {
    // Managed natively by CSS field-sizing: content
}

function checkJumpToMessage() {
    const urlParams = new URLSearchParams(window.location.search);
    const jumpTo = urlParams.get('jumpTo');
    if (jumpTo && window.scrollToMessage) {
        setTimeout(() => {
            window.scrollToMessage(parseInt(jumpTo, 10));
            const cleanUrl = window.location.pathname;
            window.history.replaceState({}, document.title, cleanUrl);
        }, 300);
    }
}

// ── HTMX Global Event Listeners ──────────────────────────────────────────────

// Allow swapping for validation / rate limit error status codes
document.body.addEventListener('htmx:beforeSwap', (evt) => {
    if (evt.detail.xhr.status === 400 || evt.detail.xhr.status === 422 || evt.detail.xhr.status === 429) {
        evt.detail.shouldSwap = true;
        evt.detail.isError = false;
    }
});

// Pass active channel slug in headers and inject CSRF token on non-GET requests
document.addEventListener('htmx:configRequest', (evt) => {
    const statusBadge = document.getElementById('mercure-status');
    if (statusBadge) {
        const activeChannelSlug = statusBadge.getAttribute('data-active-channel-slug');
        if (activeChannelSlug) {
            evt.detail.headers['X-Previous-Channel'] = activeChannelSlug;
        }
    }

    if (evt.detail.elt && evt.detail.elt.id === 'channel-search-input') {
        const query = evt.detail.elt.value.trim();
        if (statusBadge) {
            if (query !== '') {
                statusBadge.setAttribute('data-search-active', 'true');
            } else {
                statusBadge.removeAttribute('data-search-active');
            }
        }
    }

    if (evt.detail.method !== 'GET') {
        const tokenMeta = document.querySelector('meta[name="csrf-token"]');
        if (tokenMeta) {
            evt.detail.headers['X-CSRF-Token'] = tokenMeta.content;
        }
    }
});

// Prevent view transitions for non-boosted requests to avoid page flickering
document.body.addEventListener('htmx:beforeTransition', (event) => {
    if (!event.detail.boosted) {
        event.preventDefault();
    }
});

// Run syntax highlighting and button visibility on code blocks swapped via OOB
document.body.addEventListener('htmx:oobAfterSwap', (evt) => {
    if (window.updateEditButtonsVisibility) {
        window.updateEditButtonsVisibility();
    }
    if (window.highlightAllCodeBlocks && evt.detail.target) {
        window.highlightAllCodeBlocks(evt.detail.target);
    }
});

// Auto-refresh CSRF token on 403 response, then retry the request once
document.body.addEventListener('htmx:responseError', async (evt) => {
    const xhr = evt.detail.xhr;
    const requestConfig = evt.detail.requestConfig;

    if (xhr.status === 403 && requestConfig && requestConfig.verb !== 'GET' && !requestConfig._csrfRetried) {
        requestConfig._csrfRetried = true;

        const freshToken = await getFreshCsrfToken();
        if (freshToken) {
            requestConfig.headers = requestConfig.headers || {};
            requestConfig.headers['X-CSRF-Token'] = freshToken;
            window.htmx.ajax(requestConfig.verb, requestConfig.path, requestConfig);
        }
    }
});

// ── DOM Initialisation and Settle Handlers ────────────────────────────────────

document.addEventListener('DOMContentLoaded', () => {
    // Initial global setup
    if (window.connectMercure) window.connectMercure();
    if (window.updateEditButtonsVisibility) window.updateEditButtonsVisibility();
    if (window.highlightAllCodeBlocks) window.highlightAllCodeBlocks();
    if (window.initCodeBlockCopyButtons) window.initCodeBlockCopyButtons();
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
    if (window.initHideCompletedTasks) window.initHideCompletedTasks();
    if (window.initSubChannelsSidebar) window.initSubChannelsSidebar();
    if (window.initFilesSidebar) window.initFilesSidebar();
    if (window.initConfirmModals) window.initConfirmModals();
    if (window.initMessageHistoryCapture) window.initMessageHistoryCapture();
    if (window.initOfflineQueue) window.initOfflineQueue();
    if (window.initGlobalSearch) window.initGlobalSearch();
    if (window.initMobileSidebar) window.initMobileSidebar();
    if (window.initFaviconNotificationBadge) window.initFaviconNotificationBadge();
    if (window.initKanbanBoard) window.initKanbanBoard();

    // Focus message input on load (unless on mobile)
    const messageInput = document.getElementById('message');
    const isMobile = window.matchMedia('(max-width: 1024px)').matches && window.matchMedia('(pointer: coarse)').matches;
    if (messageInput && !isMobile) {
        messageInput.focus();
    }
    checkJumpToMessage();
    initializeChannelScroll();

    document.body.addEventListener('htmx:afterSettle', (evt) => {
        const target = evt.detail.target;
        const isChannelSwitch = target && (target.tagName === 'BODY' || target.classList.contains('app-container'));

        // Skip / early-return cases
        if (target && target.id === 'global-search-results') {
            return;
        }
        if (target && (target.id === 'load-more-trigger' || target.classList.contains('load-more-container'))) {
            return;
        }
        if (target && target.id === 'typing-indicator') {
            return;
        }

        // SSE message appended to #live-feed
        if (target && target.id === 'live-feed') {
            if (window.updateEditButtonsVisibility) window.updateEditButtonsVisibility();
            if (window.highlightAllCodeBlocks) window.highlightAllCodeBlocks();
            if (window.initCodeBlockCopyButtons) window.initCodeBlockCopyButtons();
            if (window.initEmojiPickers) window.initEmojiPickers();
            return;
        }

        // Form morph after sending a message
        if (target && target.classList.contains('chat-message-form')) {
            initAutoResizeTextarea();
            if (window.initFileUpload) window.initFileUpload();
            if (window.initTypingIndicator) window.initTypingIndicator();
            if (window.initMessageHistoryCapture) window.initMessageHistoryCapture();
            if (window.initEmojiAutocomplete) window.initEmojiAutocomplete();

            const messageInputAfterSettle = document.getElementById('message');
            if (messageInputAfterSettle) messageInputAfterSettle.focus();
            return;
        }

        // Single feed-item swap (edit/view/reaction)
        if (target && target.classList.contains('feed-item')) {
            if (window.updateEditButtonsVisibility) window.updateEditButtonsVisibility();
            if (window.highlightAllCodeBlocks) window.highlightAllCodeBlocks();
            if (window.initCodeBlockCopyButtons) window.initCodeBlockCopyButtons();
            if (window.initEmojiPickers) window.initEmojiPickers();
            return;
        }

        // Text preview swap
        if (!isChannelSwitch && target && (target.classList.contains('text-preview-container') || target.querySelector('.text-preview-code'))) {
            const activeTarget = target.id ? (document.getElementById(target.id) || target) : target;
            if (window.highlightAllCodeBlocks) window.highlightAllCodeBlocks(activeTarget);
            if (window.initCodeBlockCopyButtons) window.initCodeBlockCopyButtons(activeTarget);
            return;
        }

        // Link preview swap
        if (!isChannelSwitch && target && (target.classList.contains('link-preview-card') || target.querySelector('.link-preview-card'))) {
            const previewCard = target.classList.contains('link-preview-card') ? target : target.querySelector('.link-preview-card');
            adjustScrollForLinkPreview(previewCard);
            return;
        }

        // General reinitialization
        if (window.updateEditButtonsVisibility) window.updateEditButtonsVisibility();
        if (window.highlightAllCodeBlocks) window.highlightAllCodeBlocks();
        if (window.initCodeBlockCopyButtons) window.initCodeBlockCopyButtons();
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
        if (window.initHideCompletedTasks) window.initHideCompletedTasks();
        if (window.initSubChannelsSidebar) window.initSubChannelsSidebar();
        if (window.initFilesSidebar) window.initFilesSidebar();
        if (window.initMessageHistoryCapture) window.initMessageHistoryCapture();
        if (window.renderChannelOfflineMessages) window.renderChannelOfflineMessages();
        if (window.initFaviconNotificationBadge) window.initFaviconNotificationBadge();
        if (window.initKanbanBoard) window.initKanbanBoard();

        // Refocus input and restore draft after channel switches
        if (isChannelSwitch) {
            const messageInputAfterSettle = document.getElementById('message');
            if (messageInputAfterSettle && !isMobile) {
                messageInputAfterSettle.focus();
            }
            if (messageInputAfterSettle) {
                requestAnimationFrame(() => {
                    restoreDraftForActiveChannel();
                });
            }
            initializeChannelScroll();
        }
        checkJumpToMessage();
    });
});
