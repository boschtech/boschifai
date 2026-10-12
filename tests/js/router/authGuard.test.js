import { describe, expect, it, vi } from 'vitest';
import { createAuthGuard } from '@/router/authGuard';

function storeWith({ loaded = true, signedIn = false } = {}) {
    const state = { loaded, signedIn };
    return {
        getters: {
            get 'auth/loaded'() { return state.loaded; },
            get 'auth/isAuthenticated'() { return state.signedIn; },
        },
        dispatch: vi.fn(async () => { state.loaded = true; }),
    };
}

async function navigate(store, to) {
    const next = vi.fn();
    await createAuthGuard(store)({ meta: {}, fullPath: '/', ...to }, {}, next);
    return next.mock.calls[0][0];
}

describe('auth guard', () => {
    it('asks the server who is signed in on the first navigation only', async () => {
        const store = storeWith({ loaded: false, signedIn: true });

        await navigate(store, { fullPath: '/runs' });
        await navigate(store, { fullPath: '/runs' });

        expect(store.dispatch).toHaveBeenCalledTimes(1);
        expect(store.dispatch).toHaveBeenCalledWith('auth/fetchMe');
    });

    it('lets a signed-in user through to app pages', async () => {
        expect(await navigate(storeWith({ signedIn: true }), { fullPath: '/runs' })).toBeUndefined();
    });

    it('sends a guest to login, remembering where they were going', async () => {
        expect(await navigate(storeWith(), { fullPath: '/runs/abc?tab=1' })).toEqual({ name: 'login', query: { redirect: '/runs/abc?tab=1' } });
        expect(await navigate(storeWith(), { fullPath: '/' })).toEqual({ name: 'login', query: {} });
    });

    it('lets a guest see guest pages, but sends a signed-in user home from them', async () => {
        const guestPage = { meta: { guest: true }, fullPath: '/login' };

        expect(await navigate(storeWith(), guestPage)).toBeUndefined();
        expect(await navigate(storeWith({ signedIn: true }), guestPage)).toEqual({ name: 'home' });
    });
});
