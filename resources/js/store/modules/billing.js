// The workspace's prepaid credits and buying more (owners and admins) — see BillingController.

const state = {
    billing: null,
    loading: false,
    error: null,
};

const getters = {
    billing: (state) => state.billing,
    packs: (state) => state.billing?.packs ?? [],
    providers: (state) => state.billing?.providers ?? [],
    payments: (state) => state.billing?.payments ?? [],
    loading: (state) => state.loading,
    error: (state) => state.error,
};

const mutations = {
    SET_BILLING(state, billing) {
        state.billing = billing;
    },
    SET_LOADING(state, loading) {
        state.loading = loading;
    },
    SET_ERROR(state, error) {
        state.error = error;
    },
};

const actions = {
    async fetchBilling({ commit }) {
        commit('SET_LOADING', true);
        commit('SET_ERROR', null);
        try {
            const { data } = await window.axios.get('/api/billing');
            commit('SET_BILLING', data.data);
        } catch (e) {
            commit('SET_ERROR', e.response?.data?.message || e.message);
        } finally {
            commit('SET_LOADING', false);
        }
    },

    // Starts a checkout; resolves with the pending payment and the provider's checkout URL.
    async checkout(_ctx, { pack, provider, currency }) {
        const { data } = await window.axios.post('/api/billing/checkout', { pack, provider, currency });
        return data.data;
    },

    async fetchPayment(_ctx, id) {
        const { data } = await window.axios.get(`/api/billing/payments/${id}`);
        return data.data;
    },
};

export default { namespaced: true, state, getters, mutations, actions };
