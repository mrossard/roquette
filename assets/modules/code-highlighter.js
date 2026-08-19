import hljs from 'highlight.js';

/**
 * Applique la coloration syntaxique dynamique via highlight.js sur tous les blocs de code.
 *
 * @param {HTMLElement|Document} container
 */
export function highlightAllCodeBlocks(container = document) {
    if (!container) return;

    const blocks = [];
    if (container.matches && container.matches('pre code:not([data-highlighted="yes"])')) {
        blocks.push(container);
    }
    if (container.querySelectorAll) {
        blocks.push(...container.querySelectorAll('pre code:not([data-highlighted="yes"])'));
    }

    if (blocks.length === 0) return;

    blocks.forEach(block => {
        if (block.dataset.highlighted === 'yes') return;
        hljs.highlightElement(block);
    });
}

const COPY_BTN_SVG = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>';
const COPY_DONE_SVG = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>';

/**
 * Initialise les boutons de copie pour chaque bloc de code Markdown / prévisualisation texte.
 *
 * @param {HTMLElement|Document} container
 */
export function initCodeBlockCopyButtons(container = document) {
    if (!container) return;

    const pres = [];
    const selector = 'pre.message-code-block, pre:has(.text-preview-code)';
    if (container.matches && container.matches(selector)) {
        pres.push(container);
    }
    if (container.querySelectorAll) {
        pres.push(...container.querySelectorAll(selector));
    }

    pres.forEach(pre => {
        if (pre.closest('.code-block-wrapper')) return;

        const wrapper = document.createElement('div');
        wrapper.className = 'code-block-wrapper';
        pre.parentNode.insertBefore(wrapper, pre);
        wrapper.appendChild(pre);

        const btn = document.createElement('button');
        btn.className = 'code-copy-btn';
        btn.type = 'button';
        btn.innerHTML = COPY_BTN_SVG;
        btn.title = 'Copier';
        btn.addEventListener('click', () => {
            const code = pre.querySelector('code');
            const text = code ? code.textContent : pre.textContent;
            navigator.clipboard.writeText(text).then(() => {
                btn.innerHTML = COPY_DONE_SVG;
                btn.classList.add('copied');
                setTimeout(() => {
                    btn.innerHTML = COPY_BTN_SVG;
                    btn.classList.remove('copied');
                }, 2000);
            });
        });
        wrapper.appendChild(btn);
    });
}
