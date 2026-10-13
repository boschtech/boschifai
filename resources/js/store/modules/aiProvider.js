// Settings → AI provider: the platform's key and credits, or the workspace's own Anthropic key or
// Amazon Bedrock.
// The API never returns the key itself — only a hint like "…AbCd".

const state = {
    credential: null,
    loading: false,
    error: null,
};

const getters = {
    credential: (state) => state.credential,
    usesOwnKey: (state) => Boolean(state.credential && state.credential.provider !== 'platform'),
    loading: (state) => state.loading,
    error: (state) => state.error,
};

const mutations = {
    SET_CREDENTIAL(state, credential) {
        state.credential = credential;
    },
    SET_LOADING(state, loading) {
        state.loading = loading;
    },
    SET_ERROR(state, error) {
        state.error = error;
    },
};

const actions = {
    async fetchCredential({ commit }) {
        commit('SET_LOADING', true);
        commit('SET_ERROR', null);
        try {
            const { data } = await window.axios.get('/api/workspace/ai-credentials');
            commit('SET_CREDENTIAL', data.data);
        } catch (e) {
            commit('SET_ERROR', e.response?.data?.message || e.message);
        } finally {
            commit('SET_LOADING', false);
        }
    },

    // Each of these resolves with the updated credential; errors are left to the page to show.
    async saveKey({ commit }, apiKey) {
        const { data } = await window.axios.put('/api/workspace/ai-credentials', { api_key: apiKey });
        commit('SET_CREDENTIAL', data.data);
    },

    // { role_arn, region, model } — checked end to end with AWS before it's saved.
    async saveBedrock({ commit }, settings) {
        const { data } = await window.axios.put('/api/workspace/ai-credentials', { provider: 'bedrock', ...settings });
        commit('SET_CREDENTIAL', data.data);
    },

    async checkKey({ commit }) {
        const { data } = await window.axios.post('/api/workspace/ai-credentials/check');
        commit('SET_CREDENTIAL', data.data);
    },

    async removeKey({ commit }) {
        const { data } = await window.axios.delete('/api/workspace/ai-credentials');
        commit('SET_CREDENTIAL', data.data);
    },
};

export default { namespaced: true, state, getters, mutations, actions };
