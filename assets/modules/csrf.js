export async function getFreshCsrfToken() {
    try {
        const response = await fetch('/csrf-token', {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        if (response.status === 401 || response.status === 403) {
            window.location.reload();
            return null;
        }
        const data = await response.json();
        if (data && data.token) {
            const csrfMeta = document.querySelector('meta[name="csrf-token"]');
            if (csrfMeta) {
                csrfMeta.content = data.token;
            }
            return data.token;
        }
    } catch (e) {
        console.error('Failed to get fresh CSRF token:', e);
    }
    return null;
}

export async function fetchWithCsrf(url, options = {}) {
    options.headers = options.headers || {};
    options.headers['X-Requested-With'] = 'XMLHttpRequest';
    const method = (options.method || 'GET').toUpperCase();
    if (method !== 'GET' && method !== 'HEAD') {
        const csrfMeta = document.querySelector('meta[name="csrf-token"]');
        if (csrfMeta) {
            options.headers['X-CSRF-Token'] = csrfMeta.content;
        }
    }
    try {
        let response = await fetch(url, options);
        if (response.status === 401 || response.status === 403) {
            const freshToken = await getFreshCsrfToken();
            if (freshToken) {
                options.headers['X-CSRF-Token'] = freshToken;
                response = await fetch(url, options);
            }
        }
        return response;
    } catch (e) {
        console.error('fetchWithCsrf error:', e);
        throw e;
    }
}
