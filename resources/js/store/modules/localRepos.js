const state = {
    path: '',
    entries: [],
    loading: false,
    error: null,
};

const getters = {
    path: (state) => state.path,
    entries: (state) => state.entries,
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
    SET_BROWSE_RESULT(state, { path, entries }) {
        state.path = path;
        state.entries = entries;
    },
};

const actions = {
    async browse({ commit }, path = '') {
        commit('SET_LOADING', true);
        commit('SET_ERROR', null);
        try {
            const { data } = await window.axios.get('/api/local-repos/browse', { params: { path } });
            commit('SET_BROWSE_RESULT', data);
            return data;
        } catch (e) {
            commit('SET_ERROR', e.response?.data?.message || e.message);
            throw e;
        } finally {
            commit('SET_LOADING', false);
        }
    },

    async connectRepositories({ commit, dispatch }, repositories) {
        commit('SET_ERROR', null);
        try {
            await window.axios.post('/api/local-repos/connect', { repositories });
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
