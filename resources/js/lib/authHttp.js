/**
 * Session (cookie) authentication for the SPA against Laravel Sanctum: send cookies and the
 * XSRF-TOKEN header with every request, recover once from an expired CSRF token, and report a
 * lost session so the app can send the user to the login page.
 */
export function configureAuthHttp(axios) {
    axios.defaults.withCredentials = true;
    axios.defaults.withXSRFToken = true;
}

/**
 * @param {object} axios  the axios instance (window.axios)
 * @param {{ onUnauthenticated: Function }} handlers
 */
export function installAuthInterceptors(axios, { onUnauthenticated }) {
    axios.interceptors.response.use(
        (response) => response,
        async (error) => {
            const status = error.response?.status;
            const config = error.config || {};

            // 419 = CSRF token mismatch (e.g. the tab was left open past the session lifetime).
            // Fetch a fresh token and retry the original request exactly once.
            if (status === 419 && !config.csrfRetried) {
                await axios.get('/sanctum/csrf-cookie');
                return axios.request({ ...config, csrfRetried: true });
            }

            // Requests that check "am I signed in?" opt out, so a guest isn't redirected in a loop.
            if (status === 401 && !config.skipAuthRedirect) {
                onUnauthenticated();
            }

            return Promise.reject(error);
        },
    );
}

/**
 * Where to go after signing in: the page the user originally asked for, but only paths inside
 * this app (never `//evil.example` or an absolute URL — that would be an open redirect).
 */
export function safeRedirect(target, fallback = '/') {
    return typeof target === 'string' && target.startsWith('/') && !target.startsWith('//') ? target : fallback;
}
