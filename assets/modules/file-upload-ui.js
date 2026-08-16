import { clearDraftForActiveChannel } from './draft.js';

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
