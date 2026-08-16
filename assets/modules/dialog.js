/**
 * Generic dialog, modal and chip UI helpers.
 */

export function initDialogHelpers() {
    document.addEventListener('click', (e) => {
        const fileBtn = e.target.closest('.btn-file-toggle');
        if (fileBtn) {
            const inputId = fileBtn.dataset.fileInput;
            if (inputId) {
                document.getElementById(inputId)?.click();
            }
        }

        const chipBtn = e.target.closest('.btn-remove-chip');
        if (chipBtn) {
            chipBtn.closest('.admin-chip')?.remove();
        }

        const dialogCloseBtn = e.target.closest('[data-dialog-close]');
        if (dialogCloseBtn) {
            dialogCloseBtn.closest('dialog')?.close();
        }

        const modalOpenBtn = e.target.closest('[data-open-modal]');
        if (modalOpenBtn) {
            const modalId = modalOpenBtn.dataset.openModal;
            if (modalId) {
                document.getElementById(modalId)?.showModal();
            }
        }
    });
}
