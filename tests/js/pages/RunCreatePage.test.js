import { afterEach, describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import RunCreatePage from '@/pages/RunCreatePage.vue';
import { flushPromises, makePageEnv } from '../helpers';

const REPOS = [
    { id: 1, display_name: 'acme/backend' },
    { id: 2, display_name: 'acme/web' },
];

let wrapper;

async function mountPage(propsData = {}, runsActions = {}) {
    const env = makePageEnv({ repoConfigs: REPOS, runsActions });
    wrapper = mount(RunCreatePage, { localVue: env.localVue, store: env.store, router: env.router, propsData });
    await flushPromises();

    return { wrapper, ...env };
}

async function fillAndSubmit({ repoIds = [1], text = 'As a user I want refunds', target } = {}) {
    wrapper.setData({ selectedRepoIds: repoIds });
    await wrapper.find('textarea').setValue(text);
    if (target !== undefined) await wrapper.find('input[type="text"]').setValue(target);
    await wrapper.find('form').trigger('submit');
    await flushPromises();
}

afterEach(() => {
    wrapper?.destroy();
    wrapper = undefined;
});

describe('RunCreatePage — Write Requirement', () => {
    it('makes the target file optional', async () => {
        await mountPage({ runType: 'requirement' });

        expect(wrapper.text()).toContain('Write Requirement');
        expect(wrapper.text()).toContain('Target file (optional)');
        expect(wrapper.find('input[type="text"]').attributes('required')).toBeUndefined();
    });

    it('submits without a target file and opens the new run', async () => {
        const { actions, router } = await mountPage({ runType: 'requirement' }, {
            createRun: vi.fn(() => Promise.resolve({ id: 'run-9' })),
        });

        await fillAndSubmit();

        expect(actions.createRun).toHaveBeenCalledWith({
            run_type: 'requirement', repo_config_id: 1, requirement_text: 'As a user I want refunds', target_file_path: '',
        });
        expect(router.currentRoute.params.id).toBe('run-9');
    });

    it('sends the target file when one is given', async () => {
        const { actions } = await mountPage({ runType: 'requirement' });

        await fillAndSubmit({ target: 'app/Services/RefundService.php' });

        expect(actions.createRun.mock.calls[0][0].target_file_path).toBe('app/Services/RefundService.php');
    });

    it('disables submit until a repository is selected', async () => {
        await mountPage();

        expect(wrapper.find('button[type="submit"]').attributes('disabled')).toBeDefined();
        await wrapper.setData({ selectedRepoIds: [1] });
        expect(wrapper.find('button[type="submit"]').attributes('disabled')).toBeUndefined();
    });

    it('starts one run per selected repository and goes to the runs list', async () => {
        const { actions, router } = await mountPage();

        await fillAndSubmit({ repoIds: [1, 2] });

        expect(actions.createRun).toHaveBeenCalledTimes(2);
        expect(actions.createRun.mock.calls.map(([p]) => p.repo_config_id)).toEqual([1, 2]);
        expect(router.currentRoute.name).toBe('runs.index');
    });

    it('reports which repositories failed and stays on the page', async () => {
        const busy = Object.assign(new Error('x'), { response: { data: { message: 'already has a run in progress' } } });
        const { router } = await mountPage({}, {
            createRun: vi.fn((p) => (p.repo_config_id === 2 ? Promise.reject(busy) : Promise.resolve({ id: 'ok' }))),
        });

        await fillAndSubmit({ repoIds: [1, 2] });

        expect(wrapper.text()).toContain('1 of 2 run(s) started. Failed — acme/web: already has a run in progress');
        expect(router.currentRoute.name).toBeNull();
    });

    it('sends attachments as multipart form data', async () => {
        const { actions } = await mountPage();
        const file = new File(['spec'], 'spec.md', { type: 'text/markdown' });
        const input = wrapper.find('input[type="file"]');
        Object.defineProperty(input.element, 'files', { value: [file] });
        await input.trigger('change');

        await fillAndSubmit();

        const payload = actions.createRun.mock.calls[0][0];
        expect(payload).toBeInstanceOf(FormData);
        expect(payload.get('requirement_text')).toBe('As a user I want refunds');
        expect(payload.getAll('attachments[]')[0].name).toBe('spec.md');
    });

    it('lets an attachment be removed before submitting', async () => {
        await mountPage();
        const input = wrapper.find('input[type="file"]');
        Object.defineProperty(input.element, 'files', { value: [new File(['a'], 'a.md'), new File(['b'], 'b.md')] });
        await input.trigger('change');

        await wrapper.findAll('button').filter((b) => b.text() === 'Remove').at(0).trigger('click');

        expect(wrapper.findAll('li').wrappers.map((li) => li.text())).toEqual([expect.stringContaining('b.md')]);
    });
});

describe('RunCreatePage — Code Requirement (coverage)', () => {
    it('has no target file field and never sends one', async () => {
        const { actions } = await mountPage({ runType: 'coverage' });

        expect(wrapper.text()).toContain('Code Requirement');
        expect(wrapper.text()).toContain('No target file needed');
        expect(wrapper.find('input[type="text"]').exists()).toBe(false);

        await fillAndSubmit({ text: 'Increase coverage for refunds' });

        expect(actions.createRun.mock.calls[0][0]).not.toHaveProperty('target_file_path', expect.anything());
        expect(actions.createRun.mock.calls[0][0].run_type).toBe('coverage');
    });
});

describe('RunCreatePage — Edit & resubmit', () => {
    it('prefills from the original run and links the new run to it', async () => {
        const original = { run_type: 'requirement', repo_config_id: 2, requirement_text: 'Old text', target_file_path: 'app/X.php' };
        const { actions, router } = await mountPage({ editingRunId: 'run-1' }, {
            fetchRun: vi.fn(() => Promise.resolve(original)),
            createRun: vi.fn(() => Promise.resolve({ id: 'run-2' })),
        });

        expect(wrapper.text()).toContain('Edit & resubmit');
        expect(wrapper.find('textarea').element.value).toBe('Old text');
        expect(wrapper.find('input[type="text"]').element.value).toBe('app/X.php');

        await wrapper.find('form').trigger('submit');
        await flushPromises();

        expect(actions.createRun).toHaveBeenCalledWith(expect.objectContaining({ previous_run_id: 'run-1', repo_config_id: 2, requirement_text: 'Old text' }));
        expect(router.currentRoute.params.id).toBe('run-2');
    });

    it('shows the API error when resubmitting fails', async () => {
        const failure = Object.assign(new Error('x'), { response: { data: { message: 'Repo is busy' } } });
        await mountPage({ editingRunId: 'run-1' }, {
            fetchRun: vi.fn(() => Promise.resolve({ run_type: 'requirement', repo_config_id: 1, requirement_text: 'x', target_file_path: '' })),
            createRun: vi.fn(() => Promise.reject(failure)),
        });

        await wrapper.find('form').trigger('submit');
        await flushPromises();

        expect(wrapper.text()).toContain('Repo is busy');
    });

    it('resets the form when switching between the create routes', async () => {
        await mountPage({ runType: 'requirement' });
        await wrapper.find('textarea').setValue('Draft');

        await wrapper.setProps({ runType: 'coverage' });

        expect(wrapper.find('textarea').element.value).toBe('');
        expect(wrapper.text()).toContain('Code Requirement');
    });
});
