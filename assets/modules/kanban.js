import Sortable from 'sortablejs';

let cardSortables = [];
let columnSortable = null;

function initKanbanBoard(board) {
    if (!board) return;

    // Clean up previous Sortable instances
    cardSortables.forEach(s => s.destroy());
    cardSortables = [];
    if (columnSortable) {
        columnSortable.destroy();
        columnSortable = null;
    }

    const columnBodies = board.querySelectorAll('.kanban-column-body[data-drop-zone]');
    columnBodies.forEach(body => {
        const sortable = new Sortable(body, {
            group: 'kanban-cards',
            animation: 150,
            ghostClass: 'sortable-ghost',
            dragClass: 'sortable-drag',
            delay: 100,
            delayOnTouchOnly: true,
            onEnd: (evt) => {
                const messageId = evt.item.dataset.messageId;
                const toColumnId = evt.to.dataset.columnId;
                const fromColumnId = evt.from.dataset.columnId;

                if (fromColumnId === toColumnId && evt.newIndex === evt.oldIndex) {
                    return;
                }

                // Update counter badges immediately for UX
                updateColumnCounter(evt.from);
                updateColumnCounter(evt.to);

                // Send HTMX request to move the message
                const formData = new FormData();
                formData.append('columnId', toColumnId === 'null' ? '' : toColumnId);

                const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
                const headers = {};
                if (csrfToken) headers['X-CSRF-Token'] = csrfToken;

                fetch(`/messages/${messageId}/kanban-column`, {
                    method: 'POST',
                    body: formData,
                    headers,
                }).catch(err => {
                    console.error('Kanban move failed', err);
                    // Optionally revert the DOM move here
                });
            },
        });
        cardSortables.push(sortable);
    });

    // Column reordering (admin only)
    const hasReorderColumns = board.querySelector('.kanban-column[data-column-id]');
    if (hasReorderColumns) {
        columnSortable = new Sortable(board, {
            animation: 150,
            handle: '.kanban-column-header',
            draggable: '.kanban-column[data-column-id]',
            ghostClass: 'sortable-ghost',
            onEnd: (evt) => {
                const columns = board.querySelectorAll('.kanban-column[data-column-id]');
                const columnIds = Array.from(columns).map(c => parseInt(c.dataset.columnId, 10)).filter(Boolean);
                if (columnIds.length === 0) return;

                const formData = new FormData();
                columnIds.forEach((id, idx) => {
                    formData.append(`columnIds[${idx}]`, id);
                });

                const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
                const headers = {};
                if (csrfToken) headers['X-CSRF-Token'] = csrfToken;

                fetch('/kanban/columns/reorder', {
                    method: 'POST',
                    body: formData,
                    headers,
                }).catch(err => {
                    console.error('Kanban column reorder failed', err);
                });
            },
        });
    }
}

function updateColumnCounter(columnBody) {
    const column = columnBody.closest('.kanban-column');
    if (!column) return;
    const countEl = column.querySelector('.kanban-column-count');
    if (!countEl) return;
    const cards = columnBody.querySelectorAll('.kanban-card:not(.sortable-ghost)');
    countEl.textContent = cards.length;
}

window.showKanbanAddColumnModal = function () {
    const col = document.querySelector('.kanban-column-add');
    const btn = document.getElementById('btn-kanban-add-column');
    const form = document.getElementById('kanban-add-column-form');
    if (col) {
        col.style.minWidth = '280px';
        col.style.maxWidth = '280px';
        col.style.background = 'var(--panel-bg, rgba(255,255,255,0.6))';
        col.style.border = '1px solid var(--border-color, rgba(0,0,0,0.05))';
        col.style.borderRadius = '0.75rem';
        col.style.alignItems = 'stretch';
        col.style.justifyContent = 'flex-start';
    }
    if (btn) btn.style.display = 'none';
    if (form) form.style.display = 'block';
};

window.hideKanbanAddColumnModal = function () {
    const col = document.querySelector('.kanban-column-add');
    const btn = document.getElementById('btn-kanban-add-column');
    const form = document.getElementById('kanban-add-column-form');
    if (col) {
        col.style.minWidth = '';
        col.style.maxWidth = '';
        col.style.background = '';
        col.style.border = '';
        col.style.borderRadius = '';
        col.style.alignItems = '';
        col.style.justifyContent = '';
    }
    if (btn) btn.style.display = 'block';
    if (form) form.style.display = 'none';
};

window.enableKanbanColumnRename = function (columnId) {
    const header = document.getElementById('kanban-column-header-' + columnId);
    const renameForm = document.getElementById('kanban-column-rename-form-' + columnId);
    if (header) header.style.display = 'none';
    if (renameForm) renameForm.style.display = 'flex';
    const input = renameForm?.querySelector('input');
    if (input) {
        input.focus();
        input.select();
    }
};

window.cancelKanbanColumnRename = function (columnId) {
    const header = document.getElementById('kanban-column-header-' + columnId);
    const renameForm = document.getElementById('kanban-column-rename-form-' + columnId);
    if (header) header.style.display = '';
    if (renameForm) renameForm.style.display = 'none';
};

function returnMenuToCard(menu) {
    const messageId = menu.id.replace('kanban-card-menu-', '');
    const card = document.getElementById('kanban-card-' + messageId);
    if (card) {
        card.appendChild(menu);
    }
    menu.style.display = 'none';
    menu.style.top = '';
    menu.style.left = '';
}

window.toggleKanbanCardMenu = function (messageId) {
    const menu = document.getElementById('kanban-card-menu-' + messageId);
    if (!menu) return;
    const isOpen = menu.style.display === 'block';

    // Close all other open menus
    document.querySelectorAll('.kanban-card-menu').forEach(m => {
        if (m !== menu && m.style.display === 'block') {
            returnMenuToCard(m);
        }
    });

    if (isOpen) {
        returnMenuToCard(menu);
        return;
    }

    // Move to body to escape any scroll/overflow/transform contexts
    document.body.appendChild(menu);

    const btn = document.querySelector(`#kanban-card-${messageId} .btn-kanban-card-menu`);
    if (btn) {
        const btnRect = btn.getBoundingClientRect();
        const menuWidth = 180;

        let top = btnRect.bottom + 4;
        let left = btnRect.right - menuWidth;

        if (left < 8) left = 8;
        if (left + menuWidth > window.innerWidth - 8) {
            left = window.innerWidth - menuWidth - 8;
        }

        if (top + 200 > window.innerHeight) {
            top = Math.max(8, btnRect.top - 200 - 4);
        }

        menu.style.top = `${top}px`;
        menu.style.left = `${left}px`;
    }

    menu.style.display = 'block';
};

// ── Auto-init on DOM ready and after HTMX swaps ──────────────────────────────

function tryInitKanban() {
    const board = document.getElementById('kanban-board');
    if (board) {
        initKanbanBoard(board);
    }
}

window.initKanbanBoard = tryInitKanban;

document.addEventListener('DOMContentLoaded', tryInitKanban);

document.body.addEventListener('htmx:afterSettle', (evt) => {
    const target = evt.detail.target;
    if (target && (target.id === 'kanban-board' || target.querySelector('#kanban-board'))) {
        tryInitKanban();
    }

    // Remove orphan menus in body that have been re-rendered inside the board
    document.querySelectorAll('body > .kanban-card-menu').forEach(bodyMenu => {
        const messageId = bodyMenu.id.replace('kanban-card-menu-', '');
        const card = document.getElementById('kanban-card-' + messageId);
        if (card && card.querySelector('#' + bodyMenu.id)) {
            bodyMenu.remove();
        }
    });
});

// Refresh board after sending a new message in kanban view
document.body.addEventListener('htmx:afterRequest', (evt) => {
    const board = document.getElementById('kanban-board');
    if (!board) return;
    const isMainForm = evt.detail.elt?.classList.contains('chat-message-form');
    if (isMainForm && evt.detail.successful) {
        const statusBadge = document.getElementById('mercure-status');
        const channelSlug = statusBadge ? statusBadge.getAttribute('data-active-channel-slug') : null;
        if (channelSlug) {
            htmx.ajax('GET', `/channels/${channelSlug}/kanban`, {
                target: '#live-feed',
                swap: 'innerHTML transition:false',
            });
        }
    }
});

// Close card menus when clicking outside and return them to their cards
document.addEventListener('click', (e) => {
    if (!e.target.closest('.kanban-card-menu') && !e.target.closest('.btn-kanban-card-menu')) {
        document.querySelectorAll('.kanban-card-menu').forEach(m => {
            if (m.style.display === 'block') returnMenuToCard(m);
        });
    }
});




