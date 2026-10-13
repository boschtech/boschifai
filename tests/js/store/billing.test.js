import { describe, expect, it, vi } from 'vitest';
import billing from '@/store/modules/billing';
import { apiError, mockApi } from '../helpers';

const BILLING = {
    available_usd: '12.50', held_usd: '0.00',
    packs: [{ key: 'starter', credits_usd: '10.00', price_usd: '10.00' }],
    providers: [{ key: 'stripe', currency: 'USD' }],
    payments: [{ id: 1, status: 'succeeded' }],
};

function context() {
    return { commit: vi.fn() };
}

describe('billing store', () => {
    it('exposes packs, providers and payments, empty before loading', () => {
        const state = { ...billing.state, billing: BILLING };

        expect(billing.getters.packs(state)).toEqual(BILLING.packs);
        expect(billing.getters.providers(state)).toEqual(BILLING.providers);
        expect(billing.getters.payments(state)).toEqual(BILLING.payments);
        for (const getter of ['packs', 'providers', 'payments']) {
            expect(billing.getters[getter]({ billing: null })).toEqual([]);
        }
    });

    it('loads billing', async () => {
        mockApi({ 'GET /api/billing': { data: BILLING } });
        const ctx = context();

        await billing.actions.fetchBilling(ctx);

        expect(ctx.commit.mock.calls).toEqual([['SET_LOADING', true], ['SET_ERROR', null], ['SET_BILLING', BILLING], ['SET_LOADING', false]]);
    });

    it('records a load failure', async () => {
        mockApi({ 'GET /api/billing': () => { throw apiError('Only workspace admins and owners can do this.'); } });
        const ctx = context();

        await billing.actions.fetchBilling(ctx);

        expect(ctx.commit).toHaveBeenCalledWith('SET_ERROR', 'Only workspace admins and owners can do this.');
        expect(ctx.commit).toHaveBeenLastCalledWith('SET_LOADING', false);
    });

    it('starts a checkout and fetches a payment', async () => {
        const posted = vi.fn(() => ({ data: { id: 7, redirect_url: 'https://pay.example.test/7' } }));
        mockApi({ 'POST /api/billing/checkout': posted, 'GET /api/billing/payments/7': { data: { id: 7, status: 'pending' } } });

        expect(await billing.actions.checkout(context(), { pack: 'starter', provider: 'stripe', currency: 'USD' })).toEqual({ id: 7, redirect_url: 'https://pay.example.test/7' });
        expect(posted).toHaveBeenCalledWith({ pack: 'starter', provider: 'stripe', currency: 'USD' });
        expect(await billing.actions.fetchPayment(context(), 7)).toEqual({ id: 7, status: 'pending' });
    });

    it('commits through its mutations', () => {
        const state = { ...billing.state };
        billing.mutations.SET_BILLING(state, BILLING);
        billing.mutations.SET_LOADING(state, true);
        billing.mutations.SET_ERROR(state, 'x');

        expect(state).toEqual({ billing: BILLING, loading: true, error: 'x' });
    });
});
