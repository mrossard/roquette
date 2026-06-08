export function toggleSearchBuilder() {
    const panel = document.getElementById('search-builder-panel');
    const icon = document.querySelector('.builder-toggle-icon');
    if (!panel) return;

    if (panel.style.display === 'none' || panel.style.display === '') {
        panel.style.display = 'flex';
        icon.textContent = '▼';
        populateSearchBuilderDropdowns();
        parseQueryIntoBuilder();
    } else {
        panel.style.display = 'none';
        icon.textContent = '▶';
    }
}

let usersLoaded = false;

function populateSearchBuilderDropdowns() {
    const selectFrom = document.getElementById('builder-from');
    const selectIn = document.getElementById('builder-in');
    if (!selectFrom || !selectIn) return;

    const currentFrom = selectFrom.value;
    const currentIn = selectIn.value;

    selectIn.innerHTML = '<option value="">Tous les canaux</option>';

    const channelLinks = document.querySelectorAll('.channel-link');
    const addedChannels = new Set();
    channelLinks.forEach(link => {
        const slug = link.getAttribute('data-channel-slug');
        const nameElt = link.querySelector('.channel-name');
        if (slug && nameElt) {
            const name = nameElt.textContent.trim();
            const isDm = link.querySelector('.status-dot') !== null;
            const prefix = isDm ? '@' : '#';

            if (!addedChannels.has(slug)) {
                addedChannels.add(slug);
                const opt = document.createElement('option');
                opt.value = slug;
                opt.textContent = prefix + ' ' + name;
                selectIn.appendChild(opt);
            }
        }
    });
    if (currentIn) selectIn.value = currentIn;

    if (!usersLoaded) {
        usersLoaded = true;
        const panel = document.getElementById('search-builder-panel');
        const usersUrl = panel?.getAttribute('data-users-url') || '/api/users-options';
        htmx.ajax('GET', usersUrl, {
            target: '#builder-from',
            swap: 'innerHTML',
        }).then(() => {
            if (currentFrom) selectFrom.value = currentFrom;
            parseQueryIntoBuilder();
        });
    } else {
        if (currentFrom) selectFrom.value = currentFrom;
    }
}

export function parseQueryIntoBuilder() {
    const queryInput = document.getElementById('global-search-input');
    const builderFrom = document.getElementById('builder-from');
    const builderIn = document.getElementById('builder-in');
    const builderHas = document.getElementById('builder-has');
    const builderText = document.getElementById('builder-text');

    if (!queryInput || !builderFrom || !builderIn || !builderHas || !builderText) return;

    let query = queryInput.value;

    let fromVal = '';
    const fromMatch = query.match(/from:([^\s"]+|"[^"]+")/);
    if (fromMatch) {
        fromVal = fromMatch[1].replace(/"/g, '').replace(/^@/, '');
        query = query.replace(fromMatch[0], '');
    }
    builderFrom.value = fromVal;

    let inVal = '';
    const inMatch = query.match(/in:([^\s"]+|"[^"]+")/);
    if (inMatch) {
        inVal = inMatch[1].replace(/"/g, '').replace(/^#/, '');
        query = query.replace(inMatch[0], '');
    }
    builderIn.value = inVal;

    let hasVal = '';
    const hasMatch = query.match(/has:([^\s]+)/);
    if (hasMatch) {
        hasVal = hasMatch[1].toLowerCase();
        query = query.replace(hasMatch[0], '');
    }
    builderHas.value = hasVal;

    builderText.value = query.replace(/\s+/g, ' ').trim();
}

export function updateSearchQueryFromBuilder() {
    const queryInput = document.getElementById('global-search-input');
    const builderFrom = document.getElementById('builder-from');
    const builderIn = document.getElementById('builder-in');
    const builderHas = document.getElementById('builder-has');
    const builderText = document.getElementById('builder-text');

    if (!queryInput || !builderFrom || !builderIn || !builderHas || !builderText) return;

    let parts = [];

    if (builderFrom.value) {
        parts.push(`from:${builderFrom.value}`);
    }
    if (builderIn.value) {
        parts.push(`in:${builderIn.value}`);
    }
    if (builderHas.value) {
        parts.push(`has:${builderHas.value}`);
    }
    if (builderText.value.trim()) {
        parts.push(builderText.value.trim());
    }

    queryInput.value = parts.join(' ');
}

window.toggleSearchBuilder = toggleSearchBuilder;
window.updateSearchQueryFromBuilder = updateSearchQueryFromBuilder;

document.addEventListener('DOMContentLoaded', () => {
    const queryInput = document.getElementById('global-search-input');
    if (queryInput) {
        queryInput.addEventListener('input', () => {
            const panel = document.getElementById('search-builder-panel');
            if (panel && panel.style.display !== 'none') {
                parseQueryIntoBuilder();
            }
        });
    }

    const panel = document.getElementById('search-builder-panel');
    if (panel) {
        panel.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                const form = document.getElementById('global-search-input')?.closest('form');
                if (form) {
                    htmx.trigger(form, 'submit');
                }
            }
        });
    }
});
