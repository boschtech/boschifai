/**
 * Pages need a signed-in user unless their route is marked `meta: { guest: true }` (login,
 * forgot/reset password). Signed-in users visiting a guest page go home instead. Routes marked
 * `meta: { public: true }` (the invitation link) are open to everyone. Routes marked
 * `meta: { requiresManage: true }` are for workspace admins and owners — the API refuses
 * members anyway; this just keeps them off a page they can't use.
 */
export function createAuthGuard(store) {
    return async (to, _from, next) => {
        if (!store.getters['auth/loaded']) {
            await store.dispatch('auth/fetchMe');
        }

        const signedIn = store.getters['auth/isAuthenticated'];

        if (to.meta.public) {
            return next();
        }

        if (to.meta.guest) {
            return signedIn ? next({ name: 'home' }) : next();
        }

        if (!signedIn) {
            return next({ name: 'login', query: to.fullPath === '/' ? {} : { redirect: to.fullPath } });
        }

        if (to.meta.requiresManage && !store.getters['auth/canManage']) {
            return next({ name: 'home' });
        }

        return next();
    };
}
