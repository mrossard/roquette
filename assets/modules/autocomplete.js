// emoji-data.js will be loaded dynamically when needed

let activeAutocomplete = null;
let lastAutocompleteQuery = '';
let lastAutocompleteType = '';
const autocompleteInitialized = new WeakSet();

const SLASH_COMMANDS = [
    {name: 'help', icon: '🤖', description: window.trans('Poser une question à l\'Assistant Roquette'), usage: '/help <question>'},

    {name: 'shrug', icon: '🤷', description: window.trans('Envoyer le shrug ¯\\_(ツ)_/¯'), usage: '/shrug [texte]'},
    {name: 'me', icon: '💬', description: window.trans('Action'), usage: '/me <message>'},
    {name: 'color', icon: '🎨', description: window.trans('Changer la couleur de votre pseudo'), usage: '/color [0-360]'},
];

function loadAutocompleteItems(type, query) {
    lastAutocompleteQuery = query;
    lastAutocompleteType = type;
    const url = `/api/autocomplete/${type}?q=${encodeURIComponent(query)}`;

    return htmx.ajax('GET', url, {
        target: activeAutocomplete.element,
        swap: 'innerHTML',
    }).then(() => {
        if (lastAutocompleteQuery !== query || lastAutocompleteType !== type) return;
        updateAutocompleteActiveIndex();
    });
}

function findMatchingEmojisForQuery(query, data) {
    const matched = [];
    const seen = new Set();

    for (const cat of data.EMOJI_CATEGORIES) {
        for (const emoji of cat.emojis) {
            if (seen.has(emoji)) continue;

            const keywords = data.EMOJI_KEYWORDS[emoji] || [];
            const priorityMatch = keywords.some(kw => kw.startsWith(query));
            const containsMatch = keywords.some(kw => kw.includes(query));

            if (priorityMatch || containsMatch) {
                const primaryShortcode = data.EMOJI_PRIMARY_SHORTCODES[emoji] || keywords[0] || '';
                matched.push({
                    emoji,
                    keyword: primaryShortcode,
                    priority: priorityMatch ? 2 : 1
                });
                seen.add(emoji);
            }
        }
    }

    return matched
        .sort((a, b) => b.priority - a.priority)
        .slice(0, 6);
}

function findMatchingCommands(query) {
    return SLASH_COMMANDS.filter(cmd => cmd.name.startsWith(query.toLowerCase()));
}

export function initEmojiAutocomplete() {
    const targets = document.querySelectorAll('textarea, input#global-search-input');

    targets.forEach(target => {
        if (autocompleteInitialized.has(target)) return;
        autocompleteInitialized.add(target);

        target.addEventListener('input', () => {
            handleTextareaInputForAutocomplete(target);
        });

        target.addEventListener('keydown', (e) => {
            handleTextareaKeydownForAutocomplete(target, e);
        });

        target.addEventListener('blur', () => {
            setTimeout(() => {
                if (activeAutocomplete && activeAutocomplete.textarea === target) {
                    closeAutocomplete();
                }
            }, 150);
        });
    });
}

async function handleTextareaInputForAutocomplete(textarea) {
    if (textarea.id === 'admin-autocomplete-input') {
        return;
    }

    const cursor = textarea.selectionStart;
    const text = textarea.value;

    const textBeforeCursor = text.substring(0, cursor);
    const matchCustomEmoji = textBeforeCursor.match(/\[:([a-zA-Z0-9_\-\+: ]{0,})$/);
    const matchEmoji = textBeforeCursor.match(/:([a-zA-Z0-9_à-ÿÀ-Ÿ]{1,})$/);
    const matchMention = textBeforeCursor.match(/(?:^|\s|:)@([a-zA-Z0-9_à-ÿÀ-Ÿ]{0,})$/);
    const matchChannel = textBeforeCursor.match(/(?:^|\s|:)#([a-zA-Z0-9_à-ÿÀ-Ÿ-]{0,})$/);
    const matchCommand = textBeforeCursor.match(/^\/([a-zA-Z0-9_]*)$/);

    if (textarea.id === 'global-search-input') {
        if (matchMention) {
            const query = matchMention[1].toLowerCase();
            const queryStartIndex = textBeforeCursor.lastIndexOf('@');
            const queryEndIndex = cursor;

            showAutocompleteDropdown(textarea, 'mention', query, queryStartIndex, queryEndIndex, []);
            loadAutocompleteItems('users', query);
        } else if (matchChannel) {
            const query = matchChannel[1].toLowerCase();
            const queryStartIndex = textBeforeCursor.lastIndexOf('#');
            const queryEndIndex = cursor;

            showAutocompleteDropdown(textarea, 'channel', query, queryStartIndex, queryEndIndex, []);
            loadAutocompleteItems('channels', query);
        } else {
            closeAutocomplete();
        }
        return;
    }

    if (matchCommand) {
        const query = matchCommand[1].toLowerCase();
        const matches = findMatchingCommands(query);

        if (matches.length === 0) {
            closeAutocomplete();
            return;
        }

        showAutocompleteDropdown(textarea, 'command', query, 0, cursor, matches);
        renderAutocompleteItems();
    } else if (matchCustomEmoji) {
        const query = matchCustomEmoji[1].toLowerCase();
        const queryStartIndex = textBeforeCursor.lastIndexOf('[:');
        const queryEndIndex = cursor;

        showAutocompleteDropdown(textarea, 'custom-emoji', query, queryStartIndex, queryEndIndex, []);
        loadAutocompleteItems('custom-emojis', query);
    } else if (matchEmoji) {
        const query = matchEmoji[1].toLowerCase();
        const queryStartIndex = cursor - matchEmoji[0].length;
        const queryEndIndex = cursor;

        const emojiData = await import('./emoji-data.js');
        const matches = findMatchingEmojisForQuery(query, emojiData);

        if (matches.length === 0) {
            closeAutocomplete();
            return;
        }

        showAutocompleteDropdown(textarea, 'emoji', query, queryStartIndex, queryEndIndex, matches);
        renderAutocompleteItems();
    } else if (matchMention) {
        const query = matchMention[1].toLowerCase();
        const queryStartIndex = textBeforeCursor.lastIndexOf('@');
        const queryEndIndex = cursor;

        showAutocompleteDropdown(textarea, 'mention', query, queryStartIndex, queryEndIndex, []);
        loadAutocompleteItems('users', query);
    } else if (matchChannel) {
        const query = matchChannel[1].toLowerCase();
        const queryStartIndex = textBeforeCursor.lastIndexOf('#');
        const queryEndIndex = cursor;

        showAutocompleteDropdown(textarea, 'channel', query, queryStartIndex, queryEndIndex, []);
        loadAutocompleteItems('channels', query);
    } else {
        closeAutocomplete();
    }
}

function showAutocompleteDropdown(textarea, type, query, queryStartIndex, queryEndIndex, matches) {
    if (activeAutocomplete && activeAutocomplete.textarea === textarea) {
        if (!activeAutocomplete.element.isConnected) {
            closeAutocomplete();
        } else {
            activeAutocomplete.type = type;
            activeAutocomplete.query = query;
            activeAutocomplete.startIndex = queryStartIndex;
            activeAutocomplete.endIndex = queryEndIndex;
            activeAutocomplete.matches = matches;
            activeAutocomplete.activeIndex = 0;

            if (type === 'emoji' || type === 'command') {
                renderAutocompleteItems();
            } else {
                activeAutocomplete.element.innerHTML = '';
            }
            return;
        }
    }

    if (activeAutocomplete) {
        closeAutocomplete();
    }

    const dropdown = document.createElement('div');
    dropdown.className = 'emoji-autocomplete-dropdown';

    const wrapper = textarea.closest('.message-input-wrapper');
    if (wrapper) {
        wrapper.appendChild(dropdown);
    } else if (textarea.id === 'global-search-input') {
        const form = textarea.closest('form') || textarea.parentNode;
        if (form) {
            form.style.position = 'relative';
            form.appendChild(dropdown);
            dropdown.style.top = '100%';
            dropdown.style.bottom = 'auto';
            dropdown.style.left = '3rem';
            dropdown.style.right = '0';
            dropdown.style.minWidth = '100%';
            dropdown.style.maxWidth = '100%';
        }
    } else {
        document.body.appendChild(dropdown);
        const rect = textarea.getBoundingClientRect();
        dropdown.style.position = 'fixed';
        dropdown.style.bottom = `${window.innerHeight - rect.top + 6}px`;
        dropdown.style.left = `${rect.left}px`;
    }

    activeAutocomplete = {
        element: dropdown,
        textarea: textarea,
        type: type,
        query: query,
        startIndex: queryStartIndex,
        endIndex: queryEndIndex,
        matches: matches,
        activeIndex: 0
    };

    dropdown.addEventListener('click', onAutocompleteClick);

    if (type === 'emoji' || type === 'command') {
        renderAutocompleteItems();
    }
}

function onAutocompleteClick(e) {
    const item = e.target.closest('.emoji-autocomplete-item');
    if (!item || !activeAutocomplete) return;

    const items = activeAutocomplete.element.querySelectorAll('.emoji-autocomplete-item');
    const idx = Array.from(items).indexOf(item);
    if (idx === -1) return;

    if (activeAutocomplete.type === 'emoji') {
        selectAutocompleteEmoji(idx);
    } else if (activeAutocomplete.type === 'custom-emoji') {
        selectAutocompleteCustomEmoji(idx);
    } else if (activeAutocomplete.type === 'mention') {
        selectAutocompleteUser(idx);
    } else if (activeAutocomplete.type === 'channel') {
        selectAutocompleteChannel(idx);
    } else if (activeAutocomplete.type === 'command') {
        selectAutocompleteCommand(idx);
    }
}

function renderAutocompleteItems() {
    if (!activeAutocomplete) return;
    if (!activeAutocomplete.element.isConnected) {
        closeAutocomplete();
        return;
    }

    const {element, matches, activeIndex, type} = activeAutocomplete;
    element.innerHTML = '';

    if (type === 'command') {
        element.classList.add('command-dropdown');
        const header = document.createElement('div');
        header.className = 'autocomplete-commands-header';
        header.textContent = window.trans('Commandes disponibles');
        element.appendChild(header);
    } else {
        element.classList.remove('command-dropdown');
    }

    matches.forEach((match, idx) => {
        const item = document.createElement('button');
        item.type = 'button';
        item.className = `emoji-autocomplete-item${type === 'command' ? ' command-item' : ''} ${idx === activeIndex ? 'active' : ''}`;

        if (type === 'emoji') {
            const emojiSpan = document.createElement('span');
            emojiSpan.className = 'emoji';
            emojiSpan.textContent = match.emoji;

            const keywordSpan = document.createElement('span');
            keywordSpan.className = 'keyword';
            keywordSpan.textContent = `:${match.keyword}`;

            item.appendChild(emojiSpan);
            item.appendChild(keywordSpan);
        } else if (type === 'command') {
            const iconSpan = document.createElement('span');
            iconSpan.className = 'command-icon';
            iconSpan.textContent = match.icon;

            const infoContainer = document.createElement('div');
            infoContainer.className = 'command-info';

            const nameSpan = document.createElement('span');
            nameSpan.className = 'command-name';
            nameSpan.textContent = `/${match.name}`;

            const descSpan = document.createElement('span');
            descSpan.className = 'command-desc';
            descSpan.textContent = match.description;

            infoContainer.appendChild(nameSpan);
            infoContainer.appendChild(descSpan);
            item.appendChild(iconSpan);
            item.appendChild(infoContainer);
        }

        element.appendChild(item);
    });

    const activeItem = element.querySelector('.emoji-autocomplete-item.active');
    if (activeItem) activeItem.scrollIntoView({block: 'nearest'});
}

function updateAutocompleteActiveIndex() {
    if (!activeAutocomplete) return;
    if (!activeAutocomplete.element.isConnected) {
        closeAutocomplete();
        return;
    }
    const items = activeAutocomplete.element.querySelectorAll('.emoji-autocomplete-item');
    items.forEach((item, i) => {
        item.classList.toggle('active', i === activeAutocomplete.activeIndex);
    });

    const activeItem = items[activeAutocomplete.activeIndex];
    if (activeItem) activeItem.scrollIntoView({block: 'nearest'});
}

function selectAutocompleteEmoji(idx) {
    if (!activeAutocomplete) return;

    const {textarea, startIndex, endIndex, matches} = activeAutocomplete;
    const selectedEmoji = matches[idx].emoji;

    const text = textarea.value;
    textarea.value = text.substring(0, startIndex) + selectedEmoji + text.substring(endIndex);

    const newCursorPos = startIndex + selectedEmoji.length;
    textarea.selectionStart = textarea.selectionEnd = newCursorPos;

    textarea.focus();

    const event = new Event('input', {bubbles: true});
    textarea.dispatchEvent(event);

    closeAutocomplete();
}

function selectAutocompleteCustomEmoji(idx) {
    if (!activeAutocomplete) return;

    const {textarea, startIndex, endIndex, element} = activeAutocomplete;
    const items = element.querySelectorAll('.emoji-autocomplete-item[data-shortcode]');
    const selectedItem = items[idx];
    if (!selectedItem) return;

    const shortcode = selectedItem.dataset.shortcode;
    const insertText = shortcode + ' ';

    const text = textarea.value;
    textarea.value = text.substring(0, startIndex) + insertText + text.substring(endIndex);

    const newCursorPos = startIndex + insertText.length;
    textarea.selectionStart = textarea.selectionEnd = newCursorPos;

    textarea.focus();

    const event = new Event('input', {bubbles: true});
    textarea.dispatchEvent(event);

    closeAutocomplete();
}

function selectAutocompleteUser(idx) {
    if (!activeAutocomplete) return;

    const {textarea, startIndex, endIndex, element} = activeAutocomplete;
    const items = element.querySelectorAll('.emoji-autocomplete-item[data-username]');
    const selectedItem = items[idx];
    if (!selectedItem) return;

    const username = selectedItem.dataset.username;

    if (textarea.id === 'admin-autocomplete-input') {
        closeAutocomplete();
        textarea.value = '';
        return;
    }

    const insertText = '@' + username + ' ';
    const text = textarea.value;
    textarea.value = text.substring(0, startIndex) + insertText + text.substring(endIndex);

    const newCursorPos = startIndex + insertText.length;
    textarea.selectionStart = textarea.selectionEnd = newCursorPos;

    textarea.focus();

    const event = new Event('input', {bubbles: true});
    textarea.dispatchEvent(event);

    closeAutocomplete();
}

function selectAutocompleteChannel(idx) {
    if (!activeAutocomplete) return;

    const {textarea, startIndex, endIndex, element} = activeAutocomplete;
    const items = element.querySelectorAll('.emoji-autocomplete-item[data-slug]');
    const selectedItem = items[idx];
    if (!selectedItem) return;

    const slug = selectedItem.dataset.slug;
    const insertText = '#' + slug + ' ';

    const text = textarea.value;
    textarea.value = text.substring(0, startIndex) + insertText + text.substring(endIndex);

    const newCursorPos = startIndex + insertText.length;
    textarea.selectionStart = textarea.selectionEnd = newCursorPos;

    textarea.focus();

    const event = new Event('input', {bubbles: true});
    textarea.dispatchEvent(event);

    closeAutocomplete();
}

function selectAutocompleteCommand(idx) {
    if (!activeAutocomplete) return;

    const {textarea, matches} = activeAutocomplete;
    const selectedCommand = matches[idx];
    const insertText = `/${selectedCommand.name} `;

    textarea.value = insertText;
    textarea.selectionStart = textarea.selectionEnd = insertText.length;
    textarea.focus();

    const event = new Event('input', {bubbles: true});
    textarea.dispatchEvent(event);

    closeAutocomplete();
}

export function closeAutocomplete() {
    if (!activeAutocomplete) return;
    activeAutocomplete.element.remove();
    activeAutocomplete = null;
}

function handleTextareaKeydownForAutocomplete(textarea, e) {
    if (!activeAutocomplete || activeAutocomplete.textarea !== textarea) return;

    if (!activeAutocomplete.element.isConnected) {
        closeAutocomplete();
        return;
    }

    const {matches, activeIndex, type} = activeAutocomplete;

    if (e.key === 'ArrowDown') {
        e.preventDefault();
        e.stopPropagation();

        const items = activeAutocomplete.element.querySelectorAll('.emoji-autocomplete-item');
        const maxIdx = items.length > 0 ? items.length : matches.length;

        if (maxIdx === 0) return;

        activeAutocomplete.activeIndex = (activeIndex + 1) % maxIdx;

        if (type === 'emoji' || type === 'command') {
            renderAutocompleteItems();
        } else {
            updateAutocompleteActiveIndex();
        }
    } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        e.stopPropagation();

        const items = activeAutocomplete.element.querySelectorAll('.emoji-autocomplete-item');
        const maxIdx = items.length > 0 ? items.length : matches.length;

        if (maxIdx === 0) return;

        activeAutocomplete.activeIndex = (activeIndex - 1 + maxIdx) % maxIdx;

        if (type === 'emoji' || type === 'command') {
            renderAutocompleteItems();
        } else {
            updateAutocompleteActiveIndex();
        }
    } else if (e.key === 'Enter' || e.key === 'Tab') {
        e.preventDefault();
        e.stopPropagation();
        if (type === 'emoji') {
            selectAutocompleteEmoji(activeIndex);
        } else if (type === 'custom-emoji') {
            selectAutocompleteCustomEmoji(activeIndex);
        } else if (type === 'mention') {
            selectAutocompleteUser(activeIndex);
        } else if (type === 'channel') {
            selectAutocompleteChannel(activeIndex);
        } else if (type === 'command') {
            selectAutocompleteCommand(activeIndex);
        }
    } else if (e.key === 'Escape') {
        e.preventDefault();
        e.stopPropagation();
        closeAutocomplete();
    }
}

// Global window binds
window.initEmojiAutocomplete = initEmojiAutocomplete;
window.closeAutocomplete = closeAutocomplete;

window.setupGenericAutocomplete = function ({input, suggestions, onSearch, onSelect}) {
    if (!input || !suggestions) return;

    input.oninput = function () {
        const query = this.value.trim();
        if (query === '') {
            suggestions.style.display = 'none';
            return;
        }

        const matches = onSearch(query);

        if (matches.length === 0) {
            suggestions.innerHTML = `<div class="emoji-autocomplete-item" style="color: var(--text-muted); text-align: center; justify-content: center;">${window.trans('Aucun résultat')}</div>`;
        } else {
            suggestions.innerHTML = '';
            matches.forEach(item => {
                const div = document.createElement('div');
                div.className = 'emoji-autocomplete-item';
                div.innerText = item.label || item.name || item;

                div.addEventListener('click', () => {
                    onSelect(item);
                    input.value = '';
                    suggestions.style.display = 'none';
                });
                suggestions.appendChild(div);
            });
        }
        suggestions.style.display = 'block';
    };

    const closeHandler = function (e) {
        if (!input.contains(e.target) && !suggestions.contains(e.target)) {
            suggestions.style.display = 'none';
        }
    };
    document.removeEventListener('click', closeHandler);
    document.addEventListener('click', closeHandler);
};
