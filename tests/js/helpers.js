import { createLocalVue } from '@vue/test-utils';
import Vuex from 'vuex';
import VueRouter from 'vue-router';
import { vi } from 'vitest';

/**
 * A local Vue with Vuex + VueRouter installed, a namespaced `runs` module whose getters read
 * from `runsState` and whose actions are vi.fn() spies (override any via `runsActions`), plus a
 * `repoConfigs` module — enough for the pages under test without the real API-backed store.
 */
export function makePageEnv({ runsState = {}, runsActions = {}, repoConfigs = [] } = {}) {
    const localVue = createLocalVue();
    localVue.use(Vuex);
    localVue.use(VueRouter);

    const actions = {
        fetchRun: vi.fn(() => Promise.resolve(runsState.current ?? null)),
        createRun: vi.fn(() => Promise.resolve({ id: 'new-run' })),
        decideGapAnalysis: vi.fn(() => Promise.resolve(null)),
        decidePush: vi.fn(() => Promise.resolve(null)),
        retryStep: vi.fn(() => Promise.resolve(null)),
        cancelRun: vi.fn(() => Promise.resolve(null)),
        rerunLocalExecution: vi.fn(() => Promise.resolve(null)),
        fixFailingTests: vi.fn(() => Promise.resolve(null)),
        archiveRun: vi.fn(() => Promise.resolve(null)),
        unarchiveRun: vi.fn(() => Promise.resolve(null)),
        ...runsActions,
    };

    const store = new Vuex.Store({
        modules: {
            runs: {
                namespaced: true,
                state: { current: null, list: [], ...runsState },
                getters: { current: (s) => s.current, list: (s) => s.list },
                // Vuex calls actions as (context, payload) — forward just the payload to the spy.
                actions: Object.fromEntries(Object.entries(actions).map(([name, spy]) => [name, (_ctx, payload) => spy(payload)])),
            },
            repoConfigs: {
                namespaced: true,
                state: { list: repoConfigs },
                getters: { list: (s) => s.list },
                actions: { fetchRepoConfigs: vi.fn() },
            },
        },
    });

    // 'abstract' keeps history in memory per router — hash mode would share jsdom's
    // window.location across tests, leaking one test's navigation into the next.
    const router = new VueRouter({
        mode: 'abstract',
        routes: [
            { path: '/runs', name: 'runs.index' },
            { path: '/runs/:id', name: 'runs.show' },
            { path: '/runs/:id/edit', name: 'runs.edit' },
            { path: '/settings/github', name: 'settings.github' },
        ],
    });

    return { localVue, store, router, actions };
}

/**
 * Lets pending promise callbacks (awaited store actions, etc.) run before asserting. Microtasks
 * only — no setTimeout — so it also works while a test has fake timers enabled.
 */
export async function flushPromises() {
    for (let i = 0; i < 20; i++) {
        await Promise.resolve();
    }
}

/**
 * A Vuex store built from the app's REAL store modules, each with a fresh deep copy of its
 * initial state — the modules export a shared state object, so using them directly would leak
 * state between tests.
 */
export function makeRealStore(localVue, modules) {
    return new Vuex.Store({
        modules: Object.fromEntries(
            Object.entries(modules).map(([name, module]) => {
                const initial = JSON.parse(JSON.stringify(module.state));
                return [name, { ...module, state: () => JSON.parse(JSON.stringify(initial)) }];
            }),
        ),
    });
}

/** A local Vue with Vuex + VueRouter, plus an in-memory router at `path` (with optional query). */
export function makeLocalVueWithRouter({ path = '/', query = {}, routes = [] } = {}) {
    const localVue = createLocalVue();
    localVue.use(Vuex);
    localVue.use(VueRouter);
    const router = new VueRouter({
        mode: 'abstract',
        routes: [
            { path: '/', name: 'home' },
            { path: '/runs', name: 'runs.index' },
            { path: '/runs/new', name: 'runs.create' },
            { path: '/runs/new/coverage', name: 'runs.create.coverage' },
            { path: '/runs/:id', name: 'runs.show' },
            { path: '/runs/:id/edit', name: 'runs.edit' },
            { path: '/settings/github', name: 'settings.github' },
            { path: '/tools-requirement', name: 'tools.requirement' },
            // Same names/paths as resources/js/router/index.js, without the page components.
            { path: '/standalone/building-skills', name: 'standalone.building-skills' },
            { path: '/standalone/building-knowledge-base', name: 'standalone.building-knowledge-base' },
            { path: '/reporting/confidence-reports', name: 'reporting.confidence' },
            { path: '/reporting/coverage-reports', name: 'reporting.coverage' },
            { path: '/reporting/test-history', name: 'reporting.test-history' },
            { path: '/reporting/token-usage', name: 'reporting.token-usage' },
            { path: '/login', name: 'login', meta: { guest: true } },
            { path: '/forgot-password', name: 'password.forgot', meta: { guest: true } },
            { path: '/reset-password/:token', name: 'password.reset', meta: { guest: true } },
            { path: '/account', name: 'account' },
            ...routes,
        ],
    });
    router.push({ path, query }).catch(() => {});

    return { localVue, router };
}

/**
 * Routes window.axios calls to handlers keyed by "METHOD url" (exact) — each handler gets the
 * request (url, body/config) and returns the response `data`, or throws to simulate an error.
 * Unrouted calls fail loudly so a test never silently depends on an unexpected request.
 */
export function mockApi(routes) {
    const respond = (method) => (url, arg) => {
        const handler = routes[`${method} ${url}`];
        if (!handler) return Promise.reject(new Error(`Unexpected ${method} ${url}`));
        try {
            return Promise.resolve({ data: typeof handler === 'function' ? handler(arg) : handler });
        } catch (e) {
            return Promise.reject(e);
        }
    };
    window.axios.get.mockImplementation(respond('GET'));
    window.axios.post.mockImplementation(respond('POST'));
    window.axios.delete.mockImplementation(respond('DELETE'));
}

export function apiError(message) {
    return Object.assign(new Error('Request failed'), { response: { data: { message } } });
}
