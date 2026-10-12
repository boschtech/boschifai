import { afterEach, describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import GithubSettingsPage from '@/pages/GithubSettingsPage.vue';
import github from '@/store/modules/github';
import localRepos from '@/store/modules/localRepos';
import repoConfigs from '@/store/modules/repoConfigs';
import { apiError, flushPromises, makeLocalVueWithRouter, makeRealStore, mockApi } from '../helpers';

const REPOS_CONN_1 = [
    { full_name: 'acme/api', default_branch: 'main', connected: true, repo_config_id: 11 },
    { full_name: 'acme/web', default_branch: 'main', connected: false },
    { full_name: 'dev-one/sandbox', default_branch: 'dev', connected: false },
];
const REPOS_CONN_2 = [{ full_name: 'beta/app', default_branch: 'main', connected: false }];

const CONNECTED_CONFIGS = [
    { id: 11, name: 'api', connected_via_github: true, github_owner: 'acme' },
    { id: 12, name: 'local-thing', connected_via_github: false },
];

function defaultApi(overrides = {}) {
    return {
        'GET /api/repo-configs': { data: CONNECTED_CONFIGS },
        'GET /api/github/connections': { data: [{ id: 1 }, { id: 2 }] },
        'GET /api/github/connections/1/repositories': { data: REPOS_CONN_1, excluded_organizations: ['old-org'] },
        'GET /api/github/connections/2/repositories': { data: REPOS_CONN_2 },
        'GET /api/local-repos/browse': (config) => ({
            path: config.params.path,
            entries: config.params.path === ''
                ? [
                    { name: 'projects', path: 'projects', is_git_repo: false },
                    { name: 'rams', path: 'rams', is_git_repo: true, connected: true, repo_config_id: 21 },
                    { name: 'tools', path: 'tools', is_git_repo: true, connected: false },
                ]
                : [{ name: 'deep', path: `${config.params.path}/deep`, is_git_repo: true }],
        }),
        ...overrides,
    };
}

let wrapper;

async function mountPage(apiOverrides = {}, { query = {} } = {}) {
    const api = defaultApi(apiOverrides);
    mockApi(api);
    const { localVue, router } = makeLocalVueWithRouter({ path: '/settings/github', query });
    const store = makeRealStore(localVue, { github, localRepos, repoConfigs });
    wrapper = mount(GithubSettingsPage, { localVue, store, router, attachTo: document.body });
    await flushPromises();

    return { wrapper, store, api };
}

function buttonByText(text) {
    return wrapper.findAll('button').filter((b) => b.text().includes(text)).at(0);
}

function repoCheckbox(fullName) {
    return wrapper.find(`input[id="repo-${fullName}"]`);
}

function remoteRepoNames() {
    return wrapper.findAll('input[id^="repo-"]').wrappers.map((w) => w.attributes('id').replace('repo-', ''));
}

async function openOrgDropdown() {
    await wrapper.find('button.min-w-\\[12rem\\]').trigger('click');
}

afterEach(() => {
    wrapper?.destroy();
    wrapper = undefined;
});

describe('GithubSettingsPage — remote repositories', () => {
    it('loads every connected account’s repositories side by side, with every organisation selected', async () => {
        await mountPage();

        expect(remoteRepoNames()).toEqual(['acme/api', 'acme/web', 'dev-one/sandbox', 'beta/app']);
        expect(wrapper.vm.selectedOrgs.sort()).toEqual(['acme', 'beta', 'dev-one']);
        expect(wrapper.text()).toContain('3 organisations selected');
        expect(wrapper.text()).toContain('+ Connect a different GitHub account');
    });

    it('pre-ticks repositories that are already connected and marks them', async () => {
        await mountPage();

        expect(repoCheckbox('acme/api').element.checked).toBe(true);
        expect(repoCheckbox('acme/web').element.checked).toBe(false);
        expect(wrapper.find('label[for="repo-acme/api"]').text()).toContain('connected');
    });

    it('summarises connected repositories per organisation', async () => {
        await mountPage();

        expect(wrapper.text()).toContain('Connected organisations');
        expect(wrapper.text()).toContain('acme');
        expect(wrapper.text()).toContain('— 1 repo selected');
        expect(wrapper.text()).toContain('1 repo(s) connected');
    });

    it('offers to connect GitHub when no account is connected', async () => {
        await mountPage({ 'GET /api/github/connections': { data: [] } });

        expect(wrapper.find('a[href="/github/authorize"]').text()).toBe('Connect GitHub');
        expect(wrapper.text()).toContain('No GitHub accounts connected yet');
    });

    it('shows GitHub’s own message when authorization was cancelled', async () => {
        await mountPage({}, { query: { error: 'access_denied' } });

        expect(wrapper.text()).toContain('access_denied');
    });

    it('shows the store’s error when connections fail to load', async () => {
        await mountPage({ 'GET /api/github/connections': () => { throw apiError('GitHub token expired'); } });

        expect(wrapper.text()).toContain('GitHub token expired');
    });

    it('filters the list to the organisations ticked in the dropdown', async () => {
        await mountPage();
        await openOrgDropdown();

        const orgBoxes = wrapper.findAll('ul.w-64 input[type="checkbox"]');
        expect(orgBoxes.wrappers.map((b) => b.element.value)).toEqual(['acme', 'beta', 'dev-one']);

        await orgBoxes.at(1).setChecked(false); // beta
        await orgBoxes.at(2).setChecked(false); // dev-one

        expect(remoteRepoNames()).toEqual(['acme/api', 'acme/web']);
        expect(wrapper.text()).toContain('acme');

        await orgBoxes.at(0).setChecked(false); // nothing ticked → everything shows
        expect(remoteRepoNames()).toHaveLength(4);
        expect(wrapper.text()).toContain('Select organisations');
    });

    it('labels up to two selected organisations by name', async () => {
        await mountPage();
        await wrapper.setData({ selectedOrgs: ['acme', 'beta'] });

        expect(wrapper.text()).toContain('acme, beta');
    });

    it('closes the organisation dropdown on an outside click', async () => {
        await mountPage();
        await openOrgDropdown();
        expect(wrapper.find('ul.w-64').exists()).toBe(true);

        document.body.click();
        await wrapper.vm.$nextTick();

        expect(wrapper.find('ul.w-64').exists()).toBe(false);
    });

    it('says when a filter leaves no repositories', async () => {
        await mountPage({ 'GET /api/github/connections/1/repositories': { data: [] }, 'GET /api/github/connections/2/repositories': { data: [] } });

        expect(wrapper.text()).toContain('No repositories here.');
        expect(buttonByText('Save selected repositories').attributes('disabled')).toBeDefined();
    });

    it('saves the selection through each repository’s own connection', async () => {
        const posted = {};
        await mountPage({
            'POST /api/github/connections/1/repositories': (body) => { posted[1] = body; return {}; },
            'POST /api/github/connections/2/repositories': (body) => { posted[2] = body; return {}; },
        });
        await repoCheckbox('acme/web').setChecked(true);
        await repoCheckbox('beta/app').setChecked(true);

        await buttonByText('Save selected repositories').trigger('click');
        await flushPromises();

        expect(posted[1]).toEqual({ repositories: [
            { full_name: 'acme/api', default_branch: 'main' },
            { full_name: 'acme/web', default_branch: 'main' },
        ] });
        expect(posted[2]).toEqual({ repositories: [{ full_name: 'beta/app', default_branch: 'main' }] });
        expect(wrapper.text()).toContain('Saved — these repositories are now available');
    });

    it('removes a connected repository after confirmation', async () => {
        // Like the real API: once deleted, the repo comes back as not connected.
        let repos = REPOS_CONN_1;
        const deleted = vi.fn(() => {
            repos = REPOS_CONN_1.map((r) => (r.full_name === 'acme/api' ? { ...r, connected: false } : r));
            return {};
        });
        await mountPage({
            'DELETE /api/repo-configs/11': deleted,
            'GET /api/github/connections/1/repositories': () => ({ data: repos, excluded_organizations: [] }),
        });

        await wrapper.find('button[title="Remove from Boschifai"]').trigger('click');
        expect(wrapper.text()).toContain('"acme/api"');
        expect(wrapper.text()).toContain('it does not revoke or change your GitHub authorization');

        await buttonByText('Remove').trigger('click');
        await flushPromises();

        expect(deleted).toHaveBeenCalled();
        expect(wrapper.vm.selectedRepoNames).not.toContain('acme/api');
        expect(repoCheckbox('acme/api').element.checked).toBe(false);
    });

    it('keeps the repository and explains why when removal fails', async () => {
        await mountPage({ 'DELETE /api/repo-configs/11': () => { throw apiError('Repository has an active run'); } });

        await wrapper.find('button[title="Remove from Boschifai"]').trigger('click');
        await buttonByText('Remove').trigger('click');
        await flushPromises();

        expect(wrapper.text()).toContain('Repository has an active run');
        expect(wrapper.vm.removingId).toBeNull();
    });

    it('cancelling the confirmation removes nothing', async () => {
        await mountPage();

        await wrapper.find('button[title="Remove from Boschifai"]').trigger('click');
        await buttonByText('Cancel').trigger('click');

        expect(wrapper.vm.pendingRemove).toBeNull();
        expect(window.axios.delete).not.toHaveBeenCalled();
    });

    it('removes a whole organisation after confirming which repos go with it', async () => {
        // Like the real API: an excluded org's repos stop being listed and it shows as hidden.
        let listing = { data: REPOS_CONN_1, excluded_organizations: [] };
        const excluded = vi.fn(() => {
            listing = { data: REPOS_CONN_1.filter((r) => !r.full_name.startsWith('acme/')), excluded_organizations: ['acme'] };
            return {};
        });
        await mountPage({
            'DELETE /api/github/connections/1/organizations/acme': excluded,
            'GET /api/github/connections/1/repositories': () => listing,
        });
        await openOrgDropdown();

        await wrapper.find('button[title^="Remove this organisation"]').trigger('click');
        expect(wrapper.text()).toContain('"acme" — 1 repo: api');

        await buttonByText('Remove all').trigger('click');
        await flushPromises();

        expect(excluded).toHaveBeenCalled();
        expect(wrapper.vm.selectedOrgs).not.toContain('acme');
        expect(wrapper.vm.selectedRepoNames.some((n) => n.startsWith('acme/'))).toBe(false);
        expect(remoteRepoNames()).toEqual(['dev-one/sandbox', 'beta/app']);
        expect(wrapper.text()).toContain('Hidden:');
    });

    it('explains a failed organisation removal', async () => {
        await mountPage({ 'DELETE /api/github/connections/1/organizations/acme': () => { throw apiError('Org removal failed'); } });
        await openOrgDropdown();

        await wrapper.find('button[title^="Remove this organisation"]').trigger('click');
        await buttonByText('Remove all').trigger('click');
        await flushPromises();

        expect(wrapper.text()).toContain('Org removal failed');
        expect(wrapper.vm.removingOrg).toBe(false);
    });

    it('restores a hidden organisation and reloads that account', async () => {
        const restored = vi.fn(() => ({}));
        await mountPage({ 'POST /api/github/connections/1/organizations/old-org/restore': restored });
        expect(wrapper.text()).toContain('Hidden:');

        await buttonByText('old-org').trigger('click');
        await flushPromises();

        expect(restored).toHaveBeenCalled();
        expect(window.axios.get.mock.calls.filter(([url]) => url === '/api/github/connections/1/repositories')).toHaveLength(2);
    });

    it('explains a failed restore', async () => {
        await mountPage({ 'POST /api/github/connections/1/organizations/old-org/restore': () => { throw apiError('Restore failed'); } });

        await buttonByText('old-org').trigger('click');
        await flushPromises();

        expect(wrapper.text()).toContain('Restore failed');
    });

    it('carries on when one account’s repositories fail to load', async () => {
        await mountPage({ 'GET /api/github/connections/2/repositories': () => { throw apiError('Account 2 revoked'); } });

        expect(remoteRepoNames()).toEqual(['acme/api', 'acme/web', 'dev-one/sandbox']);
        expect(wrapper.text()).toContain('Account 2 revoked');
    });
});

describe('GithubSettingsPage — local repositories', () => {
    function localNames() {
        return wrapper.findAll('details').at(1).findAll('li button.flex-1').wrappers.map((b) => b.text().split(/\s+/)[0]);
    }

    it('lists the root folder, marking git repos and pre-ticking connected ones', async () => {
        await mountPage();

        expect(localNames()).toEqual(['projects', 'rams', 'tools']);
        expect(wrapper.find('input[id="local-rams"]').element.checked).toBe(true);
        expect(wrapper.find('input[id="local-tools"]').element.checked).toBe(false);
        expect(wrapper.find('input[id="local-projects"]').exists()).toBe(false);
    });

    it('navigates into a folder and back via the breadcrumbs', async () => {
        await mountPage();

        await wrapper.findAll('details').at(1).findAll('li button.flex-1').at(0).trigger('click');
        await flushPromises();
        expect(localNames()).toEqual(['deep']);
        expect(wrapper.find('nav').text()).toContain('projects');

        await wrapper.find('nav').findAll('button').at(0).trigger('click'); // root
        await flushPromises();
        expect(localNames()).toEqual(['projects', 'rams', 'tools']);
    });

    it('builds cumulative breadcrumb paths', async () => {
        await mountPage();
        wrapper.vm.$store.commit('localRepos/SET_BROWSE_RESULT', { path: 'sinov8/rams', entries: [] });
        await wrapper.vm.$nextTick();

        expect(wrapper.vm.pathCrumbs).toEqual([
            { name: 'sinov8', path: 'sinov8' },
            { name: 'rams', path: 'sinov8/rams' },
        ]);
        expect(wrapper.text()).toContain('No subfolders here.');
    });

    it('connects the selected local repositories', async () => {
        const connected = vi.fn(() => ({}));
        await mountPage({ 'POST /api/local-repos/connect': connected });
        await wrapper.find('input[id="local-tools"]').setChecked(true);

        await buttonByText('Connect selected local repositories').trigger('click');
        await flushPromises();

        expect(connected).toHaveBeenCalledWith({ repositories: [{ path: 'rams' }, { path: 'tools' }] });
        expect(wrapper.text()).toContain('Connected — these repositories are now available');
    });

    it('removes a connected local repository, without the GitHub wording', async () => {
        const deleted = vi.fn(() => ({}));
        await mountPage({ 'DELETE /api/repo-configs/21': deleted });

        await wrapper.findAll('details').at(1).find('button[title="Remove from Boschifai"]').trigger('click');
        expect(wrapper.text()).toContain('"rams"');
        expect(wrapper.text()).not.toContain('GitHub authorization');

        await buttonByText('Remove').trigger('click');
        await flushPromises();

        expect(deleted).toHaveBeenCalled();
    });

    it('shows the API error when a folder cannot be browsed', async () => {
        await mountPage({ 'GET /api/local-repos/browse': () => { throw apiError('Path is outside the allowed root'); } });

        expect(wrapper.text()).toContain('Path is outside the allowed root');
    });
});
