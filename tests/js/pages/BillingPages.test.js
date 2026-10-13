import { afterEach, describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import BillingPage from '@/pages/BillingPage.vue';
import BillingReturnPage from '@/pages/BillingReturnPage.vue';
import SelectDropdown from '@/components/SelectDropdown.vue';
import billing from '@/store/modules/billing';
import { apiError, flushPromises, makeLocalVueWithRouter, makeRealStore, mockApi } from '../helpers';

const STARTER = { key: 'starter', credits_usd: '10.00', price_usd: '10.00' };
const TEAM = { key: 'team', credits_usd: '50.00', price_usd: '45.50' };

function billingData(overrides = {}) {
    return {
        available_usd: '12.50',
        held_usd: '0.00',
        packs: [STARTER, TEAM],
        providers: [{ key: 'stripe', currencies: ['USD'] }],
        payments: [],
        ...overrides,
    };
}

let wrapper;

async function mountBilling({ data = billingData(), api = {}, path = '/billing' } = {}) {
    const redirect = vi.fn();
    mockApi({ 'GET /api/billing': { data }, ...api });
    const { localVue, router } = makeLocalVueWithRouter({ path });
    const store = makeRealStore(localVue, { billing });
    wrapper = mount(BillingPage, { localVue, store, router, propsData: { redirect }, attachTo: document.body });
    await flushPromises();

    return { redirect };
}

function button(label) {
    return wrapper.findAll('button').filter((b) => b.text() === label).at(0);
}

afterEach(() => {
    wrapper?.destroy();
    wrapper = undefined;
    vi.useRealTimers();
});

describe('BillingPage', () => {
    it('shows the balance, and what is reserved for running steps', async () => {
        await mountBilling({ data: billingData({ held_usd: '3.00' }) });

        expect(wrapper.find('[data-balance]').text()).toContain('US$12.50');
        expect(wrapper.find('[data-balance]').text()).toContain('Plus US$3.00 reserved for steps that are running now');
    });

    it('does not mention reserved credits when nothing is held', async () => {
        await mountBilling();

        expect(wrapper.find('[data-balance]').text()).not.toContain('reserved');
        expect(wrapper.find('[data-own-key]').exists()).toBe(false);
    });

    it('explains that runs on the workspace\'s own key do not use credits', async () => {
        await mountBilling({ data: billingData({ uses_own_ai_key: true }) });

        expect(wrapper.find('[data-own-key]').text()).toContain("so they don't use credits");
        expect(wrapper.find('[data-own-key] a').attributes('href')).toBe('/settings/ai-provider');
    });

    it('buys a USD pack by going straight to the provider', async () => {
        const posted = vi.fn(() => ({ data: { id: 7, charge: '45.50', charge_currency: 'USD', credits_usd: '50.00', redirect_url: 'https://pay.example.test/7' } }));
        const { redirect } = await mountBilling({ api: { 'POST /api/billing/checkout': posted } });

        expect(button('Buy credits').attributes('disabled')).toBeDefined();
        expect(wrapper.text()).toContain('Paid with Stripe');
        await wrapper.find('[data-pack="team"]').trigger('click');
        expect(wrapper.find('[data-pack="team"]').attributes('aria-pressed')).toBe('true');
        await button('Buy credits').trigger('click');
        await flushPromises();

        expect(posted).toHaveBeenCalledWith({ pack: 'team', provider: 'stripe', currency: 'USD' });
        expect(redirect).toHaveBeenCalledWith('https://pay.example.test/7');
    });

    it('shows the exact amount before paying in another currency, and can go back', async () => {
        const posted = vi.fn(() => ({ data: { id: 8, charge: '855.44', charge_currency: 'ZAR', credits_usd: '50.00', redirect_url: 'https://pay.example.test/8' } }));
        const { redirect } = await mountBilling({
            data: billingData({ providers: [{ key: 'stripe', currencies: ['USD'] }, { key: 'payfast', currencies: ['ZAR'] }] }),
            api: { 'POST /api/billing/checkout': posted },
        });

        const picker = wrapper.findComponent(SelectDropdown);
        await picker.find('button').trigger('click');
        expect(picker.findAll('[role="option"]').wrappers.map((o) => o.text())).toEqual(['Stripe', 'PayFast']);
        await picker.findAll('[role="option"]').at(1).trigger('click');
        expect(wrapper.find('[data-pay-in]').text()).toBe('in ZAR');
        await wrapper.find('[data-pack="team"]').trigger('click');
        await button('Buy credits').trigger('click');
        await flushPromises();

        expect(posted).toHaveBeenCalledWith({ pack: 'team', provider: 'payfast', currency: 'ZAR' });
        expect(redirect).not.toHaveBeenCalled();
        expect(wrapper.find('[data-quote]').text()).toContain("You'll be charged ZAR 855.44 for 50.00 credits.");

        await button('Back').trigger('click');
        expect(wrapper.find('[data-quote]').exists()).toBe(false);
        await button('Buy credits').trigger('click');
        await flushPromises();
        await button('Continue to payment').trigger('click');
        expect(redirect).toHaveBeenCalledWith('https://pay.example.test/8');
    });

    it('lets the buyer choose the currency when the provider offers several', async () => {
        const posted = vi.fn(() => ({ data: { id: 9, charge: '10.00', charge_currency: 'USD', credits_usd: '10.00', redirect_url: 'https://checkout.paystack.com/x' } }));
        const { redirect } = await mountBilling({ data: billingData({ providers: [{ key: 'paystack', currencies: ['ZAR', 'USD'] }] }), api: { 'POST /api/billing/checkout': posted } });

        expect(wrapper.text()).toContain('Prices are in US dollars.');
        expect(wrapper.text()).toContain('Paid with Paystack');
        const currency = wrapper.findAll('[aria-label="Pay in"]').at(0);
        expect(currency.text()).toBe('ZAR');
        await currency.trigger('click');
        await wrapper.findAll('[role="option"]').filter((o) => o.text() === 'USD').at(0).trigger('click');
        await wrapper.find('[data-pack="starter"]').trigger('click');
        await button('Buy credits').trigger('click');
        await flushPromises();

        expect(posted).toHaveBeenCalledWith({ pack: 'starter', provider: 'paystack', currency: 'USD' });
        expect(redirect).toHaveBeenCalledWith('https://checkout.paystack.com/x');
    });

    it('says why a checkout was refused', async () => {
        await mountBilling({ api: { 'POST /api/billing/checkout': () => { throw apiError('That payment method is not available.'); } } });

        await wrapper.find('[data-pack="starter"]').trigger('click');
        await button('Buy credits').trigger('click');
        await flushPromises();

        expect(wrapper.find('[role="alert"]').text()).toBe('That payment method is not available.');
        expect(button('Buy credits').attributes('disabled')).toBeUndefined();
    });

    it.each([
        ['no packs', { packs: [] }],
        ['no payment provider', { providers: [] }],
    ])('explains that buying is not available yet when there are %s', async (_label, overrides) => {
        await mountBilling({ data: billingData(overrides) });

        expect(wrapper.find('[data-not-available]').text()).toContain("Buying credits isn't available yet.");
        expect(wrapper.findAll('button').filter((b) => b.text() === 'Buy credits').length).toBe(0);
    });

    it('lists payments with their status', async () => {
        await mountBilling({ data: billingData({ payments: [
            { id: 1, status: 'succeeded', credits_usd: '50.00', charge: '45.50', charge_currency: 'USD', created_at: '2026-10-13T10:00:00Z' },
            { id: 2, status: 'needs_review', credits_usd: '10.00', charge: '182.53', charge_currency: 'ZAR', created_at: '2026-10-13T11:00:00Z' },
            { id: 3, status: 'failed', credits_usd: '10.00', charge: '10.00', charge_currency: 'USD', created_at: '2026-10-13T12:00:00Z' },
            { id: 4, status: 'pending', credits_usd: '10.00', charge: '10.00', charge_currency: 'USD', created_at: '2026-10-13T13:00:00Z' },
            { id: 5, status: 'something_new', credits_usd: '10.00', charge: '10.00', charge_currency: 'USD', created_at: '2026-10-13T14:00:00Z' },
        ] }) });

        const rows = wrapper.findAll('[data-payment]').wrappers.map((row) => row.text());
        expect(rows[0]).toContain('50.00 credits');
        expect(rows[0]).toContain('US$45.50');
        expect(wrapper.findAll('[data-status]').wrappers.map((s) => s.text())).toEqual(['Paid', 'Being checked', 'Failed', 'Pending', 'something_new']);
        expect(rows[1]).toContain('ZAR 182.53');
    });

    it('confirms a cancelled checkout charged nothing', async () => {
        await mountBilling({ path: '/billing?cancelled=1' });

        expect(wrapper.find('[role="status"]').text()).toBe('Checkout was cancelled. Nothing was charged.');
    });

    it('shows a loading error', async () => {
        mockApi({ 'GET /api/billing': () => { throw apiError('Only workspace admins and owners can do this.'); } });
        const { localVue, router } = makeLocalVueWithRouter({ path: '/billing' });
        wrapper = mount(BillingPage, { localVue, store: makeRealStore(localVue, { billing }), router });
        await flushPromises();

        expect(wrapper.find('[role="alert"]').text()).toBe('Only workspace admins and owners can do this.');
    });
});

describe('BillingReturnPage', () => {
    async function mountReturn(responses) {
        let call = 0;
        mockApi({ 'GET /api/billing/payments/7': () => {
            const next = responses[Math.min(call++, responses.length - 1)];
            if (next instanceof Error) throw next;

            return { data: { id: 7, credits_usd: '50.00', ...next } };
        } });
        const { localVue, router } = makeLocalVueWithRouter({ path: '/billing/payments/7' });
        wrapper = mount(BillingReturnPage, { localVue, store: makeRealStore(localVue, { billing }), router, propsData: { id: '7' } });
        await flushPromises();
    }

    it('waits for the confirmation, then shows the credits added', async () => {
        vi.useFakeTimers();
        await mountReturn([{ status: 'pending' }, { status: 'succeeded' }]);
        expect(wrapper.find('[data-state="pending"]').text()).toContain('Waiting for the payment provider to confirm your payment');

        await vi.advanceTimersByTimeAsync(3000);
        await flushPromises();

        expect(wrapper.find('[data-state="succeeded"]').text()).toContain('50.00 credits were added to this workspace.');
        expect(wrapper.find('a').attributes('href')).toBe('/billing');
    });

    it.each([
        ['failed', 'The payment didn\'t go through'],
        ['needs_review', 'it\'s being checked before credits are added'],
    ])('explains a %s payment', async (status, text) => {
        await mountReturn([{ status }]);

        expect(wrapper.find(`[data-state="${status}"]`).text()).toContain(text);
    });

    it('stops checking after about two minutes and says the credits will still arrive', async () => {
        vi.useFakeTimers();
        await mountReturn([{ status: 'pending' }]);

        await vi.advanceTimersByTimeAsync(3000 * 45);
        await flushPromises();

        expect(window.axios.get).toHaveBeenCalledTimes(40);
        expect(wrapper.text()).toContain('This is taking longer than usual.');
        expect(wrapper.text()).toContain("Please don't pay again.");
    });

    it('shows why the payment could not be loaded', async () => {
        await mountReturn([apiError('No query results for model [App\\Models\\Payment].')]);

        expect(wrapper.find('[role="alert"]').text()).toBe('No query results for model [App\\Models\\Payment].');
    });

    it('stops checking when the page is left', async () => {
        vi.useFakeTimers();
        await mountReturn([{ status: 'pending' }]);
        wrapper.destroy();
        wrapper = undefined;

        await vi.advanceTimersByTimeAsync(30000);

        expect(window.axios.get).toHaveBeenCalledTimes(1);
    });
});
