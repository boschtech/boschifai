import { describe, expect, it, vi } from 'vitest';
import auth from '@/store/modules/auth';
import { apiError, mockApi, signedInUser } from '../helpers';

const USER = { id: 1, name: 'Ops Admin', email: 'ops@example.com' };

function context() {
    return { commit: vi.fn() };
}

describe('auth store', () => {
    it('starts signed out and not yet loaded; SET_USER marks it loaded', () => {
        const state = JSON.parse(JSON.stringify(auth.state));
        expect(auth.getters.isAuthenticated(state)).toBe(false);
        expect(auth.getters.loaded(state)).toBe(false);

        auth.mutations.SET_USER(state, USER);
        expect(auth.getters.user(state)).toEqual(USER);
        expect(auth.getters.isAuthenticated(state)).toBe(true);
        expect(auth.getters.loaded(state)).toBe(true);
    });

    it('fetchMe loads the signed-in user without triggering the login redirect', async () => {
        mockApi({ 'GET /api/me': { data: USER } });
        const ctx = context();

        await auth.actions.fetchMe(ctx);

        expect(window.axios.get).toHaveBeenCalledWith('/api/me', { skipAuthRedirect: true });
        expect(ctx.commit).toHaveBeenCalledWith('SET_USER', USER);
    });

    it('fetchMe records a guest as signed out', async () => {
        mockApi({ 'GET /api/me': () => { throw Object.assign(new Error('401'), { response: { status: 401 } }); } });
        const ctx = context();

        await auth.actions.fetchMe(ctx);

        expect(ctx.commit).toHaveBeenCalledWith('SET_USER', null);
    });

    it('login fetches a CSRF cookie, signs in, then loads the user', async () => {
        const posted = vi.fn(() => ({}));
        mockApi({ 'GET /sanctum/csrf-cookie': {}, 'POST /login': posted, 'GET /api/me': { data: USER } });
        const ctx = context();

        await auth.actions.login(ctx, { email: 'ops@example.com', password: 'secret' });

        const calls = window.axios.get.mock.calls.map(([url]) => url);
        expect(calls[0]).toBe('/sanctum/csrf-cookie');
        expect(posted).toHaveBeenCalledWith({ email: 'ops@example.com', password: 'secret', remember: false });
        expect(ctx.commit).toHaveBeenCalledWith('SET_USER', USER);
    });

    it('login rethrows a rejected sign-in and stays signed out', async () => {
        mockApi({ 'GET /sanctum/csrf-cookie': {}, 'POST /login': () => { throw apiError('These credentials do not match our records.'); } });
        const ctx = context();

        await expect(auth.actions.login(ctx, { email: 'x', password: 'y' })).rejects.toThrow();
        expect(ctx.commit).not.toHaveBeenCalled();
    });

    it('logout clears the user even if the server call fails', async () => {
        mockApi({ 'POST /logout': () => { throw new Error('Network Error'); } });
        const ctx = context();

        await expect(auth.actions.logout(ctx)).rejects.toThrow();
        expect(ctx.commit).toHaveBeenCalledWith('SET_USER', null);
    });

    it('forgotPassword returns the server’s neutral message', async () => {
        const posted = vi.fn(() => ({ message: 'If an account exists…' }));
        mockApi({ 'GET /sanctum/csrf-cookie': {}, 'POST /forgot-password': posted });

        await expect(auth.actions.forgotPassword(context(), 'ops@example.com')).resolves.toBe('If an account exists…');
        expect(posted).toHaveBeenCalledWith({ email: 'ops@example.com' });
    });

    it('resetPassword and changePassword send their payloads after fetching a CSRF cookie', async () => {
        const reset = vi.fn(() => ({}));
        mockApi({ 'GET /sanctum/csrf-cookie': {}, 'POST /reset-password': reset });
        await auth.actions.resetPassword(context(), { token: 't', email: 'e' });
        expect(reset).toHaveBeenCalledWith({ token: 't', email: 'e' });

        window.axios.put = vi.fn(() => Promise.resolve({ data: {} }));
        await auth.actions.changePassword(context(), { current_password: 'a' });
        expect(window.axios.put).toHaveBeenCalledWith('/user/password', { current_password: 'a' });
    });

    it('exposes the current workspace, the user’s workspaces and whether they can manage it', () => {
        const state = JSON.parse(JSON.stringify(auth.state));
        expect(auth.getters.canManage(state)).toBe(false);
        expect(auth.getters.workspaces(state)).toEqual([]);
        expect(auth.getters.currentWorkspace(state)).toBeNull();

        auth.mutations.SET_USER(state, signedInUser({ canManage: true }));
        expect(auth.getters.currentWorkspace(state).name).toBe('Acme');
        expect(auth.getters.workspaces(state)).toHaveLength(2);
        expect(auth.getters.canManage(state)).toBe(true);

        auth.mutations.SET_USER(state, signedInUser({ canManage: false }));
        expect(auth.getters.canManage(state)).toBe(false);
    });

    it('switchWorkspace stores the user as the server returns them after the switch', async () => {
        const after = signedInUser();
        mockApi({ 'POST /api/workspaces/20/switch': { data: after } });
        const ctx = context();

        await auth.actions.switchWorkspace(ctx, 20);

        expect(ctx.commit).toHaveBeenCalledWith('SET_USER', after);
    });
});
