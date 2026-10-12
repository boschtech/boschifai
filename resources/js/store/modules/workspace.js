// The current workspace's settings: its name, members and pending invitations (admins/owners).

const state = {
    workspace: null,
    loading: false,
    error: null,
};

const getters = {
    workspace: (state) => state.workspace,
    members: (state) => state.workspace?.members ?? [],
    invitations: (state) => state.workspace?.invitations ?? [],
    loading: (state) => state.loading,
    error: (state) => state.error,
};

const mutations = {
    SET_WORKSPACE(state, workspace) {
        state.workspace = workspace;
    },
    SET_LOADING(state, loading) {
        state.loading = loading;
    },
    SET_ERROR(state, error) {
        state.error = error;
    },
};

const actions = {
    async fetchWorkspace({ commit }) {
        commit('SET_LOADING', true);
        commit('SET_ERROR', null);
        try {
            const { data } = await window.axios.get('/api/workspace');
            commit('SET_WORKSPACE', data.data);
        } catch (e) {
            commit('SET_ERROR', e.response?.data?.message || e.message);
        } finally {
            commit('SET_LOADING', false);
        }
    },

    async rename({ commit }, name) {
        const { data } = await window.axios.patch('/api/workspace', { name });
        commit('SET_WORKSPACE', data.data);
    },

    async invite({ dispatch }, { email, role }) {
        await window.axios.post('/api/workspace/invitations', { email, role });
        await dispatch('fetchWorkspace');
    },

    async revokeInvitation({ dispatch }, invitationId) {
        await window.axios.delete(`/api/workspace/invitations/${invitationId}`);
        await dispatch('fetchWorkspace');
    },

    async changeRole({ dispatch }, { memberId, role }) {
        await window.axios.patch(`/api/workspace/members/${memberId}`, { role });
        await dispatch('fetchWorkspace');
    },

    async removeMember({ dispatch }, memberId) {
        await window.axios.delete(`/api/workspace/members/${memberId}`);
        await dispatch('fetchWorkspace');
    },
};

export default {
    namespaced: true,
    state,
    getters,
    mutations,
    actions,
};
