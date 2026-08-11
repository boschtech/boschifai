const state = {
    list: [],
    loading: false,
    error: null,
};

const getters = {
    list: (state) => state.list,
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
    SET_LIST(state, repoConfigs) {
        state.list = repoConfigs;
    },
    REMOVE_FROM_LIST(state, id) {
        state.list = state.list.filter((repo) => repo.id !== id);
    },
};

const actions = {
    async fetchRepoConfigs({ commit }) {
        commit('SET_LOADING', true);
        commit('SET_ERROR', null);
        try {
            const { data } = await window.axios.get('/api/repo-configs');
            commit('SET_LIST', data.data);
        } catch (e) {
            commit('SET_ERROR', e.response?.data?.message || e.message);
        } finally {
            commit('SET_LOADING', false);
        }
    },

    async deleteRepoConfig({ commit }, id) {
        commit('SET_ERROR', null);
        try {
            await window.axios.delete(`/api/repo-configs/${id}`);
            commit('REMOVE_FROM_LIST', id);
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
