import { getOrBuildSharedEmojiPickerDOM } from './emoji.js';

/**
 * Message reaction picker popup handling with intelligent viewport boundary detection.
 */

export function closeAllReactionPickers() {
    document.querySelectorAll('.reaction-picker.show').forEach((p) => {
        p.classList.remove('show');
    });
}

export function closeAllFeedItemActions() {
    document.querySelectorAll('.feed-item-actions-list.show').forEach((list) => {
        list.classList.remove('show');
    });
}

export function initReactionPicker() {
    document.addEventListener('click', async (e) => {
        const btn = e.target.closest('.btn-add-reaction');
        if (!btn) return;

        e.stopPropagation();
        const feedItem = btn.closest('.feed-item');
        if (!feedItem) return;
        const messageId = feedItem.dataset.messageId;
        if (!messageId) return;

        const picker = document.getElementById(`reaction-picker-${messageId}`);
        if (!picker) return;

        document.querySelectorAll('.reaction-picker.show').forEach((p) => {
            if (p !== picker) {
                p.classList.remove('show');
            }
        });

        const isShowing = !picker.classList.contains('show');
        picker.classList.toggle('show');

        if (isShowing) {
            let emojiPickerContainer = document.getElementById('shared-reaction-emoji-picker');
            if (!emojiPickerContainer) {
                const { element, focusSearch: focusSearchFn } = await getOrBuildSharedEmojiPickerDOM((emoji) => {
                    const msgId = emojiPickerContainer?.dataset.messageId;
                    if (emoji && msgId && window.htmx) {
                        const targetFeedItem = document.querySelector(`.feed-item[data-message-id="${msgId}"]`);
                        if (targetFeedItem) {
                            window.htmx.ajax('POST', `/messages/${msgId}/react/${encodeURIComponent(emoji)}`, {
                                target: targetFeedItem,
                                swap: 'outerHTML',
                            });
                        }
                    }
                    const activePicker = emojiPickerContainer?.closest('.reaction-picker');
                    if (activePicker) {
                        activePicker.classList.remove('show');
                    }
                });
                emojiPickerContainer = element;
                emojiPickerContainer.id = 'shared-reaction-emoji-picker';
                emojiPickerContainer.focusSearch = focusSearchFn;
            }

            emojiPickerContainer.dataset.messageId = messageId;
            picker.appendChild(emojiPickerContainer);

            picker.style.left = '0';
            picker.style.right = 'auto';
            picker.style.top = '100%';
            picker.style.bottom = 'auto';
            picker.style.marginTop = '4px';
            picker.style.marginBottom = '0';

            let rect = picker.getBoundingClientRect();
            const triggerRect = picker.parentElement?.getBoundingClientRect() ?? { left: 0, top: 0 };

            if (rect.right > window.innerWidth) {
                picker.style.left = 'auto';
                picker.style.right = '0';
                rect = picker.getBoundingClientRect();

                if (rect.left < 0) {
                    picker.style.left = `${-triggerRect.left + 12}px`;
                    picker.style.right = 'auto';
                    rect = picker.getBoundingClientRect();
                }
            } else if (rect.left < 0) {
                picker.style.left = `${-triggerRect.left + 12}px`;
                picker.style.right = 'auto';
                rect = picker.getBoundingClientRect();
            }

            let bottomThreshold = window.innerHeight;
            const container = picker.closest('#live-feed');
            if (container) {
                const chatInputArea = container.parentElement?.querySelector('.chat-input-area');
                if (chatInputArea) {
                    bottomThreshold = chatInputArea.getBoundingClientRect().top;
                }
            }

            if (rect.bottom > bottomThreshold) {
                picker.style.top = 'auto';
                picker.style.bottom = '100%';
                picker.style.marginTop = '0';
                picker.style.marginBottom = '8px';
                rect = picker.getBoundingClientRect();

                if (rect.top < 0) {
                    picker.style.top = `${-triggerRect.top + 12}px`;
                    picker.style.bottom = 'auto';
                    picker.style.marginTop = '0';
                    picker.style.marginBottom = '0';
                }
            }

            if (emojiPickerContainer.focusSearch) {
                emojiPickerContainer.focusSearch();
            }
        }
    });

    // Global click/escape handlers to close picker and action dropdowns
    document.addEventListener('click', (e) => {
        if (!e.target.closest('.reaction-picker') && !e.target.closest('.btn-add-reaction')) {
            closeAllReactionPickers();
        }
        if (!e.target.closest('.feed-item-actions')) {
            closeAllFeedItemActions();
        }
    });

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            closeAllReactionPickers();
            closeAllFeedItemActions();
        }
    });
}
