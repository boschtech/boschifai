const state = {
    list: [],
    current: null,
    loading: false,
    error: null,
};

const getters = {
    list: (state) => state.list,
    current: (state) => state.current,
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
    SET_LIST(state, runs) {
        state.list = runs;
    },
    SET_CURRENT(state, run) {
        state.current = run;
    },
    REMOVE_FROM_LIST(state, id) {
        state.list = state.list.filter((run) => run.id !== id);
    },
};

const actions = {
    async fetchRuns({ commit }, { archived = false } = {}) {
        commit('SET_LOADING', true);
        commit('SET_ERROR', null);
        try {
            const { data } = await window.axios.get('/api/runs', { params: archived ? { archived: 1 } : {} });
            commit('SET_LIST', data.data);
        } catch (e) {
            commit('SET_ERROR', e.response?.data?.message || e.message);
        } finally {
            commit('SET_LOADING', false);
        }
    },

    async fetchRun({ commit }, id) {
        commit('SET_LOADING', true);
        commit('SET_ERROR', null);
        try {
            const { data } = await window.axios.get(`/api/runs/${id}`);
            commit('SET_CURRENT', data.data);
            return data.data;
        } catch (e) {
            commit('SET_ERROR', e.response?.data?.message || e.message);
        } finally {
            commit('SET_LOADING', false);
        }
    },

    async createRun({ commit }, payload) {
        commit('SET_ERROR', null);
        try {
            const { data } = await window.axios.post('/api/runs', payload);
            return data.data;
        } catch (e) {
            commit('SET_ERROR', e.response?.data?.message || e.message);
            throw e;
        }
    },

    async decideGapAnalysis({ dispatch }, { id, decision, comment }) {
        await window.axios.post(`/api/runs/${id}/approvals/gap-analysis`, { decision, comment });
        return dispatch('fetchRun', id);
    },

    async decidePush({ dispatch }, { id, decision, comment }) {
        await window.axios.post(`/api/runs/${id}/approvals/push`, { decision, comment });
        return dispatch('fetchRun', id);
    },

    async retryStep({ dispatch }, { id, stepId }) {
        await window.axios.post(`/api/runs/${id}/steps/${stepId}/retry`);
        return dispatch('fetchRun', id);
    },

    async deleteRun({ commit }, id) {
        await window.axios.delete(`/api/runs/${id}`);
        commit('REMOVE_FROM_LIST', id);
    },

    async cancelRun({ dispatch }, id) {
        await window.axios.post(`/api/runs/${id}/cancel`);
        return dispatch('fetchRun', id);
    },

    async rerunLocalExecution({ dispatch }, id) {
        await window.axios.post(`/api/runs/${id}/local-execution/rerun`);
        return dispatch('fetchRun', id);
    },

    async fixFailingTests({ dispatch }, id) {
        await window.axios.post(`/api/runs/${id}/local-execution/fix-failing-tests`);
        return dispatch('fetchRun', id);
    },

    async archiveRun({ commit }, id) {
        commit('SET_ERROR', null);
        try {
            const { data } = await window.axios.post(`/api/runs/${id}/archive`);
            commit('SET_CURRENT', data.data);
            return data.data;
        } catch (e) {
            commit('SET_ERROR', e.response?.data?.message || e.message);
            throw e;
        }
    },

    async unarchiveRun({ commit }, id) {
        commit('SET_ERROR', null);
        try {
            const { data } = await window.axios.post(`/api/runs/${id}/unarchive`);
            commit('SET_CURRENT', data.data);
            return data.data;
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
