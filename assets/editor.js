// Message history navigation (shell-like ↑/↓)
const messageHistory = [];
let historyIndex = -1; // -1 = not navigating
let historyDraft = ''; // save current draft when entering history mode



const isMobile = () => window.matchMedia('(max-width: 1024px)').matches || ('ontouchstart' in window) || (navigator.maxTouchPoints > 0);

export function insertMarkdown(formattingType) {
    const textarea = document.getElementById('message');
    if (!textarea) return;

    if (!isMobile()) {
        textarea.focus();
    }

    const start = textarea.selectionStart;
    const end = textarea.selectionEnd;
    const text = textarea.value;
    const selectedText = text.substring(start, end);

    let replacement = '';

    switch (formattingType) {
        case 'bold':
            replacement = `**${selectedText || 'texte'}**`;
            break;
        case 'italic':
            replacement = `*${selectedText || 'texte'}*`;
            break;
        case 'strikethrough':
            replacement = `~~${selectedText || 'texte'}~~`;
            break;
        case 'quote':
            replacement = `> ${selectedText || 'citation'}`;
            break;
        case 'code':
            replacement = `\`${selectedText || 'code'}\``;
            break;
        case 'codeblock':
            replacement = `\`\`\`\n${selectedText || 'code'}\n\`\`\``;
            break;
        case 'link':
            replacement = `[${selectedText || 'lien'}](https://)`;
            break;
    }

    textarea.setRangeText(replacement, start, end, 'select');

    // adjust selection/cursor if dummy text was used
    if (!selectedText) {
        if (formattingType === 'bold') {
            textarea.setSelectionRange(start + 2, start + 7);
        } else if (formattingType === 'italic') {
            textarea.setSelectionRange(start + 1, start + 6);
        } else if (formattingType === 'strikethrough') {
            textarea.setSelectionRange(start + 2, start + 7);
        } else if (formattingType === 'quote') {
            textarea.setSelectionRange(start + 2, start + 10);
        } else if (formattingType === 'code') {
            textarea.setSelectionRange(start + 1, start + 5);
        } else if (formattingType === 'codeblock') {
            textarea.setSelectionRange(start + 4, start + 8);
        } else if (formattingType === 'link') {
            textarea.setSelectionRange(start + 1, start + 5);
        }
    } else {
        // focus at the end of the insertion
        const newCursorPos = start + replacement.length;
        textarea.setSelectionRange(newCursorPos, newCursorPos);
    }

    // Trigger any auto-resize listeners
    textarea.dispatchEvent(new Event('input', { bubbles: true }));
}

export function replyToMessage(author, content) {
    const textarea = document.getElementById('message');
    if (!textarea) return;

    // Split content by lines, prefix each with "> "
    const lines = content.split('\n');
    const quotedLines = lines.map(line => `> ${line}`).join('\n');

    // Add header to make it clear who said it
    const quoteHeader = `> **@${author}** a écrit :\n`;

    // Combine quote header and the quoted lines, then add empty lines for user's reply
    const quote = `${quoteHeader}${quotedLines}\n\n`;

    // Prepend or set the quote
    const currentValue = textarea.value;
    if (currentValue.trim()) {
        textarea.value = quote + currentValue;
    } else {
        textarea.value = quote;
    }

    if (!isMobile()) {
        textarea.focus();
    }

    // Set selection/cursor at the end of the text
    textarea.selectionStart = textarea.selectionEnd = textarea.value.length;

    // Trigger any auto-resize listeners
    textarea.dispatchEvent(new Event('input', { bubbles: true }));
}

// Send message on Enter (without Shift or Alt) in the message textarea — with history & inline edit support via HTMX
document.addEventListener('keydown', (event) => {
    if (!event.target || event.target.id !== 'message') return;
    const textarea = event.target;

    if (event.key === 'Enter') {
        if (!event.shiftKey && !event.altKey) {
            event.preventDefault();

            const form = textarea.closest('form');
            if (form) {
                form.requestSubmit();
            }
        } else if (event.altKey) {
            event.preventDefault();
            const start = textarea.selectionStart;
            const end = textarea.selectionEnd;
            const value = textarea.value;
            textarea.value = value.substring(0, start) + "\n" + value.substring(end);
            textarea.selectionStart = textarea.selectionEnd = start + 1;

            // Trigger input event to auto-resize textarea
            textarea.dispatchEvent(new Event('input', { bubbles: true }));
        }
        return;
    }

    if (event.key === 'ArrowUp') {
        // Only act when cursor is at the very start (position 0)
        if (textarea.selectionStart !== 0 || textarea.selectionEnd !== 0) return;

        // ── Textarea is empty → enter edit mode on last own message ──
        if (textarea.value.trim() === '') {
            event.preventDefault();
            const ownItems = getOwnFeedItems();
            if (ownItems.length > 0) {
                const lastItem = ownItems[ownItems.length - 1];
                const editBtn = lastItem.querySelector('.btn-edit-subtle');
                if (editBtn) {
                    editBtn.click();
                }
            }
            return;
        }

        // ── Textarea has content → history navigation ──
        if (messageHistory.length === 0) return;
        if (historyIndex === -1) {
            historyDraft = textarea.value;
        }
        event.preventDefault();
        historyIndex = historyIndex === -1
            ? messageHistory.length - 1
            : Math.max(0, historyIndex - 1);
        textarea.value = messageHistory[historyIndex];
        textarea.dispatchEvent(new Event('input', { bubbles: true }));
        textarea.selectionStart = textarea.selectionEnd = 0;
        return;
    }

    if (event.key === 'ArrowDown') {
        // Only act when cursor is at the very end of the textarea
        if (textarea.selectionStart !== textarea.value.length || textarea.selectionEnd !== textarea.value.length) return;

        if (historyIndex === -1) return; // not navigating history
        event.preventDefault();
        if (historyIndex < messageHistory.length - 1) {
            historyIndex++;
            textarea.value = messageHistory[historyIndex];
        } else {
            historyIndex = -1;
            textarea.value = historyDraft;
        }
        textarea.dispatchEvent(new Event('input', { bubbles: true }));
        textarea.selectionStart = textarea.selectionEnd = textarea.value.length;
        return;
    }

    if (event.key === 'Escape') {
        if (historyIndex !== -1) {
            historyIndex = -1;
            textarea.value = historyDraft;
            textarea.dispatchEvent(new Event('input', { bubbles: true }));
            textarea.selectionStart = textarea.selectionEnd = textarea.value.length;
        }
        return;
    }

    // Any other key resets history navigation index (user started typing)
    if (historyIndex !== -1 && event.key.length === 1) {
        historyIndex = -1;
        historyDraft = '';
    }
});

// Document listener to navigate between inline edit forms in the feed using arrow keys and Escape
document.addEventListener('keydown', (event) => {
    if (!event.target || !event.target.classList.contains('edit-message-textarea')) return;
    const textarea = event.target;

    if (event.key === 'Escape') {
        event.preventDefault();
        const cancelBtn = textarea.form ? textarea.form.querySelector('.btn-cancel') : null;
        if (cancelBtn) {
            cancelBtn.click();
        }
        const mainTextarea = document.getElementById('message');
        if (mainTextarea) {
            mainTextarea.focus();
        }
        return;
    }

    if (event.key === 'ArrowUp') {
        // Only act when cursor is at the very start (position 0)
        if (textarea.selectionStart !== 0 || textarea.selectionEnd !== 0) return;

        event.preventDefault();
        const feedItem = textarea.closest('.feed-item');
        if (!feedItem) return;

        const ownItems = getOwnFeedItems();
        const currentIndex = ownItems.indexOf(feedItem);
        if (currentIndex > 0) {
            // Cancel current edit by clicking its cancel button
            const cancelBtn = feedItem.querySelector('.btn-cancel');
            if (cancelBtn) cancelBtn.click();

            // Click edit on the previous message
            const prevItem = ownItems[currentIndex - 1];
            const editBtn = prevItem.querySelector('.btn-edit-subtle');
            if (editBtn) {
                editBtn.click();
            }
        }
        return;
    }

    if (event.key === 'ArrowDown') {
        // Only act when cursor is at the very end of the textarea
        if (textarea.selectionStart !== textarea.value.length || textarea.selectionEnd !== textarea.value.length) return;

        event.preventDefault();
        const feedItem = textarea.closest('.feed-item');
        if (!feedItem) return;

        const ownItems = getOwnFeedItems();
        const currentIndex = ownItems.indexOf(feedItem);

        // Cancel current edit by clicking its cancel button
        const cancelBtn = feedItem.querySelector('.btn-cancel');
        if (cancelBtn) cancelBtn.click();

        if (currentIndex < ownItems.length - 1) {
            // Click edit on the next message
            const nextItem = ownItems[currentIndex + 1];
            const editBtn = nextItem.querySelector('.btn-edit-subtle');
            if (editBtn) {
                editBtn.click();
            }
        } else {
            // Return focus to the main input
            const mainTextarea = document.getElementById('message');
            if (mainTextarea) {
                mainTextarea.focus();
            }
        }
        return;
    }
});

// Focus main input after successful inline edit save
document.body.addEventListener('htmx:afterRequest', (evt) => {
    if (evt.detail.elt && evt.detail.elt.classList.contains('edit-message-form') && evt.detail.successful) {
        const mainTextarea = document.getElementById('message');
        if (mainTextarea) {
            mainTextarea.focus();
        }
    }
});

export function getOwnFeedItems() {
    const statusBadge = document.getElementById('mercure-status');
    if (!statusBadge) return [];
    const currentUsername = statusBadge.getAttribute('data-current-username');
    if (!currentUsername) return [];
    return Array.from(document.querySelectorAll(
        `.feed-item[data-author-username="${currentUsername}"][data-message-id]`
    )).filter(el => el.getAttribute('data-message-id') !== '');
}

export function saveMessageToHistory(text) {
    const trimmed = text.trim();
    if (!trimmed) return;
    // Avoid duplicates at the end
    if (messageHistory.length > 0 && messageHistory[messageHistory.length - 1] === trimmed) return;
    messageHistory.push(trimmed);
    if (messageHistory.length > 50) messageHistory.shift();
    historyIndex = -1;
    historyDraft = '';
}



export function updateReactionBadges(currentUsername) {
    // Handled via pure CSS selectors
}

export function updateEditButtonsVisibility() {
    // Handled via pure CSS selectors
}

export function updateUserLinks(currentUsername) {
    // Handled via pure CSS selectors
}

// Global window binds
window.insertMarkdown = insertMarkdown;
window.replyToMessage = replyToMessage;

window.updateEditButtonsVisibility = updateEditButtonsVisibility;
window.updateUserLinks = updateUserLinks;
window.updateReactionBadges = updateReactionBadges;
window.getOwnFeedItems = getOwnFeedItems;

