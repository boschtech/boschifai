import { describe, expect, it, vi } from 'vitest';
import { configureAuthHttp, installAuthInterceptors, safeRedirect } from '@/lib/authHttp';

function fakeAxios() {
    const axios = {
        defaults: {},
        get: vi.fn(() => Promise.resolve({ data: {} })),
        request: vi.fn(() => Promise.resolve({ data: 'retried' })),
        interceptors: { response: { use: vi.fn((ok, fail) => { axios.onFulfilled = ok; axios.onRejected = fail; }) } },
    };
    return axios;
}

function httpError(status, config = { url: '/api/runs', method: 'get' }) {
    return Object.assign(new Error(`HTTP ${status}`), { response: { status }, config });
}

describe('configureAuthHttp', () => {
    it('sends the session cookie and XSRF token with every request', () => {
        const axios = { defaults: {} };
        configureAuthHttp(axios);

        expect(axios.defaults.withCredentials).toBe(true);
        expect(axios.defaults.withXSRFToken).toBe(true);
    });
});

describe('installAuthInterceptors', () => {
    it('passes successful responses straight through', () => {
        const axios = fakeAxios();
        installAuthInterceptors(axios, { onUnauthenticated: vi.fn() });

        expect(axios.onFulfilled({ data: 1 })).toEqual({ data: 1 });
    });

    it('refreshes the CSRF token and retries a request once after a 419', async () => {
        const axios = fakeAxios();
        installAuthInterceptors(axios, { onUnauthenticated: vi.fn() });

        await expect(axios.onRejected(httpError(419))).resolves.toEqual({ data: 'retried' });

        expect(axios.get).toHaveBeenCalledWith('/sanctum/csrf-cookie');
        expect(axios.request).toHaveBeenCalledWith({ url: '/api/runs', method: 'get', csrfRetried: true });
    });

    it('does not retry a second time', async () => {
        const axios = fakeAxios();
        installAuthInterceptors(axios, { onUnauthenticated: vi.fn() });

        await expect(axios.onRejected(httpError(419, { url: '/x', csrfRetried: true }))).rejects.toThrow('HTTP 419');
        expect(axios.request).not.toHaveBeenCalled();
    });

    it('reports a lost session on 401, unless the request opted out', async () => {
        const onUnauthenticated = vi.fn();
        const axios = fakeAxios();
        installAuthInterceptors(axios, { onUnauthenticated });

        await expect(axios.onRejected(httpError(401))).rejects.toThrow();
        expect(onUnauthenticated).toHaveBeenCalledTimes(1);

        await expect(axios.onRejected(httpError(401, { url: '/api/me', skipAuthRedirect: true }))).rejects.toThrow();
        expect(onUnauthenticated).toHaveBeenCalledTimes(1);
    });

    it('leaves other errors (and network failures) to the caller', async () => {
        const onUnauthenticated = vi.fn();
        const axios = fakeAxios();
        installAuthInterceptors(axios, { onUnauthenticated });

        await expect(axios.onRejected(httpError(500))).rejects.toThrow('HTTP 500');
        await expect(axios.onRejected(new Error('Network Error'))).rejects.toThrow('Network Error');
        expect(onUnauthenticated).not.toHaveBeenCalled();
    });
});

describe('safeRedirect', () => {
    it.each([
        ['/runs/abc', '/runs/abc'],
        ['/settings/github?x=1', '/settings/github?x=1'],
        ['//evil.example/phish', '/'],
        ['https://evil.example', '/'],
        ['runs', '/'],
        [undefined, '/'],
        [['/a', '/b'], '/'],
    ])('turns %j into %j', (target, expected) => {
        expect(safeRedirect(target)).toBe(expected);
    });
});
