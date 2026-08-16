import { Idiomorph } from 'idiomorph';

/**
 * Registers Idiomorph as a custom swap extension for HTMX.
 */
export function initMorphExtension(htmx) {
    if (!htmx || !Idiomorph) return;

    window.Idiomorph = Idiomorph;

    function createMorphConfig(swapStyle) {
        if (swapStyle === 'morph' || swapStyle === 'morph:outerHTML') {
            return { morphStyle: 'outerHTML' };
        }
        if (swapStyle === 'morph:innerHTML') {
            return { morphStyle: 'innerHTML' };
        }
        if (swapStyle.startsWith('morph:')) {
            const params = swapStyle.slice(6);
            const config = {};
            for (const part of params.split(';')) {
                const eqIdx = part.indexOf('=');
                if (eqIdx === -1) continue;
                const key = part.slice(0, eqIdx).trim();
                let value = part.slice(eqIdx + 1).trim();
                if (value === 'true') value = true;
                else if (value === 'false') value = false;
                else if (value === 'null') value = null;
                config[key] = value;
            }
            return config;
        }
        return undefined;
    }

    htmx.defineExtension('morph', {
        isInlineSwap(swapStyle) {
            const config = createMorphConfig(swapStyle);
            return config?.morphStyle === 'outerHTML' || config?.morphStyle == null;
        },
        handleSwap(swapStyle, target, fragment) {
            const config = createMorphConfig(swapStyle);
            if (config) {
                return Idiomorph.morph(target, fragment.children, config);
            }
            return undefined;
        },
    });
}
