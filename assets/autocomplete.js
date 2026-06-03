import { EMOJI_CATEGORIES, EMOJI_KEYWORDS } from './emoji-data.js';
import { EMOJI_PRIMARY_SHORTCODES } from './emoji-primary-shortcodes.js';

let activeAutocomplete = null;
let appUsers = [];

// Slash commands available in the app
const SLASH_COMMANDS = [
    { name: 'giphy',  icon: '🎞️',  description: 'Rechercher et envoyer un GIF animé',   usage: '/giphy <recherche>' },
    { name: 'shrug',  icon: '🤷',  description: 'Envoyer le shrug ¯\\_(ツ)_/¯',         usage: '/shrug [texte]' },
    { name: 'me',     icon: '💬',  description: 'Action',                                usage: '/me <message>' },
    { name: 'color',  icon: '🎨',  description: 'Changer la couleur de votre pseudo',    usage: '/color [0-360]' },
];

async function fetchUsersForAutocomplete() {
    try {
        const response = await fetch('/api/users');
        if (response.ok) {
            appUsers = await response.json();
        }
    } catch (e) {
        console.error('Failed to fetch users for autocomplete:', e);
    }
}

function findMatchingUsersForQuery(query) {
    if (!query) {
        return appUsers.slice(0, 6);
    }

    const matched = [];
    appUsers.forEach(user => {
        const username = (user.username || '').toLowerCase();
        const displayName = (user.displayName || '').toLowerCase();

        let priority = 0;
        if (username.startsWith(query)) {
            priority = 3;
        } else if (displayName.startsWith(query)) {
            priority = 2;
        } else if (username.includes(query) || displayName.includes(query)) {
            priority = 1;
        }

        if (priority > 0) {
            matched.push({
                user,
                priority
            });
        }
    });

    return matched
        .sort((a, b) => b.priority - a.priority)
        .map(item => item.user)
        .slice(0, 6);
}

function findMatchingEmojisForQuery(query) {
    const matched = [];
    const seen = new Set();

    for (const cat of EMOJI_CATEGORIES) {
        for (const emoji of cat.emojis) {
            if (seen.has(emoji)) continue;

            const keywords = EMOJI_KEYWORDS[emoji] || [];
            const priorityMatch = keywords.some(kw => kw.startsWith(query));
            const containsMatch = keywords.some(kw => kw.includes(query));

            if (priorityMatch || containsMatch) {
                const primaryShortcode = EMOJI_PRIMARY_SHORTCODES[emoji] || keywords[0] || '';
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
    const textareas = document.querySelectorAll('textarea:not([data-autocomplete-initialized])');

    if (textareas.length > 0 && appUsers.length === 0) {
        fetchUsersForAutocomplete();
    }

    textareas.forEach(textarea => {
        textarea.setAttribute('data-autocomplete-initialized', 'true');

        textarea.addEventListener('input', () => {
            handleTextareaInputForAutocomplete(textarea);
        });

        textarea.addEventListener('keydown', (e) => {
            handleTextareaKeydownForAutocomplete(textarea, e);
        });

        textarea.addEventListener('blur', () => {
            setTimeout(() => {
                if (activeAutocomplete && activeAutocomplete.textarea === textarea) {
                    closeAutocomplete();
                }
            }, 150);
        });
    });
}

function handleTextareaInputForAutocomplete(textarea) {
    const cursor = textarea.selectionStart;
    const text = textarea.value;

    const textBeforeCursor = text.substring(0, cursor);
    const matchEmoji = textBeforeCursor.match(/:([a-zA-Z0-9_à-ÿÀ-Ÿ]{1,})$/);
    const matchMention = textBeforeCursor.match(/(?:^|\s)@([a-zA-Z0-9_à-ÿÀ-Ÿ]{0,})$/);
    const matchCommand = textBeforeCursor.match(/^\/([a-zA-Z0-9_]*)$/);

    if (matchCommand) {
        const query = matchCommand[1].toLowerCase();
        const matches = findMatchingCommands(query);

        if (matches.length === 0) {
            closeAutocomplete();
            return;
        }

        showAutocompleteDropdown(textarea, 'command', query, 0, cursor, matches);
    } else if (matchEmoji) {
        const query = matchEmoji[1].toLowerCase();
        const queryStartIndex = cursor - matchEmoji[0].length;
        const queryEndIndex = cursor;

        const matches = findMatchingEmojisForQuery(query);

        if (matches.length === 0) {
            closeAutocomplete();
            return;
        }

        showAutocompleteDropdown(textarea, 'emoji', query, queryStartIndex, queryEndIndex, matches);
    } else if (matchMention) {
        const query = matchMention[1].toLowerCase();
        const queryStartIndex = textBeforeCursor.lastIndexOf('@');
        const queryEndIndex = cursor;

        if (appUsers.length === 0) {
            fetchUsersForAutocomplete().then(() => {
                handleTextareaInputForAutocomplete(textarea);
            });
            return;
        }

        const matches = findMatchingUsersForQuery(query);

        if (matches.length === 0) {
            closeAutocomplete();
            return;
        }

        showAutocompleteDropdown(textarea, 'mention', query, queryStartIndex, queryEndIndex, matches);
    } else {
        closeAutocomplete();
    }
}

function showAutocompleteDropdown(textarea, type, query, queryStartIndex, queryEndIndex, matches) {
    if (activeAutocomplete && activeAutocomplete.textarea === textarea) {
        activeAutocomplete.type = type;
        activeAutocomplete.query = query;
        activeAutocomplete.startIndex = queryStartIndex;
        activeAutocomplete.endIndex = queryEndIndex;
        activeAutocomplete.matches = matches;
        activeAutocomplete.activeIndex = 0;

        renderAutocompleteItems();
        return;
    }

    if (activeAutocomplete) {
        closeAutocomplete();
    }

    const dropdown = document.createElement('div');
    dropdown.className = 'emoji-autocomplete-dropdown';

    const wrapper = textarea.closest('.message-input-wrapper');
    if (wrapper) {
        wrapper.appendChild(dropdown);
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

    renderAutocompleteItems();
}

function renderAutocompleteItems() {
    if (!activeAutocomplete) return;

    const { element, matches, activeIndex, type } = activeAutocomplete;
    element.innerHTML = '';

    // Add command-dropdown class for wider styling
    if (type === 'command') {
        element.classList.add('command-dropdown');
        const header = document.createElement('div');
        header.className = 'autocomplete-commands-header';
        header.textContent = 'Commandes disponibles';
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
        } else if (type === 'mention') {
            const avatar = document.createElement('div');
            avatar.className = 'user-avatar-circle';
            avatar.style.backgroundColor = `hsl(${match.hue}, 90%, 65%)`;
            avatar.style.width = '20px';
            avatar.style.height = '20px';
            avatar.style.borderRadius = '50%';
            avatar.style.display = 'flex';
            avatar.style.alignItems = 'center';
            avatar.style.justifyContent = 'center';
            avatar.style.fontWeight = '700';
            avatar.style.color = '#111';
            avatar.style.fontSize = '0.7rem';
            avatar.style.flexShrink = '0';
            avatar.textContent = (match.username || 'U')[0].toUpperCase();

            const namesContainer = document.createElement('div');
            namesContainer.style.display = 'flex';
            namesContainer.style.alignItems = 'baseline';
            namesContainer.style.gap = '6px';
            namesContainer.style.overflow = 'hidden';

            const displayNameSpan = document.createElement('span');
            displayNameSpan.style.fontWeight = '600';
            displayNameSpan.style.whiteSpace = 'nowrap';
            displayNameSpan.style.textOverflow = 'ellipsis';
            displayNameSpan.style.overflow = 'hidden';
            displayNameSpan.textContent = match.displayName || match.username;

            const usernameSpan = document.createElement('span');
            usernameSpan.className = 'keyword';
            usernameSpan.textContent = `@${match.username}`;

            namesContainer.appendChild(displayNameSpan);
            namesContainer.appendChild(usernameSpan);

            item.appendChild(avatar);
            item.appendChild(namesContainer);
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

        item.addEventListener('click', (e) => {
            e.stopPropagation();
            if (type === 'emoji') {
                selectAutocompleteEmoji(idx);
            } else if (type === 'mention') {
                selectAutocompleteUser(idx);
            } else if (type === 'command') {
                selectAutocompleteCommand(idx);
            }
        });

        element.appendChild(item);
    });
}

function selectAutocompleteEmoji(idx) {
    if (!activeAutocomplete) return;

    const { textarea, startIndex, endIndex, matches } = activeAutocomplete;
    const selectedEmoji = matches[idx].emoji;

    const text = textarea.value;
    textarea.value = text.substring(0, startIndex) + selectedEmoji + text.substring(endIndex);

    const newCursorPos = startIndex + selectedEmoji.length;
    textarea.selectionStart = textarea.selectionEnd = newCursorPos;

    textarea.focus();

    const event = new Event('input', { bubbles: true });
    textarea.dispatchEvent(event);

    closeAutocomplete();
}

function selectAutocompleteUser(idx) {
    if (!activeAutocomplete) return;

    const { textarea, startIndex, endIndex, matches } = activeAutocomplete;
    const selectedUser = matches[idx];
    const insertText = '@' + selectedUser.username + ' ';

    const text = textarea.value;
    textarea.value = text.substring(0, startIndex) + insertText + text.substring(endIndex);

    const newCursorPos = startIndex + insertText.length;
    textarea.selectionStart = textarea.selectionEnd = newCursorPos;

    textarea.focus();

    const event = new Event('input', { bubbles: true });
    textarea.dispatchEvent(event);

    closeAutocomplete();
}

function selectAutocompleteCommand(idx) {
    if (!activeAutocomplete) return;

    const { textarea, matches } = activeAutocomplete;
    const selectedCommand = matches[idx];
    // Insert the command name followed by a space so the user can type args directly
    const insertText = `/${selectedCommand.name} `;

    textarea.value = insertText;
    textarea.selectionStart = textarea.selectionEnd = insertText.length;
    textarea.focus();

    const event = new Event('input', { bubbles: true });
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

    const { matches, activeIndex, type } = activeAutocomplete;

    if (e.key === 'ArrowDown') {
        e.preventDefault();
        e.stopPropagation();
        activeAutocomplete.activeIndex = (activeIndex + 1) % matches.length;
        renderAutocompleteItems();
    } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        e.stopPropagation();
        activeAutocomplete.activeIndex = (activeIndex - 1 + matches.length) % matches.length;
        renderAutocompleteItems();
    } else if (e.key === 'Enter' || e.key === 'Tab') {
        e.preventDefault();
        e.stopPropagation();
        if (type === 'emoji') {
            selectAutocompleteEmoji(activeIndex);
        } else if (type === 'mention') {
            selectAutocompleteUser(activeIndex);
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
