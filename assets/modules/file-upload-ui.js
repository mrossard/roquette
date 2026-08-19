import { clearDraftForActiveChannel } from './draft.js';
import { adjustScrollForFeedContent } from './scroll.js';

/**
 * Formate un nombre d'octets en chaîne lisible (B, KB, MB, GB, TB).
 *
 * @param {number} bytes
 * @param {number} decimals
 * @returns {string}
 */
export function formatBytes(bytes, decimals = 2) {
    if (bytes === 0) return '0 B';
    const k = 1024;
    const dm = decimals < 0 ? 0 : decimals;
    const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(dm)) + ' ' + sizes[i];
}

function setupDragAndDrop(chatPanel, dragOverlay) {
    if (!chatPanel || !dragOverlay || chatPanel.classList.contains('drag-drop-bound')) return;

    chatPanel.classList.add('drag-drop-bound');
    let dragCounter = 0;

    const isDragSourceFiles = (e) => {
        if (!e.dataTransfer) return false;
        if (e.dataTransfer.types) {
            for (let i = 0; i < e.dataTransfer.types.length; i++) {
                if (e.dataTransfer.types[i] === 'Files') {
                    return true;
                }
            }
        }
        return false;
    };

    const preventDefaults = (e) => {
        e.preventDefault();
        e.stopPropagation();
    };

    ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
        chatPanel.addEventListener(eventName, preventDefaults, false);
    });

    chatPanel.addEventListener('dragenter', (e) => {
        if (!isDragSourceFiles(e)) return;
        dragCounter++;
        if (dragCounter === 1) {
            const currentOverlay = document.getElementById('drag-drop-overlay') || dragOverlay;
            if (currentOverlay) {
                currentOverlay.classList.add('active');
            }
        }
    }, false);

    chatPanel.addEventListener('dragleave', (e) => {
        if (!isDragSourceFiles(e)) return;
        dragCounter--;
        if (dragCounter === 0) {
            const currentOverlay = document.getElementById('drag-drop-overlay') || dragOverlay;
            if (currentOverlay) {
                currentOverlay.classList.remove('active');
            }
        }
    }, false);

    chatPanel.addEventListener('drop', (e) => {
        if (!isDragSourceFiles(e)) return;
        dragCounter = 0;
        const currentOverlay = document.getElementById('drag-drop-overlay') || dragOverlay;
        if (currentOverlay) {
            currentOverlay.classList.remove('active');
        }

        const files = e.dataTransfer.files;
        if (files && files.length > 0) {
            const currentFileInput = document.getElementById('file-upload');
            if (currentFileInput) {
                currentFileInput.files = files;
                currentFileInput.dispatchEvent(new Event('change'));
            }
        }
    }, false);
}

function handlePasteUpload(event, fileInput) {
    const items = (event.clipboardData || event.originalEvent.clipboardData).items;
    for (let item of items) {
        if (item.kind === 'file') {
            const file = item.getAsFile();
            const dataTransfer = new DataTransfer();
            dataTransfer.items.add(file);
            fileInput.files = dataTransfer.files;
            fileInput.dispatchEvent(new Event('change'));
            event.preventDefault(); // Don't paste file contents as text
            break;
        }
    }
}

/**
 * Initialise les champs de sélection de fichier, l'aperçu, le drag & drop et le collage d'images/fichiers.
 */
export function initFileUpload() {
    const fileInput = document.getElementById('file-upload');
    const textarea = document.getElementById('message');
    const previewContainer = document.getElementById('file-preview-container');
    const previewName = document.getElementById('file-preview-name');
    const clearBtn = document.getElementById('btn-clear-file');

    if (!fileInput || !textarea || !previewContainer || !previewName) return;

    // Drag & drop handlers - setup on chat panel even if fileInput was already initialized
    const chatPanel = document.querySelector('.chat-panel');
    const dragOverlay = document.getElementById('drag-drop-overlay');
    setupDragAndDrop(chatPanel, dragOverlay);

    // Prevent default drag/drop behaviors globally to avoid browser page navigation on stray drops
    if (!window.dragAndDropGlobalBound) {
        window.dragAndDropGlobalBound = true;
        ['dragover', 'drop'].forEach(eventName => {
            window.addEventListener(eventName, (e) => {
                e.preventDefault();
            }, false);
        });
    }

    if (fileInput.dataset.initialized === 'true') return;
    fileInput.dataset.initialized = 'true';

    // Paste event handler
    textarea.addEventListener('paste', (event) => {
        handlePasteUpload(event, fileInput);
    });

    // Change event handler for file input
    fileInput.addEventListener('change', () => {
        if (fileInput.files && fileInput.files.length > 0) {
            const file = fileInput.files[0];
            const MAX_SIZE = 10485760; // 10MB
            if (file.size > MAX_SIZE) {
                const message = window.trans ? window.trans('Le fichier %fileName% dépasse la taille maximale de 10 MB.', {'%fileName%': file.name}) : `Le fichier ${file.name} dépasse la taille maximale de 10 MB.`;
                alert(message);
                fileInput.value = '';
                previewContainer.style.display = 'none';
                if (textarea.value.trim() === '') {
                    textarea.setAttribute('required', 'required');
                }
                setTimeout(() => {
                    adjustScrollForFeedContent();
                }, 50);
                return;
            }
            previewName.textContent = `${file.name} (${formatBytes(file.size)})`;
            previewContainer.style.display = 'flex';
            textarea.removeAttribute('required');
        } else {
            previewContainer.style.display = 'none';
            // Only require textarea if empty
            if (textarea.value.trim() === '') {
                textarea.setAttribute('required', 'required');
            }
        }
        setTimeout(() => {
            adjustScrollForFeedContent();
        }, 50);
    });

    // Clear button event handler
    if (clearBtn) {
        clearBtn.addEventListener('click', () => {
            fileInput.value = '';
            fileInput.dispatchEvent(new Event('change'));
        });
    }

    // Also monitor textarea inputs to update required attribute when a file is NOT selected
    textarea.addEventListener('input', () => {
        if (!fileInput.files || fileInput.files.length === 0) {
            if (textarea.value.trim() === '') {
                textarea.setAttribute('required', 'required');
            } else {
                textarea.removeAttribute('required');
            }
        }
    });
}

/**
 * Handles file upload feedback, progress bars, loading skeletons, and submit button states for HTMX forms.
 */
export function initFileUploadUi() {
    // Show skeletons and progress indicators on request start
    document.body.addEventListener('htmx:beforeRequest', (evt) => {
        const target = evt.detail.target;
        if (target && (target.classList.contains('app-container') || target.tagName === 'BODY')) {
            const chatPanel = document.querySelector('.chat-panel');
            if (chatPanel) {
                chatPanel.classList.add('channel-loading');
            }
            const settingsPanel = document.querySelector('.settings-panel');
            if (settingsPanel) {
                settingsPanel.classList.add('settings-loading');
            }
        }

        const elt = evt.detail.elt;
        if (!elt) return;

        const isMainForm = elt.classList.contains('chat-message-form');
        if (isMainForm) {
            const fileInput = document.getElementById('file-upload');
            if (fileInput && fileInput.files && fileInput.files.length > 0) {
                const progressWrapper = document.getElementById('file-upload-progress');
                const progressBar = document.getElementById('file-upload-progress-bar');
                const progressPercent = document.getElementById('file-upload-progress-percent');
                if (progressWrapper && progressBar && progressPercent) {
                    progressWrapper.style.display = 'block';
                    progressBar.style.width = '0%';
                    progressPercent.textContent = '0%';
                }
                const submitBtn = elt.querySelector('button[type="submit"]');
                if (submitBtn) {
                    submitBtn.disabled = true;
                }
                const clearBtn = document.getElementById('btn-clear-file');
                if (clearBtn) {
                    clearBtn.style.display = 'none';
                }
            }
        }
    });

    // Handle XHR upload progress updates
    document.body.addEventListener('htmx:xhr:progress', (evt) => {
        const elt = evt.detail.elt;
        if (!elt) return;

        const isMainForm = elt.classList.contains('chat-message-form');
        if (isMainForm) {
            const fileInput = document.getElementById('file-upload');
            if (fileInput && fileInput.files && fileInput.files.length > 0) {
                const progressBar = document.getElementById('file-upload-progress-bar');
                const progressPercent = document.getElementById('file-upload-progress-percent');
                if (progressBar && progressPercent && (evt.detail.lengthComputable || evt.detail.total > 0)) {
                    const percent = Math.round((evt.detail.loaded / evt.detail.total) * 100);
                    progressBar.style.width = percent + '%';
                    progressPercent.textContent = percent + '%';
                }
            }
        }
    });

    // Cleanup and reset states after request finishes
    document.body.addEventListener('htmx:afterRequest', (evt) => {
        const chatPanel = document.querySelector('.chat-panel');
        if (chatPanel) {
            chatPanel.classList.remove('channel-loading');
        }
        const settingsPanel = document.querySelector('.settings-panel');
        if (settingsPanel) {
            settingsPanel.classList.remove('settings-loading');
        }

        const progressWrapper = document.getElementById('file-upload-progress');
        if (progressWrapper) {
            progressWrapper.style.display = 'none';
        }

        const elt = evt.detail.elt;
        if (elt) {
            const submitBtn = elt.querySelector('button[type="submit"]');
            if (submitBtn) {
                submitBtn.disabled = false;
            }

            if (evt.detail.successful) {
                const isMainForm = elt.classList.contains('chat-message-form');
                if (isMainForm) {
                    clearDraftForActiveChannel();

                    const fileInput = document.getElementById('file-upload');
                    if (fileInput) {
                        fileInput.value = '';
                        fileInput.dispatchEvent(new Event('change'));
                    }
                }
            }
        }
        const clearBtn = document.getElementById('btn-clear-file');
        if (clearBtn) {
            clearBtn.style.display = '';
        }
    });
}
