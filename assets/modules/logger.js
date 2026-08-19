/**
 * Logger helper qui n'affiche les messages de log/debug/warn qu'en environnement hors production.
 * L'environnement est détecté dynamiquement via la balise <meta name="app-env">.
 */

export function isProd() {
    const env = document.querySelector('meta[name="app-env"]')?.getAttribute('content');
    return env === 'prod' || env === 'production';
}

export const logger = {
    log: (...args) => {
        if (!isProd()) {
            window.console.log(...args);
        }
    },
    warn: (...args) => {
        if (!isProd()) {
            window.console.warn(...args);
        }
    },
    info: (...args) => {
        if (!isProd()) {
            window.console.info(...args);
        }
    },
    debug: (...args) => {
        if (!isProd()) {
            window.console.debug(...args);
        }
    },
    error: (...args) => {
        window.console.error(...args);
    },
};
