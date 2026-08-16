/**
 * Poll options DOM helpers used across message composers and edit forms.
 */

export function getPollOptionCount(containerId) {
    const container = document.getElementById(containerId);
    return container ? container.querySelectorAll('input').length : 0;
}

export function removePollOption(btn) {
    const parent = btn.closest('.poll-options-list');
    btn.closest('div')?.remove();
    if (parent) {
        parent.querySelectorAll('input').forEach((inp, i) => {
            inp.placeholder = 'Option ' + (i + 1);
        });
    }
}

// Expose on window for Twig hx-vals and inline onclick attributes
window.getPollOptionCount = getPollOptionCount;
window.removePollOption = removePollOption;
