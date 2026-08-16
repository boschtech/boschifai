const state = {
    connections: [],
    error: null,
};

const getters = {
    connections: (state) => state.connections,
    error: (state) => state.error,
};

const mutations = {
    SET_ERROR(state, error) {
        state.error = error;
    },
    SET_CONNECTIONS(state, connections) {
        state.connections = connections;
    },
};

const actions = {
    async fetchConnections({ commit }) {
        commit('SET_ERROR', null);
        try {
            const { data } = await window.axios.get('/api/github/connections');
            commit('SET_CONNECTIONS', data.data);
            return data.data;
        } catch (e) {
            commit('SET_ERROR', e.response?.data?.message || e.message);
        }
    },

    // Deliberately doesn't touch shared state — the page fetches every connected account's
    // repositories concurrently (see GithubSettingsPage's loadConnection), and a single shared
    // `repositories`/`loading` slot would have the last response to land silently clobber the
    // others. Each call's result is returned directly for the caller to key by connectionId.
    async fetchRepositories({ commit }, connectionId) {
        commit('SET_ERROR', null);
        try {
            const { data } = await window.axios.get(`/api/github/connections/${connectionId}/repositories`);
            return { repositories: data.data, excludedOrganizations: data.excluded_organizations || [] };
        } catch (e) {
            commit('SET_ERROR', e.response?.data?.message || e.message);
            throw e;
        }
    },

    async connectRepositories({ commit, dispatch }, { connectionId, repositories }) {
        commit('SET_ERROR', null);
        try {
            await window.axios.post(`/api/github/connections/${connectionId}/repositories`, { repositories });
            await dispatch('repoConfigs/fetchRepoConfigs', null, { root: true });
        } catch (e) {
            commit('SET_ERROR', e.response?.data?.message || e.message);
            throw e;
        }
    },

    // Deletes every repo Boschifai has connected under this organisation AND stops it being
    // offered at all — see GithubConnectionController::excludeOrganization's own doc comment
    // for why simply deleting the RepoConfig rows isn't enough on its own.
    async excludeOrganization({ commit, dispatch }, { connectionId, organization }) {
        commit('SET_ERROR', null);
        try {
            await window.axios.delete(`/api/github/connections/${connectionId}/organizations/${encodeURIComponent(organization)}`);
            await dispatch('repoConfigs/fetchRepoConfigs', null, { root: true });
        } catch (e) {
            commit('SET_ERROR', e.response?.data?.message || e.message);
            throw e;
        }
    },

    async restoreOrganization({ commit }, { connectionId, organization }) {
        commit('SET_ERROR', null);
        try {
            await window.axios.post(`/api/github/connections/${connectionId}/organizations/${encodeURIComponent(organization)}/restore`);
        } catch (e) {
            commit('SET_ERROR', e.response?.data?.message || e.message);
            throw e;
        }
    },
};

export default {
    namespaced: true,
    state,
    getters,
    mutations,
    actions,
};
