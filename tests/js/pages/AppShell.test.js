import { afterEach, describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import App from '@/App.vue';
import WorkspaceSwitcher from '@/components/WorkspaceSwitcher.vue';
import SidebarNav from '@/components/SidebarNav.vue';
import ThemeToggle from '@/components/ThemeToggle.vue';
import TokenUsageBadge from '@/components/TokenUsageBadge.vue';
import HomePage from '@/pages/HomePage.vue';
import ToolsRequirementPage from '@/pages/ToolsRequirementPage.vue';
import StandaloneActionCreatePage from '@/pages/StandaloneActionCreatePage.vue';
import usage from '@/store/modules/usage';
import auth from '@/store/modules/auth';
import runs from '@/store/modules/runs';
import repoConfigs from '@/store/modules/repoConfigs';
import { apiError, flushPromises, makeLocalVueWithRouter, makeRealStore, mockApi, signedInUser } from '../helpers';

const USAGE = {
    month: 'October 2026', total_tokens: 515402, total_cost_usd: 0.5612,
    input_tokens: 1200, output_tokens: 3400, cache_creation_input_tokens: 10000, cache_read_input_tokens: 500802,
};

let wrapper;

async function mountWith(Component, { path = '/', api = {}, modules = {}, propsData, stubs } = {}) {
    mockApi(api);
    const { localVue, router } = makeLocalVueWithRouter({ path });
    const store = makeRealStore(localVue, modules);
    wrapper = mount(Component, { localVue, store, router, propsData, stubs, attachTo: document.body });
    await flushPromises();

    return { router, store };
}

afterEach(() => {
    wrapper?.destroy();
    wrapper = undefined;
    document.documentElement.classList.remove('dark');
    document.documentElement.style.removeProperty('--app-header-height');
});

describe('TokenUsageBadge', () => {
    it('shows this month’s tokens and cost, linking to the usage page', async () => {
        await mountWith(TokenUsageBadge, { api: { 'GET /api/usage/tokens-this-month': USAGE }, modules: { usage } });

        expect(wrapper.text()).toBe('515,402 tokens · $0.56 this month');
        expect(wrapper.attributes('href')).toBe('/reporting/token-usage');
        expect(wrapper.attributes('title')).toContain('October 2026');
        expect(wrapper.attributes('title')).toContain('500,802 cache read');
    });

    it('renders nothing until usage has loaded (or when it fails)', async () => {
        await mountWith(TokenUsageBadge, { api: { 'GET /api/usage/tokens-this-month': () => { throw apiError('down'); } }, modules: { usage } });

        expect(wrapper.html()).toBe('');
    });
});

describe('ThemeToggle', () => {
    it('starts from the theme already applied to the page', async () => {
        document.documentElement.classList.add('dark');
        await mountWith(ThemeToggle);

        expect(wrapper.attributes('title')).toBe('Switch to light mode');
    });

    it('switches theme, updating the page and remembering the choice', async () => {
        await mountWith(ThemeToggle);
        expect(wrapper.attributes('title')).toBe('Switch to dark mode');

        await wrapper.trigger('click');
        expect(document.documentElement.classList.contains('dark')).toBe(true);
        expect(localStorage.getItem('boschifai-theme')).toBe('dark');
        expect(wrapper.attributes('title')).toBe('Switch to light mode');

        await wrapper.trigger('click');
        expect(document.documentElement.classList.contains('dark')).toBe(false);
        expect(localStorage.getItem('boschifai-theme')).toBe('light');
    });
});

describe('SidebarNav', () => {
    it('groups every page into its section', async () => {
        await mountWith(SidebarNav, { path: '/runs' });

        expect(wrapper.findAll('h2').wrappers.map((h) => h.text())).toEqual(['Runs', 'Pipeline Actions', 'Standalone Actions', 'Reporting']);
        expect(wrapper.findAll('a').wrappers.map((a) => a.text())).toEqual([
            'All Runs', 'Write Requirement', 'Code Requirement', 'Tools Requirement',
            'Build Skills', 'Build Knowledge Base',
            'Confidence Reports', 'Coverage Reports', 'Test History', 'Token Usage',
        ]);
    });

    it('highlights only the current page', async () => {
        await mountWith(SidebarNav, { path: '/runs/new/coverage' });

        const active = wrapper.findAll('a.is-active');
        expect(active).toHaveLength(1);
        expect(active.at(0).text()).toBe('Code Requirement');
    });
});

describe('App', () => {
    it('renders the header, sidebar and the current page', async () => {
        await mountWith(App, {
            path: '/',
            api: { 'GET /api/usage/tokens-this-month': USAGE },
            modules: { usage, auth },
            stubs: { 'router-view': { template: '<div class="page">page content</div>' } },
        });
        wrapper.vm.$store.commit('auth/SET_USER', signedInUser());
        await wrapper.vm.$nextTick();

        expect(wrapper.text()).toContain('BoschifAI');
        expect(wrapper.text()).toContain('515,402 tokens');
        expect(wrapper.text()).toContain('Connect Repo');
        expect(wrapper.find('nav').exists()).toBe(true);
        expect(wrapper.find('.page').text()).toBe('page content');
    });

    it('publishes the header height for the sticky sidebar, and stops listening when destroyed', async () => {
        vi.spyOn(HTMLElement.prototype, 'offsetHeight', 'get').mockReturnValue(88);
        const removeListener = vi.spyOn(window, 'removeEventListener');
        await mountWith(App, { api: { 'GET /api/usage/tokens-this-month': USAGE }, modules: { usage, auth }, stubs: { 'router-view': true } });

        expect(document.documentElement.style.getPropertyValue('--app-header-height')).toBe('88px');

        window.dispatchEvent(new Event('resize'));
        wrapper.destroy();
        wrapper = undefined;

        expect(removeListener).toHaveBeenCalledWith('resize', expect.any(Function));
    });
});

describe('App — sign-in state', () => {
    const SIGNED_IN = { 'GET /api/usage/tokens-this-month': USAGE };

    it('shows the signed-in user with links to their account and to sign out', async () => {
        const { store } = await mountWith(App, { api: SIGNED_IN, modules: { usage, auth }, stubs: { 'router-view': true } });
        store.commit('auth/SET_USER', { id: 1, name: 'Ops Admin', email: 'ops@example.com' });
        await wrapper.vm.$nextTick();

        expect(wrapper.find('a[href="/account"]').text()).toBe('Ops Admin');
        expect(wrapper.findAll('button').filter((b) => b.text() === 'Sign out')).toHaveLength(1);
    });

    it('signs out and goes to the login page', async () => {
        const loggedOut = vi.fn(() => ({}));
        const { store, router } = await mountWith(App, { api: { ...SIGNED_IN, 'POST /logout': loggedOut }, modules: { usage, auth }, stubs: { 'router-view': true } });
        store.commit('auth/SET_USER', { id: 1, name: 'Ops Admin', email: 'ops@example.com' });
        await wrapper.vm.$nextTick();

        await wrapper.findAll('button').filter((b) => b.text() === 'Sign out').at(0).trigger('click');
        await flushPromises();

        expect(loggedOut).toHaveBeenCalled();
        expect(store.getters['auth/user']).toBeNull();
        expect(router.currentRoute.name).toBe('login');
    });

    it('renders guest pages on their own, without the header or sidebar', async () => {
        await mountWith(App, { path: '/login', modules: { usage, auth }, stubs: { 'router-view': { template: '<div class="page">login form</div>' } } });

        expect(wrapper.find('.page').text()).toBe('login form');
        expect(wrapper.find('header').exists()).toBe(false);
        expect(wrapper.find('nav').exists()).toBe(false);
        // The token badge (which calls the API) isn't mounted for guests.
        expect(window.axios.get).not.toHaveBeenCalled();
    });

    it('measures the header once the user moves from a guest page into the app', async () => {
        vi.spyOn(HTMLElement.prototype, 'offsetHeight', 'get').mockReturnValue(91);
        const { router } = await mountWith(App, { path: '/login', api: SIGNED_IN, modules: { usage, auth }, stubs: { 'router-view': true } });
        expect(document.documentElement.style.getPropertyValue('--app-header-height')).toBe('');

        await router.push('/runs');
        await flushPromises();

        expect(document.documentElement.style.getPropertyValue('--app-header-height')).toBe('91px');
    });
});

describe('App — workspace and role', () => {
    it('hides Connect Repo from members', async () => {
        const { store } = await mountWith(App, { api: { 'GET /api/usage/tokens-this-month': USAGE }, modules: { usage, auth }, stubs: { 'router-view': true } });
        store.commit('auth/SET_USER', signedInUser({ canManage: false }));
        await wrapper.vm.$nextTick();

        expect(wrapper.text()).not.toContain('Connect Repo');
        expect(wrapper.find('#workspace-switcher').exists()).toBe(true);
    });
});

describe('WorkspaceSwitcher', () => {
    async function mountSwitcher(user, api = {}) {
        const reload = vi.fn();
        const { store } = await mountWith(WorkspaceSwitcher, { api, modules: { auth }, propsData: { reload } });
        store.commit('auth/SET_USER', user);
        await wrapper.vm.$nextTick();

        return { store, reload };
    }

    it('shows just the workspace name when the user has one workspace', async () => {
        await mountSwitcher(signedInUser({ workspaces: [{ id: 10, name: 'Acme', role: 'admin' }] }));

        expect(wrapper.find('select').exists()).toBe(false);
        expect(wrapper.text()).toBe('Acme');
    });

    it('lists the user’s workspaces with the current one selected', async () => {
        await mountSwitcher(signedInUser());

        expect(wrapper.findAll('option').wrappers.map((o) => o.text())).toEqual(['Acme', 'Beta']);
        expect(wrapper.find('select').element.value).toBe('10');
    });

    it('switches workspace and reloads the app so nothing from the old one lingers', async () => {
        const switched = vi.fn(() => ({ data: { ...signedInUser(), current_workspace: { id: 20, name: 'Beta', role: 'member', can_manage: false } } }));
        const { store, reload } = await mountSwitcher(signedInUser(), { 'POST /api/workspaces/20/switch': switched });

        await wrapper.find('select').setValue('20');
        await flushPromises();

        expect(switched).toHaveBeenCalled();
        expect(store.getters['auth/currentWorkspace'].name).toBe('Beta');
        expect(reload).toHaveBeenCalledTimes(1);
    });

    it('does nothing when the current workspace is picked again, and stays put if switching fails', async () => {
        const { reload } = await mountSwitcher(signedInUser(), { 'POST /api/workspaces/20/switch': () => { throw apiError('nope'); } });

        await wrapper.vm.switchTo(10);
        expect(window.axios.post).not.toHaveBeenCalled();

        await expect(wrapper.vm.switchTo(20)).rejects.toThrow();
        expect(reload).not.toHaveBeenCalled();
        expect(wrapper.find('select').attributes('disabled')).toBeUndefined();
    });

    it('renders nothing before the user is known', async () => {
        await mountWith(WorkspaceSwitcher, { modules: { auth } });

        expect(wrapper.html()).toBe('');
    });
});

describe('HomePage and ToolsRequirementPage', () => {
    it('links to both ways of starting a run', async () => {
        await mountWith(HomePage);

        const hrefs = wrapper.findAll('a').wrappers.map((a) => a.attributes('href'));
        expect(hrefs).toEqual(expect.arrayContaining(['/runs/new', '/runs/new/coverage', '/runs', '/settings/github']));
        expect(wrapper.text()).toContain('How a run flows');
    });

    it('explains that tool integrations are coming soon', async () => {
        await mountWith(ToolsRequirementPage);

        expect(wrapper.text()).toContain('coming soon');
        expect(wrapper.find('img').attributes('src')).toBe('/images/software-update-in-progress.png');
    });
});

describe('StandaloneActionCreatePage', () => {
    const PROPS = { runType: 'build_skills', title: 'Build Skills', description: 'Reads the repo.', submitLabel: 'Build skills' };
    const REPOS = { 'GET /api/repo-configs': { data: [{ id: 1, display_name: 'acme/api' }, { id: 2, display_name: 'acme/web' }] } };

    it('needs a repository before it can start', async () => {
        await mountWith(StandaloneActionCreatePage, { api: REPOS, modules: { runs, repoConfigs }, propsData: PROPS });

        expect(wrapper.text()).toContain('Build Skills');
        expect(wrapper.text()).toContain('Reads the repo.');
        expect(wrapper.findAll('option').wrappers.map((o) => o.text())).toEqual(['Select a repository…', 'acme/api', 'acme/web']);
        expect(wrapper.find('button[type="submit"]').attributes('disabled')).toBeDefined();
    });

    it('starts the action and opens the new run', async () => {
        const created = vi.fn(() => ({ data: { id: 'run-5' } }));
        const { router } = await mountWith(StandaloneActionCreatePage, {
            api: { ...REPOS, 'POST /api/runs': created },
            modules: { runs, repoConfigs },
            propsData: PROPS,
        });

        await wrapper.find('select').setValue('2');
        await wrapper.find('form').trigger('submit');
        await flushPromises();

        expect(created).toHaveBeenCalledWith({ run_type: 'build_skills', repo_config_id: 2 });
        expect(router.currentRoute.params.id).toBe('run-5');
    });

    it('shows why the action could not start', async () => {
        await mountWith(StandaloneActionCreatePage, {
            api: { ...REPOS, 'POST /api/runs': () => { throw apiError('acme/api already has a run in progress'); } },
            modules: { runs, repoConfigs },
            propsData: PROPS,
        });

        await wrapper.find('select').setValue('1');
        await wrapper.find('form').trigger('submit');
        await flushPromises();

        expect(wrapper.text()).toContain('acme/api already has a run in progress');
        expect(wrapper.find('button[type="submit"]').text()).toBe('Build skills');
    });
});
