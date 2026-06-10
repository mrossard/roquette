import { EMOJI_CATEGORIES, EMOJI_KEYWORDS } from './emoji-data.js';

let activePicker = null;
const emojiPickerInitialized = new WeakSet();

export function initEmojiPickers() {
    const triggers = document.querySelectorAll('.btn-emoji-toggle');
    
    triggers.forEach(trigger => {
        if (emojiPickerInitialized.has(trigger)) return;
        emojiPickerInitialized.add(trigger);
        
        trigger.addEventListener('click', (e) => {
            e.stopPropagation();
            toggleEmojiPicker(trigger);
        });
    });
}

function getTargetTextarea(trigger) {
    const selector = trigger.getAttribute('data-emoji-target');
    if (!selector) return null;
    
    if (selector === 'textarea') {
        const wrapper = trigger.closest('.message-input-wrapper');
        return wrapper ? wrapper.querySelector('textarea') : null;
    }
    
    return document.querySelector(selector);
}

export function toggleEmojiPicker(trigger) {
    if (activePicker && activePicker.trigger === trigger) {
        closeEmojiPicker();
        return;
    }
    
    if (activePicker) {
        closeEmojiPicker();
    }
    
    createEmojiPicker(trigger);
}

export function closeEmojiPicker() {
    if (!activePicker) return;
    activePicker.element.remove();
    activePicker = null;
}

function insertEmoji(textarea, emoji) {
    const start = textarea.selectionStart;
    const end = textarea.selectionEnd;
    const text = textarea.value;
    
    textarea.value = text.substring(0, start) + emoji + text.substring(end);
    
    const newCursorPos = start + emoji.length;
    textarea.selectionStart = textarea.selectionEnd = newCursorPos;
    
    const isMobile = window.matchMedia('(max-width: 1024px)').matches || ('ontouchstart' in window) || (navigator.maxTouchPoints > 0);
    if (!isMobile) {
        textarea.focus();
    }
    
    const event = new Event('input', { bubbles: true });
    textarea.dispatchEvent(event);
}

export function buildEmojiPickerDOM(onSelect) {
    const picker = document.createElement('div');
    picker.className = 'emoji-picker';
    
    const searchContainer = document.createElement('div');
    searchContainer.className = 'emoji-picker-search';
    const searchInput = document.createElement('input');
    searchInput.type = 'text';
    searchInput.placeholder = window.AppTranslations?.["Rechercher un émoji..."] || 'Rechercher un émoji...';
    searchContainer.appendChild(searchInput);
    picker.appendChild(searchContainer);
    
    const tabsContainer = document.createElement('div');
    tabsContainer.className = 'emoji-picker-tabs';
    
    EMOJI_CATEGORIES.forEach((cat, idx) => {
        const tabBtn = document.createElement('button');
        tabBtn.type = 'button';
        tabBtn.className = `emoji-picker-tab ${idx === 0 ? 'active' : ''}`;
        tabBtn.title = window.AppTranslations?.[cat.name] || cat.name;
        tabBtn.textContent = cat.icon;
        tabBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            switchCategory(idx);
        });
        tabsContainer.appendChild(tabBtn);
    });
    picker.appendChild(tabsContainer);
    
    const listContainer = document.createElement('div');
    listContainer.className = 'emoji-picker-list';
    
    EMOJI_CATEGORIES.forEach((cat, idx) => {
        const catSection = document.createElement('div');
        catSection.className = 'emoji-category-section';
        catSection.setAttribute('data-category-id', cat.id);
        if (idx !== 0) catSection.style.display = 'none';
        
        const catTitle = document.createElement('h4');
        catTitle.className = 'emoji-category-title';
        catTitle.textContent = window.AppTranslations?.[cat.name] || cat.name;
        catSection.appendChild(catTitle);
        
        const grid = document.createElement('div');
        grid.className = 'emoji-grid';
        
        cat.emojis.forEach(emoji => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'emoji-item';
            btn.textContent = emoji;
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                onSelect(emoji);
            });
            grid.appendChild(btn);
        });
        
        catSection.appendChild(grid);
        listContainer.appendChild(catSection);
    });
    picker.appendChild(listContainer);
    
    function switchCategory(activeIndex) {
        const tabs = tabsContainer.querySelectorAll('.emoji-picker-tab');
        tabs.forEach((tab, idx) => {
            if (idx === activeIndex) {
                tab.classList.add('active');
            } else {
                tab.classList.remove('active');
            }
        });
        
        const sections = listContainer.querySelectorAll('.emoji-category-section');
        sections.forEach((section, idx) => {
            if (idx === activeIndex) {
                section.style.display = 'block';
            } else {
                section.style.display = 'none';
            }
        });
        
        searchInput.value = '';
        searchInput.dispatchEvent(new Event('input'));
    }
    
    searchInput.addEventListener('input', (e) => {
        const val = e.target.value.toLowerCase().trim();
        
        const noResults = listContainer.querySelector('.emoji-picker-no-results');
        if (noResults) noResults.remove();
        
        if (val === '') {
            const activeTabIdx = Array.from(tabsContainer.querySelectorAll('.emoji-picker-tab')).findIndex(t => t.classList.contains('active'));
            switchCategory(activeTabIdx >= 0 ? activeTabIdx : 0);
            return;
        }
        
        const tabs = tabsContainer.querySelectorAll('.emoji-picker-tab');
        tabs.forEach(t => t.classList.remove('active'));
        
        let totalMatches = 0;
        
        const sections = listContainer.querySelectorAll('.emoji-category-section');
        sections.forEach(section => {
            section.style.display = 'block';
            
            const grid = section.querySelector('.emoji-grid');
            const items = grid.querySelectorAll('.emoji-item');
            
            let sectionMatches = 0;
            
            items.forEach(item => {
                const emoji = item.textContent;
                const matchKeywords = EMOJI_KEYWORDS[emoji] || [];
                const isMatch = matchKeywords.some(keyword => keyword.includes(val));
                
                if (isMatch) {
                    item.style.display = 'flex';
                    sectionMatches++;
                    totalMatches++;
                } else {
                    item.style.display = 'none';
                }
            });
            
            if (sectionMatches === 0) {
                section.style.display = 'none';
            } else {
                section.style.display = 'block';
            }
        });
        
        if (totalMatches === 0) {
            const noResultsDiv = document.createElement('div');
            noResultsDiv.className = 'emoji-picker-no-results';
            noResultsDiv.innerHTML = `
                <div style="font-size: 2rem;">🔍</div>
                <div>${window.AppTranslations?.["Aucun émoji ne correspond"] || 'Aucun émoji ne correspond'}</div>
            `;
            listContainer.appendChild(noResultsDiv);
        }
    });
    
    return {
        element: picker,
        focusSearch: () => setTimeout(() => searchInput.focus(), 50)
    };
}

function createEmojiPicker(trigger) {
    const targetTextarea = getTargetTextarea(trigger);
    if (!targetTextarea) return;
    
    const { element: picker, focusSearch } = buildEmojiPickerDOM(emoji => {
        insertEmoji(targetTextarea, emoji);
    });
    
    const wrapper = trigger.closest('.message-input-wrapper');
    if (wrapper) {
        wrapper.appendChild(picker);
    } else {
        document.body.appendChild(picker);
        const rect = trigger.getBoundingClientRect();
        picker.style.position = 'fixed';
        picker.style.bottom = `${window.innerHeight - rect.top + 8}px`;
        picker.style.right = `${window.innerWidth - rect.right}px`;
    }
    
    activePicker = {
        element: picker,
        trigger: trigger,
        textarea: targetTextarea
    };
    
    focusSearch();
}

// Global click handler helper
document.addEventListener('click', (e) => {
    if (activePicker && !activePicker.element.contains(e.target) && e.target !== activePicker.trigger) {
        closeEmojiPicker();
    }
});

document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        if (activePicker) {
            closeEmojiPicker();
        }
    }
});

// Global window binds
window.initEmojiPickers = initEmojiPickers;
window.closeEmojiPicker = closeEmojiPicker;
window.toggleEmojiPicker = toggleEmojiPicker;
