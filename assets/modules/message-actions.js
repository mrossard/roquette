/**
 * Fait défiler la vue jusqu'au message désigné et l'anime brièvement.
 *
 * @param {number|string} messageId
 */
export function scrollToMessage(messageId) {
    const el = document.querySelector(`[data-message-id="${messageId}"]`);
    if (el) {
        el.scrollIntoView({ behavior: 'smooth', block: 'center' });
        el.classList.add('highlight-pinned-message');
        setTimeout(() => {
            el.classList.remove('highlight-pinned-message');
        }, 2000);
    } else {
        const urlParams = new URLSearchParams(window.location.search);
        if (!urlParams.get('jumpTo')) {
            window.location.href = window.location.pathname + '?jumpTo=' + messageId;
        }
    }
}

// Expose globally
window.scrollToMessage = scrollToMessage;

// Intercept clicks on jumpTo links within the same channel to scroll without reloading
document.addEventListener('click', (e) => {
    const link = e.target.closest('a[href*="jumpTo="]');
    if (!link) return;

    const href = link.getAttribute('href');
    if (!href) return;

    try {
        const url = new URL(href, window.location.origin);
        const jumpTo = url.searchParams.get('jumpTo');
        if (!jumpTo) return;

        // If on the same channel/page:
        if (url.pathname === window.location.pathname) {
            e.preventDefault();
            e.stopPropagation();

            const modal = link.closest('dialog[open]');
            if (modal && typeof modal.close === 'function') {
                modal.close();
            }

            scrollToMessage(jumpTo);
            const cleanUrl = window.location.pathname;
            window.history.replaceState({}, document.title, cleanUrl);
        }
    } catch (_) {}
});

function toggleMessageActionsFromEvent(e) {
    const button = e.target.closest('.btn-actions-toggle');
    if (!button) return;

    e.stopPropagation();
    const actionsList = button.nextElementSibling;
    if (!actionsList) return;

    document.querySelectorAll('.feed-item-actions-list.show').forEach(list => {
        if (list !== actionsList) {
            list.classList.remove('show');
        }
    });
    actionsList.classList.toggle('show');
}

export function toggleReminderDropdown(button, event) {
    if (event) {
        event.stopPropagation();
        event.preventDefault();
    }

    const wrapper = button.closest('.reminder-dropdown-wrapper');
    const dropdown = wrapper?.querySelector('.reminder-picker-dropdown');
    if (!dropdown) return;

    const isShown = dropdown.classList.contains('show');
    document.querySelectorAll('.reminder-picker-dropdown.show').forEach(d => d.classList.remove('show', 'open-up'));

    if (!isShown) {
        dropdown.classList.add('show');
        // Smart positioning: check if there is enough space below
        const rect = button.getBoundingClientRect();
        const spaceBelow = window.innerHeight - rect.bottom;
        if (spaceBelow < 260) {
            dropdown.classList.add('open-up');
        } else {
            dropdown.classList.remove('open-up');
        }
    }
}

// Expose globally for inline onclick
window.toggleReminderDropdown = toggleReminderDropdown;

// Menu contextuel des messages
document.addEventListener('click', (e) => {
    toggleMessageActionsFromEvent(e);

    const reminderToggle = e.target.closest('.btn-reminder-toggle');
    if (reminderToggle) {
        toggleReminderDropdown(reminderToggle, e);
        return;
    }

    // Close open reminder pickers if clicking outside
    if (!e.target.closest('.reminder-dropdown-wrapper')) {
        document.querySelectorAll('.reminder-picker-dropdown.show').forEach(d => d.classList.remove('show', 'open-up'));
    }
});

// Barre d'outils de formatage Markdown pour les zones de texte des messages
document.addEventListener('click', (e) => {
    const btn = e.target.closest('.btn-format[data-markdown]');
    if (!btn) return;

    const form = btn.closest('form');
    if (!form) return;
    const textarea = form.querySelector('textarea');
    if (!textarea) return;

    const formattingType = btn.getAttribute('data-markdown');

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

    textarea.focus();
    textarea.setRangeText(replacement, start, end, 'select');

    if (!selectedText) {
        const offsets = {
            bold: [2, 7],
            italic: [1, 6],
            strikethrough: [2, 7],
            quote: [2, 10],
            code: [1, 5],
            codeblock: [4, 8],
            link: [1, 5]
        };
        const offset = offsets[formattingType];
        if (offset) {
            textarea.setSelectionRange(start + offset[0], start + offset[1]);
        }
    } else {
        const newCursorPos = start + replacement.length;
        textarea.setSelectionRange(newCursorPos, newCursorPos);
    }

    textarea.dispatchEvent(new Event('input', { bubbles: true }));
});

// Bloque le swap SSE sur #live-feed lors de l'affichage d'un fil de discussion
document.addEventListener('htmx:sseBeforeMessage', function (e) {
    const feed = document.getElementById('live-feed');
    if (feed && feed.hasAttribute('data-block-sse') && e.target === feed) {
        e.preventDefault();
    }
});
