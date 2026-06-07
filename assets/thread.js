

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
                setTimeout(() => {
                    const threadFeed = document.getElementById('thread-replies-feed');
                    if (threadFeed) threadFeed.scrollTop = threadFeed.scrollHeight;
                }, 50);
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
        setTimeout(() => {
            const threadFeed = document.getElementById('thread-replies-feed');
            if (threadFeed) threadFeed.scrollTop = threadFeed.scrollHeight;
        }, 50);
    });

    if (clearBtn) {
        clearBtn.addEventListener('click', () => {
            fileInput.value = '';
            previewContainer.style.display = 'none';
            if (textarea.value.trim() === '') {
                textarea.setAttribute('required', 'required');
            }
            setTimeout(() => {
                const threadFeed = document.getElementById('thread-replies-feed');
                if (threadFeed) threadFeed.scrollTop = threadFeed.scrollHeight;
            }, 50);
        });
    }
}

window.initThreadTextareaResize = initThreadTextareaResize;
window.initThreadFileUpload = initThreadFileUpload;
