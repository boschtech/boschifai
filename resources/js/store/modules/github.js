const state = {
    connections: [],
    repositories: [],
    loading: false,
    error: null,
};

const getters = {
    connections: (state) => state.connections,
    repositories: (state) => state.repositories,
    loading: (state) => state.loading,
    error: (state) => state.error,
};

const mutations = {
    SET_LOADING(state, value) {
        state.loading = value;
    },
    SET_ERROR(state, error) {
        state.error = error;
    },
    SET_CONNECTIONS(state, connections) {
        state.connections = connections;
    },
    SET_REPOSITORIES(state, repositories) {
        state.repositories = repositories;
    },
};

const actions = {
    async fetchAuthorizeUrl({ commit }) {
        commit('SET_ERROR', null);
        try {
            const { data } = await window.axios.get('/api/github/authorize-url');
            return data.data.url;
        } catch (e) {
            commit('SET_ERROR', e.response?.data?.message || e.message);
            throw e;
        }
    },

    async fetchConnections({ commit }) {
        commit('SET_LOADING', true);
        commit('SET_ERROR', null);
        try {
            const { data } = await window.axios.get('/api/github/connections');
            commit('SET_CONNECTIONS', data.data);
            return data.data;
        } catch (e) {
            commit('SET_ERROR', e.response?.data?.message || e.message);
        } finally {
            commit('SET_LOADING', false);
        }
    },

    async fetchRepositories({ commit }, connectionId) {
        commit('SET_LOADING', true);
        commit('SET_ERROR', null);
        try {
            const { data } = await window.axios.get(`/api/github/connections/${connectionId}/repositories`);
            commit('SET_REPOSITORIES', data.data);
            return data.data;
        } catch (e) {
            commit('SET_ERROR', e.response?.data?.message || e.message);
        } finally {
            commit('SET_LOADING', false);
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
};

export default {
    namespaced: true,
    state,
    getters,
    mutations,
    actions,
};
