// Message history navigation (shell-like ↑/↓)
const messageHistory = [];
let historyIndex = -1; // -1 = not navigating
let historyDraft = ''; // save current draft when entering history mode

// Inline edit mode state
let editModeMessageId = null;   // ID of the message being edited inline
let editModeItemIndex = -1;     // index into ownFeedItems[] currently being edited

export function insertMarkdown(formattingType) {
    const textarea = document.getElementById('message');
    if (!textarea) return;

    textarea.focus();
    
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
    
    textarea.focus();
    
    // Set selection/cursor at the end of the text
    textarea.selectionStart = textarea.selectionEnd = textarea.value.length;

    // Trigger any auto-resize listeners
    textarea.dispatchEvent(new Event('input', { bubbles: true }));
}

// Send message on Enter (without Shift or Alt) in the message textarea — with history & inline edit support
document.addEventListener('keydown', (event) => {
    if (!event.target || event.target.id !== 'message') return;
    const textarea = event.target;

    if (event.key === 'Enter') {
        if (!event.shiftKey && !event.altKey) {
            event.preventDefault();

            // In inline edit mode: submit the edit, not a new message
            if (editModeMessageId !== null) {
                event.preventDefault();
                submitInlineEdit(textarea);
                return;
            }

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

        // ── In edit mode: navigate to the previous own message ──
        if (editModeMessageId !== null) {
            event.preventDefault();
            const ownItems = getOwnFeedItems();
            const nextIndex = editModeItemIndex - 1;
            if (nextIndex >= 0) {
                editMessageInline(ownItems[nextIndex], nextIndex);
            }
            return;
        }

        // ── Textarea is empty → enter edit mode on last own message ──
        if (textarea.value.trim() === '') {
            event.preventDefault();
            const ownItems = getOwnFeedItems();
            if (ownItems.length > 0) {
                editMessageInline(ownItems[ownItems.length - 1], ownItems.length - 1);
            }
            return;
        }

        // ── Textarea has content → history navigation ──
        if (messageHistory.length === 0) return;
        if (historyIndex === -1) {
            historyDraft = textarea.value;
        }
        event.preventDefault();
        const newIndex = historyIndex === -1
            ? messageHistory.length - 1
            : Math.max(0, historyIndex - 1);
        historyIndex = newIndex;
        textarea.value = messageHistory[historyIndex];
        textarea.dispatchEvent(new Event('input', { bubbles: true }));
        textarea.selectionStart = textarea.selectionEnd = 0;
        return;
    }

    if (event.key === 'ArrowDown') {
        // In edit mode: navigate to the next own message or exit
        if (editModeMessageId !== null) {
            event.preventDefault();
            const ownItems = getOwnFeedItems();
            const nextIndex = editModeItemIndex + 1;
            if (nextIndex < ownItems.length) {
                editMessageInline(ownItems[nextIndex], nextIndex);
            } else {
                cancelInlineEdit();
            }
            return;
        }

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
        if (editModeMessageId !== null) {
            cancelInlineEdit();
            return;
        }
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

export function editMessageInline(feedItem, itemIndex) {
    const messageId = feedItem.getAttribute('data-message-id');
    if (!messageId) return;

    // Extract the text content of the message (strip HTML)
    const bodyEl = feedItem.querySelector('.feed-item-body p');
    const rawText = bodyEl ? bodyEl.innerText : '';

    const textarea = document.getElementById('message');
    if (!textarea) return;

    editModeMessageId = messageId;
    editModeItemIndex = itemIndex;
    historyIndex = -1; // leave history navigation

    textarea.value = rawText;
    textarea.dispatchEvent(new Event('input', { bubbles: true }));
    textarea.focus();
    textarea.selectionStart = textarea.selectionEnd = rawText.length;

    // Highlight the feed item being edited
    document.querySelectorAll('.feed-item.editing-inline').forEach(el =>
        el.classList.remove('editing-inline')
    );
    feedItem.classList.add('editing-inline');

    // Show edit mode banner
    showEditModeBanner();
}

export function cancelInlineEdit() {
    editModeMessageId = null;
    editModeItemIndex = -1;

    const textarea = document.getElementById('message');
    if (textarea) {
        textarea.value = '';
        textarea.dispatchEvent(new Event('input', { bubbles: true }));
        textarea.focus();
    }

    document.querySelectorAll('.feed-item.editing-inline').forEach(el =>
        el.classList.remove('editing-inline')
    );

    hideEditModeBanner();
}

export function submitInlineEdit(textarea) {
    const messageId = editModeMessageId;
    const newContent = textarea.value;

    cancelInlineEdit();

    fetch(`/messages/${messageId}/edit`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: new URLSearchParams({ content: newContent }),
    })
    .then(res => {
        if (!res.ok) throw new Error(`Edit failed: ${res.status}`);
        return res.text();
    })
    .then(html => {
        // Replace the feed item with the updated HTML
        const feedItem = document.querySelector(`[data-message-id="${messageId}"]`);
        if (feedItem) {
            const tmp = document.createElement('div');
            tmp.innerHTML = html;
            const newItem = tmp.firstElementChild;
            if (newItem) {
                feedItem.replaceWith(newItem);
                if (window.htmx) window.htmx.process(newItem);
                updateEditButtonsVisibility();
            }
        }
    })
    .catch(err => console.error('Inline edit error:', err));
}

export function showEditModeBanner() {
    let banner = document.getElementById('inline-edit-banner');
    if (!banner) {
        banner = document.createElement('div');
        banner.id = 'inline-edit-banner';
        banner.className = 'inline-edit-banner';
        banner.innerHTML = `
            <span class="inline-edit-banner-icon">✏️</span>
            <span class="inline-edit-banner-text">Modification du message — <kbd>Entrée</kbd> pour valider, <kbd>Échap</kbd> pour annuler</span>
            <button type="button" class="inline-edit-banner-cancel" onclick="cancelInlineEdit()" aria-label="Annuler la modification">✕</button>
        `;
        // Insert before the form
        const form = document.querySelector('.chat-message-form');
        if (form && form.parentNode) {
            form.parentNode.insertBefore(banner, form);
        }
    }
    banner.style.display = 'flex';
}

export function hideEditModeBanner() {
    const banner = document.getElementById('inline-edit-banner');
    if (banner) banner.style.display = 'none';
}

export function updateReactionBadges(currentUsername) {
    document.querySelectorAll('.reaction-badge').forEach(badge => {
        const reactorsStr = badge.getAttribute('data-reactors') || '';
        const reactors = reactorsStr.split(',').filter(r => r !== '');
        if (reactors.includes(currentUsername)) {
            badge.classList.add('active');
        } else {
            badge.classList.remove('active');
        }
    });
}

export function updateEditButtonsVisibility() {
    const statusBadge = document.getElementById('mercure-status');
    if (!statusBadge) return;
    const currentUsername = statusBadge.getAttribute('data-current-username');
    if (!currentUsername) return;

    document.querySelectorAll('.feed-item').forEach(item => {
        const authorUsername = item.getAttribute('data-author-username');
        const editBtn = item.querySelector('.btn-edit-subtle');
        if (editBtn) {
            if (authorUsername === currentUsername) {
                editBtn.style.display = 'inline-flex';
            } else {
                editBtn.style.display = 'none';
            }
        }
    });

    // Also update DM links for usernames in the feed
    updateUserLinks(currentUsername);

    // Dynamic reaction badge active class status
    updateReactionBadges(currentUsername);
}

export function updateUserLinks(currentUsername) {
    document.querySelectorAll('.feed-item').forEach(item => {
        const authorUsername = item.getAttribute('data-author-username');
        const userLink = item.querySelector('.feed-item-user-link');
        if (userLink && authorUsername) {
            if (authorUsername === currentUsername) {
                // It's the current user, disable link behavior/styling
                userLink.removeAttribute('href');
                userLink.style.pointerEvents = 'none';
                userLink.style.cursor = 'default';
                userLink.removeAttribute('title');
                userLink.classList.add('self-link');
            } else {
                // Ensure it is active
                userLink.setAttribute('href', `/dm/${authorUsername}`);
                userLink.style.pointerEvents = 'auto';
                userLink.style.cursor = 'pointer';
                const nameSpan = item.querySelector('.feed-item-user');
                const displayName = nameSpan ? nameSpan.textContent : authorUsername;
                userLink.setAttribute('title', `Discuter en privé avec ${displayName}`);
                userLink.classList.remove('self-link');
            }
        }
    });
}

export function switchInputTab(textareaId, mode) {
    const textarea = document.getElementById(textareaId);
    const previewContainer = document.getElementById(textareaId + '-preview');
    const editBtn = document.getElementById(textareaId + '-btn-edit-tab');
    const previewBtn = document.getElementById(textareaId + '-btn-preview-tab');
    const actionsWrapper = textarea?.parentElement?.querySelector('.input-actions-wrapper');

    if (!textarea || !previewContainer || !editBtn || !previewBtn) return;

    if (mode === 'preview') {
        // Toggle active class on buttons
        editBtn.classList.remove('active');
        previewBtn.classList.add('active');

        // Hide textarea and actions (emojis/files upload), show preview container
        textarea.style.display = 'none';
        if (actionsWrapper) actionsWrapper.style.display = 'none';
        previewContainer.style.display = 'block';

        // Load preview content from API
        previewContainer.innerHTML = '<span class="preview-loading">Chargement de l\'aperçu...</span>';

        const content = textarea.value;
        if (!content.trim()) {
            previewContainer.innerHTML = '<span class="preview-empty">Rien à prévisualiser</span>';
            return;
        }

        fetch('/api/message/preview', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({ content: content })
        })
        .then(res => res.json())
        .then(data => {
            previewContainer.innerHTML = data.html || '';
            if (window.highlightAllCodeBlocks) {
                window.highlightAllCodeBlocks(previewContainer);
            }
        })
        .catch(err => {
            console.error('Failed to load message preview:', err);
            previewContainer.innerHTML = '<span class="preview-error">Erreur lors du chargement de l\'aperçu.</span>';
        });
    } else {
        // Edit mode
        editBtn.classList.add('active');
        previewBtn.classList.remove('active');

        textarea.style.display = 'block';
        if (actionsWrapper) actionsWrapper.style.display = 'flex';
        previewContainer.style.display = 'none';
        textarea.focus();
    }
}

// Global window binds
window.insertMarkdown = insertMarkdown;
window.replyToMessage = replyToMessage;
window.cancelInlineEdit = cancelInlineEdit;
window.editMessageInline = editMessageInline;
window.submitInlineEdit = submitInlineEdit;
window.showEditModeBanner = showEditModeBanner;
window.hideEditModeBanner = hideEditModeBanner;
window.updateEditButtonsVisibility = updateEditButtonsVisibility;
window.updateUserLinks = updateUserLinks;
window.updateReactionBadges = updateReactionBadges;
window.getOwnFeedItems = getOwnFeedItems;
window.switchInputTab = switchInputTab;

