/**
 * Façade principale pour les modules d'interface utilisateur.
 * Ré-exporte l'ensemble des helpers et assure la liaison sur l'objet global window
 * pour la compatibilité avec les templates Twig et les handlers HTMX.
 */

export * from './code-highlighter.js';
export * from './file-upload-ui.js';
export * from './user-status.js';
export * from './modal.js';
export * from './sidebar.js';
export * from './global-search.js';
export * from './message-actions.js';

import {
    highlightAllCodeBlocks,
    initCodeBlockCopyButtons,
} from './code-highlighter.js';

import {
    formatBytes,
    initFileUpload,
    initFileUploadUi,
} from './file-upload-ui.js';

import {
    closeAllStatusDropdowns,
    updateElementStatus,
} from './user-status.js';

import {
    trapFocus,
    openModal,
    closeModal,
    showCustomConfirm,
    showCustomAlert,
    initConfirmModals,
    openExternalImageLightbox,
} from './modal.js';

import {
    updateChannelLastMessageDate,
    initChannelReordering,
    initUnreadFilter,
    initHideCompletedTasks,
    initSidebarToggles,
    initMobileSidebar,
    toggleMobileChannelDetails,
    initSubChannelsSidebar,
    toggleSubChannelsSidebar,
    initFilesSidebar,
    toggleFilesSidebar,
    filterFiles,
} from './sidebar.js';

import {
    openGlobalSearch,
    closeGlobalSearch,
    initGlobalSearch,
    clearSearchFilters,
} from './global-search.js';

import {
    scrollToMessage,
} from './message-actions.js';

// Bindings globaux pour les templates Twig (onclick=...) et les swaps HTMX
Object.assign(window, {
    highlightAllCodeBlocks,
    initCodeBlockCopyButtons,
    formatBytes,
    initFileUpload,
    initFileUploadUi,
    closeAllStatusDropdowns,
    updateElementStatus,
    trapFocus,
    openModal,
    closeModal,
    showCustomConfirm,
    showCustomAlert,
    initConfirmModals,
    openExternalImageLightbox,
    updateChannelLastMessageDate,
    initChannelReordering,
    initUnreadFilter,
    initHideCompletedTasks,
    initSidebarToggles,
    initMobileSidebar,
    toggleMobileChannelDetails,
    initSubChannelsSidebar,
    toggleSubChannelsSidebar,
    initFilesSidebar,
    toggleFilesSidebar,
    filterFiles,
    openGlobalSearch,
    closeGlobalSearch,
    initGlobalSearch,
    clearSearchFilters,
    scrollToMessage,
});
