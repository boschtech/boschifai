/**
 * Pages need a signed-in user unless their route is marked `meta: { guest: true }` (login,
 * forgot/reset password). Signed-in users visiting a guest page go home instead.
 */
export function createAuthGuard(store) {
    return async (to, _from, next) => {
        if (!store.getters['auth/loaded']) {
            await store.dispatch('auth/fetchMe');
        }

        const signedIn = store.getters['auth/isAuthenticated'];

        if (to.meta.guest) {
            return signedIn ? next({ name: 'home' }) : next();
        }

        if (!signedIn) {
            return next({ name: 'login', query: to.fullPath === '/' ? {} : { redirect: to.fullPath } });
        }

        return next();
    };
}
