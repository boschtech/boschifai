import { afterEach, describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import RepoFileBrowser from '@/components/RepoFileBrowser.vue';
import { apiError, flushPromises, mockApi } from '../helpers';

const LISTINGS = {
    '': { path: '', entries: [{ name: 'app', path: 'app', type: 'dir' }, { name: 'composer.json', path: 'composer.json', type: 'file' }] },
    app: { path: 'app', entries: [{ name: 'Http', path: 'app/Http', type: 'dir' }] },
    'app/Http': { path: 'app/Http', entries: [{ name: 'RefundController.php', path: 'app/Http/RefundController.php', type: 'file' }] },
};

let wrapper;

async function openBrowser(api = { 'GET /api/repo-configs/7/browse-files': (config) => LISTINGS[config.params.path] }) {
    mockApi(api);
    wrapper = mount(RepoFileBrowser, { propsData: { open: false, repoConfigId: 7, repoDisplayName: 'acme/api' } });
    await wrapper.setProps({ open: true });
    await flushPromises();

    return wrapper;
}

function names() {
    return wrapper.findAll('li button').wrappers.map((b) => b.text());
}

afterEach(() => {
    wrapper?.destroy();
});

describe('RepoFileBrowser', () => {
    it('stays closed until opened, then lists the repo root', async () => {
        await openBrowser();

        expect(wrapper.text()).toContain('Browse acme/api');
        expect(names()).toEqual(['app', 'composer.json']);
    });

    it('opens folders and walks back via the breadcrumbs', async () => {
        await openBrowser();

        await wrapper.findAll('li button').at(0).trigger('click');
        await flushPromises();
        await wrapper.findAll('li button').at(0).trigger('click');
        await flushPromises();

        expect(names()).toEqual(['RefundController.php']);
        expect(wrapper.findAll('nav button').wrappers.map((b) => b.text())).toEqual(['root', 'app', 'Http']);

        await wrapper.findAll('nav button').at(1).trigger('click');
        await flushPromises();
        expect(names()).toEqual(['Http']);

        await wrapper.findAll('nav button').at(0).trigger('click');
        await flushPromises();
        expect(names()).toEqual(['app', 'composer.json']);
    });

    it('selects a file and closes', async () => {
        await openBrowser();

        await wrapper.findAll('li button').at(1).trigger('click');

        expect(wrapper.emitted('select')).toEqual([['composer.json']]);
        expect(wrapper.emitted('close')).toHaveLength(1);
    });

    it('closes from the backdrop', async () => {
        await openBrowser();

        await wrapper.find('.fixed').trigger('click');

        expect(wrapper.emitted('close')).toHaveLength(1);
    });

    it('explains an empty folder and a failed listing', async () => {
        await openBrowser({ 'GET /api/repo-configs/7/browse-files': { path: '', entries: [] } });
        expect(wrapper.text()).toContain('Empty directory.');
        wrapper.destroy();

        await openBrowser({ 'GET /api/repo-configs/7/browse-files': () => { throw apiError('Repository has not been checked out yet'); } });
        expect(wrapper.text()).toContain('Repository has not been checked out yet');
        expect(wrapper.find('ul').exists()).toBe(false);
    });
});
