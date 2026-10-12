// Signed-in user for the SPA. Sanctum session auth: every state-changing call to Fortify's
// endpoints (/login, /logout, /forgot-password, /reset-password, /user/password) first needs
// the XSRF-TOKEN cookie from /sanctum/csrf-cookie.

const state = {
    user: null,
    // True once we've asked the server who's signed in — the router guard waits for this.
    loaded: false,
};

const getters = {
    user: (state) => state.user,
    loaded: (state) => state.loaded,
    isAuthenticated: (state) => state.user !== null,
    currentWorkspace: (state) => state.user?.current_workspace ?? null,
    workspaces: (state) => state.user?.workspaces ?? [],
    // Admins and owners manage the workspace's repositories and GitHub connections.
    canManage: (state) => state.user?.current_workspace?.can_manage === true,
};

const mutations = {
    SET_USER(state, user) {
        state.user = user;
        state.loaded = true;
    },
};

function csrf() {
    return window.axios.get('/sanctum/csrf-cookie');
}

const actions = {
    async fetchMe({ commit }) {
        try {
            const { data } = await window.axios.get('/api/me', { skipAuthRedirect: true });
            commit('SET_USER', data.data);
        } catch (e) {
            commit('SET_USER', null);
        }
    },

    async login({ commit }, { email, password, remember = false }) {
        await csrf();
        await window.axios.post('/login', { email, password, remember });
        const { data } = await window.axios.get('/api/me', { skipAuthRedirect: true });
        commit('SET_USER', data.data);
    },

    async logout({ commit }) {
        try {
            await window.axios.post('/logout');
        } finally {
            commit('SET_USER', null);
        }
    },

    async forgotPassword(_context, email) {
        await csrf();
        const { data } = await window.axios.post('/forgot-password', { email });
        return data.message;
    },

    async resetPassword(_context, payload) {
        await csrf();
        await window.axios.post('/reset-password', payload);
    },

    async switchWorkspace({ commit }, workspaceId) {
        const { data } = await window.axios.post(`/api/workspaces/${workspaceId}/switch`);
        commit('SET_USER', data.data);
    },

    async changePassword(_context, payload) {
        await csrf();
        await window.axios.put('/user/password', payload);
    },
};

export default {
    namespaced: true,
    state,
    getters,
    mutations,
    actions,
};
