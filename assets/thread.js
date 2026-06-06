
export function insertThreadMarkdown(formattingType) {
    const textarea = document.getElementById('thread-message');
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
        const newCursorPos = start + replacement.length;
        textarea.setSelectionRange(newCursorPos, newCursorPos);
    }

    textarea.dispatchEvent(new Event('input', { bubbles: true }));
}

// Send thread reply on Enter (without Shift/Alt)
document.addEventListener('keydown', (event) => {
    if (!event.target || event.target.id !== 'thread-message') return;
    const textarea = event.target;

    if (event.key === 'Enter') {
        if (!event.shiftKey && !event.altKey) {
            event.preventDefault();
            const form = textarea.closest('form');
            if (form) form.requestSubmit();
        } else if (event.altKey) {
            event.preventDefault();
            const start = textarea.selectionStart;
            const end = textarea.selectionEnd;
            const value = textarea.value;
            textarea.value = value.substring(0, start) + '\n' + value.substring(end);
            textarea.selectionStart = textarea.selectionEnd = start + 1;
            textarea.dispatchEvent(new Event('input', { bubbles: true }));
        }
    }
});

// After HTMX form submission in thread pane, re-init the new input form
document.body.addEventListener('htmx:afterSwap', (evt) => {
    if (evt.detail.target && evt.detail.target.classList && evt.detail.target.classList.contains('thread-message-form')) {
        if (window.initEmojiPickers) window.initEmojiPickers();
        initThreadFileUpload();
        initThreadTextareaResize();
        const threadTextarea = document.getElementById('thread-message');
        const isMobile = window.matchMedia('(max-width: 1024px)').matches || ('ontouchstart' in window) || (navigator.maxTouchPoints > 0);
        if (threadTextarea && !isMobile) threadTextarea.focus();
    }
});

export function initThreadTextareaResize() {
    const textarea = document.getElementById('thread-message');
    if (!textarea || textarea.dataset.threadResizeInitialized) return;
    textarea.dataset.threadResizeInitialized = 'true';

    function resize() {
        textarea.style.height = 'auto';
        textarea.style.height = Math.min(textarea.scrollHeight, 200) + 'px';
    }
    textarea.addEventListener('input', resize);
}

export function initThreadFileUpload() {
    const fileInput = document.getElementById('thread-file-upload');
    const textarea = document.getElementById('thread-message');
    const previewContainer = document.getElementById('thread-file-preview-container');
    const previewName = document.getElementById('thread-file-preview-name');
    const clearBtn = document.getElementById('thread-btn-clear-file');

    if (!fileInput || !textarea || !previewContainer || !previewName) return;
    if (fileInput.dataset.initialized) return;
    fileInput.dataset.initialized = 'true';

    fileInput.addEventListener('change', () => {
        if (fileInput.files && fileInput.files.length > 0) {
            const file = fileInput.files[0];
            const MAX_SIZE = 10485760; // 10MB
            if (file.size > MAX_SIZE) {
                alert(`Le fichier "${file.name}" dépasse la taille maximale autorisée de 10 Mo.`);
                fileInput.value = '';
                previewContainer.style.display = 'none';
                if (textarea.value.trim() === '') {
                    textarea.setAttribute('required', 'required');
                }
                return;
            }
            const sizeFormatted = typeof window.formatBytes === 'function' ? window.formatBytes(file.size) : `${file.size} B`;
            previewName.textContent = `${file.name} (${sizeFormatted})`;
            previewContainer.style.display = 'flex';
            textarea.removeAttribute('required');
        } else {
            previewContainer.style.display = 'none';
            if (textarea.value.trim() === '') {
                textarea.setAttribute('required', 'required');
            }
        }
    });

    if (clearBtn) {
        clearBtn.addEventListener('click', () => {
            fileInput.value = '';
            previewContainer.style.display = 'none';
            if (textarea.value.trim() === '') {
                textarea.setAttribute('required', 'required');
            }
        });
    }
}

// Global window binds

window.insertThreadMarkdown = insertThreadMarkdown;
window.initThreadTextareaResize = initThreadTextareaResize;
window.initThreadFileUpload = initThreadFileUpload;
