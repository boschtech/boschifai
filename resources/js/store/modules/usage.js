const state = {
    tokensThisMonth: null,
    details: null,
    error: null,
};

const getters = {
    tokensThisMonth: (state) => state.tokensThisMonth,
    details: (state) => state.details,
    error: (state) => state.error,
};

const mutations = {
    SET_ERROR(state, error) {
        state.error = error;
    },
    SET_TOKENS_THIS_MONTH(state, usage) {
        state.tokensThisMonth = usage;
    },
    SET_DETAILS(state, details) {
        state.details = details;
    },
};

const actions = {
    async fetchTokensThisMonth({ commit }) {
        commit('SET_ERROR', null);
        try {
            const { data } = await window.axios.get('/api/usage/tokens-this-month');
            commit('SET_TOKENS_THIS_MONTH', data);
            return data;
        } catch (e) {
            commit('SET_ERROR', e.response?.data?.message || e.message);
        }
    },

    async fetchDetails({ commit }) {
        commit('SET_ERROR', null);
        try {
            const { data } = await window.axios.get('/api/usage/tokens-this-month/details');
            commit('SET_DETAILS', data);
            return data;
        } catch (e) {
            commit('SET_ERROR', e.response?.data?.message || e.message);
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
