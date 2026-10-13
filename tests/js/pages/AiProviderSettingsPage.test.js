import { afterEach, describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import AiProviderSettingsPage from '@/pages/AiProviderSettingsPage.vue';
import aiProvider from '@/store/modules/aiProvider';
import { apiError, flushPromises, makeLocalVueWithRouter, makeRealStore, mockApi } from '../helpers';

const PLATFORM = { provider: 'platform' };
const OWN = { provider: 'anthropic', secret_hint: '…AbCd', status: 'valid', validation_error: null, validated_at: '2026-10-13T10:00:00Z', updated_by: 'Ops Admin' };
const KEY = 'sk-ant-api03-workspace-own-key-AbCd';

let wrapper;

async function mountPage(credential, api = {}) {
    mockApi({ 'GET /api/workspace/ai-credentials': { data: credential }, ...api });
    const { localVue, router } = makeLocalVueWithRouter({ path: '/settings/ai-provider' });
    wrapper = mount(AiProviderSettingsPage, { localVue, store: makeRealStore(localVue, { aiProvider }), router, attachTo: document.body });
    await flushPromises();
}

function button(label) {
    return wrapper.findAll('button').filter((b) => b.text() === label).at(0);
}

async function submitKey(key) {
    await wrapper.find('#api-key').setValue(key);
    await wrapper.find('form').trigger('submit');
    await flushPromises();
}

afterEach(() => {
    wrapper?.destroy();
    wrapper = undefined;
});

describe('AiProviderSettingsPage', () => {
    it('explains the platform account and credits by default', async () => {
        await mountPage(PLATFORM);

        expect(wrapper.find('[data-current]').text()).toContain("BoschifAI's Anthropic account");
        expect(wrapper.find('[data-current]').text()).toContain("Runs are paid for with this workspace's credits.");
        expect(wrapper.text()).toContain('Use your own Anthropic API key');
        expect(wrapper.findAll('button').filter((b) => b.text() === 'Check key').length).toBe(0);
    });

    it('saves a key, then clears it from the page', async () => {
        const put = vi.fn(() => ({ data: OWN }));
        await mountPage(PLATFORM, { 'PUT /api/workspace/ai-credentials': put });

        expect(wrapper.find('#api-key').attributes('type')).toBe('password');
        expect(button('Save key').attributes('disabled')).toBeDefined();
        await submitKey(`  ${KEY} `);

        expect(put).toHaveBeenCalledWith({ api_key: KEY });
        expect(wrapper.find('#api-key').element.value).toBe('');
        expect(wrapper.find('[role="status"]').text()).toBe('Saved. New runs use this key.');
        expect(wrapper.find('[data-current]').text()).toContain("This workspace's own Anthropic API key (…AbCd)");
        expect(wrapper.find('[data-status]').text()).toContain('Accepted by Anthropic');
        expect(wrapper.text()).toContain('Replace the key');
    });

    it('shows why a key was refused, and still clears it', async () => {
        const refusal = Object.assign(new Error('422'), { response: { data: { message: 'invalid', errors: { api_key: ['Anthropic rejected this API key. Check that it was copied in full and is still active in the Anthropic Console.'] } } } });
        await mountPage(PLATFORM, { 'PUT /api/workspace/ai-credentials': () => { throw refusal; } });

        await submitKey(KEY);

        expect(wrapper.find('[role="alert"]').text()).toContain('Anthropic rejected this API key.');
        expect(wrapper.find('#api-key').element.value).toBe('');
        expect(wrapper.find('[data-current]').text()).toContain("BoschifAI's Anthropic account");
    });

    it('re-checks the key and shows why it no longer works', async () => {
        const invalid = { ...OWN, status: 'invalid', validation_error: 'Anthropic rejected this API key. Check that it was copied in full and is still active in the Anthropic Console.' };
        await mountPage(OWN, { 'POST /api/workspace/ai-credentials/check': { data: invalid } });

        await button('Check key').trigger('click');
        await flushPromises();

        expect(wrapper.find('[data-status]').text()).toBe(invalid.validation_error);
        expect(wrapper.find('[role="status"]').exists()).toBe(false);
    });

    it('says when Anthropic could not be reached', async () => {
        await mountPage(OWN, { 'POST /api/workspace/ai-credentials/check': () => { throw apiError("Couldn't reach Anthropic to check the key. Nothing was changed — try again in a moment."); } });

        await button('Check key').trigger('click');
        await flushPromises();

        expect(wrapper.find('[role="alert"]').text()).toContain("Couldn't reach Anthropic");
    });

    it('removes the key only after confirmation', async () => {
        const removed = vi.fn(() => ({ data: PLATFORM }));
        await mountPage(OWN, { 'DELETE /api/workspace/ai-credentials': removed });

        await button('Remove key').trigger('click');
        expect(wrapper.text()).toContain("Runs will go back to BoschifAI's Anthropic account and use this workspace's credits.");
        await button('Cancel').trigger('click');
        expect(removed).not.toHaveBeenCalled();

        await button('Remove key').trigger('click');
        const confirm = wrapper.findAll('button').filter((b) => b.text() === 'Remove key');
        await confirm.at(confirm.length - 1).trigger('click');
        await flushPromises();

        expect(removed).toHaveBeenCalled();
        expect(wrapper.find('[data-current]').text()).toContain("BoschifAI's Anthropic account");
        expect(wrapper.find('[role="status"]').text()).toContain('Removed.');
    });

    it('shows a loading error', async () => {
        mockApi({ 'GET /api/workspace/ai-credentials': () => { throw apiError('Only workspace admins and owners can do this.'); } });
        const { localVue, router } = makeLocalVueWithRouter();
        wrapper = mount(AiProviderSettingsPage, { localVue, store: makeRealStore(localVue, { aiProvider }), router });
        await flushPromises();

        expect(wrapper.find('[role="alert"]').text()).toBe('Only workspace admins and owners can do this.');
    });
});

describe('AiProviderSettingsPage — Amazon Bedrock', () => {
    const BEDROCK_SETUP = { available: true, principal_arn: 'arn:aws:iam::999999999999:role/boschifai-worker', external_id: 'boschifai-abc123' };
    const BEDROCK = {
        provider: 'bedrock', secret_hint: 'BoschifAIBedrock', status: 'valid', validation_error: null, validated_at: '2026-10-13T10:00:00Z',
        config: { role_arn: 'arn:aws:iam::123456789012:role/BoschifAIBedrock', region: 'eu-west-1', model: 'us.anthropic.claude-sonnet-4-6' },
        bedrock: BEDROCK_SETUP,
    };

    it('only offers Bedrock when the server supports it', async () => {
        await mountPage({ ...PLATFORM, bedrock: { available: false } });
        expect(wrapper.find('[role="tablist"]').exists()).toBe(false);
        wrapper.destroy();

        await mountPage({ ...PLATFORM, bedrock: BEDROCK_SETUP });
        expect(wrapper.findAll('[role="tab"]').wrappers.map((t) => t.text())).toEqual(['Anthropic API key', 'Amazon Bedrock']);
    });

    it('shows a trust policy for BoschifAI with this workspace\'s external ID, and the permissions to grant', async () => {
        await mountPage({ ...PLATFORM, bedrock: BEDROCK_SETUP });
        await wrapper.findAll('[role="tab"]').at(1).trigger('click');

        const trust = JSON.parse(wrapper.find('[data-trust-policy]').text());
        expect(trust.Statement[0].Principal.AWS).toBe(BEDROCK_SETUP.principal_arn);
        expect(trust.Statement[0].Condition.StringEquals['sts:ExternalId']).toBe('boschifai-abc123');
        const permissions = JSON.parse(wrapper.find('[data-permissions-policy]').text());
        expect(permissions.Statement.flatMap((st) => [].concat(st.Action))).toEqual(expect.arrayContaining(['bedrock:InvokeModel', 'bedrock:InvokeModelWithResponseStream']));
    });

    it('saves the role, region and model', async () => {
        const put = vi.fn(() => ({ data: BEDROCK }));
        await mountPage({ ...PLATFORM, bedrock: BEDROCK_SETUP }, { 'PUT /api/workspace/ai-credentials': put });
        await wrapper.findAll('[role="tab"]').at(1).trigger('click');

        await wrapper.find('#role-arn').setValue(' arn:aws:iam::123456789012:role/BoschifAIBedrock ');
        await wrapper.find('#region').setValue('eu-west-1');
        await wrapper.find('#model').setValue('us.anthropic.claude-sonnet-4-6');
        await wrapper.find('[data-bedrock-setup] form').trigger('submit');
        await flushPromises();

        expect(put).toHaveBeenCalledWith({ provider: 'bedrock', ...BEDROCK.config });
        expect(wrapper.find('[role="status"]').text()).toBe('Saved. New runs use your Amazon Bedrock.');
        expect(wrapper.find('[data-current]').text()).toContain("This workspace's own Amazon Bedrock");
        expect(wrapper.find('[data-current]').text()).toContain('costs shown in BoschifAI are estimates');
    });

    it('opens on the saved Bedrock setup, and checks or stops it', async () => {
        const removed = vi.fn(() => ({ data: { ...PLATFORM, bedrock: BEDROCK_SETUP } }));
        await mountPage(BEDROCK, { 'POST /api/workspace/ai-credentials/check': { data: BEDROCK }, 'DELETE /api/workspace/ai-credentials': removed });

        expect(wrapper.find('#role-arn').element.value).toBe(BEDROCK.config.role_arn);
        expect(wrapper.find('[data-current]').text()).toContain('eu-west-1');
        await button('Check access').trigger('click');
        await flushPromises();

        await button('Stop using Bedrock').trigger('click');
        expect(wrapper.text()).toContain('Stop using Amazon Bedrock?');
        const confirm = wrapper.findAll('button').filter((b) => b.text() === 'Stop using Bedrock');
        await confirm.at(confirm.length - 1).trigger('click');
        await flushPromises();
        expect(removed).toHaveBeenCalled();
    });
});

describe('aiProvider store', () => {
    it('knows whether the workspace uses its own key', () => {
        expect(aiProvider.getters.usesOwnKey({ credential: null })).toBe(false);
        expect(aiProvider.getters.usesOwnKey({ credential: PLATFORM })).toBe(false);
        expect(aiProvider.getters.usesOwnKey({ credential: OWN })).toBe(true);
    });
});
