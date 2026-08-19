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

// Menu contextuel des messages
document.addEventListener('click', toggleMessageActionsFromEvent);

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
