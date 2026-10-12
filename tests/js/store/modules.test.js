import { describe, expect, it, vi } from 'vitest';
import repoConfigs from '@/store/modules/repoConfigs';
import localRepos from '@/store/modules/localRepos';
import usage from '@/store/modules/usage';
import github from '@/store/modules/github';

function context() {
    return { commit: vi.fn(), dispatch: vi.fn(() => Promise.resolve()) };
}

function apiError(message) {
    return Object.assign(new Error('Request failed'), { response: { data: { message } } });
}

const REFRESH_REPOS = ['repoConfigs/fetchRepoConfigs', null, { root: true }];

describe('repoConfigs store', () => {
    it('loads the connected repositories', async () => {
        window.axios.get.mockResolvedValue({ data: { data: [{ id: 1 }] } });
        const ctx = context();

        await repoConfigs.actions.fetchRepoConfigs(ctx);

        expect(window.axios.get).toHaveBeenCalledWith('/api/repo-configs');
        expect(ctx.commit).toHaveBeenCalledWith('SET_LIST', [{ id: 1 }]);
        expect(ctx.commit).toHaveBeenLastCalledWith('SET_LOADING', false);
    });

    it('records a load failure', async () => {
        window.axios.get.mockRejectedValue(apiError('Forbidden'));
        const ctx = context();

        await repoConfigs.actions.fetchRepoConfigs(ctx);

        expect(ctx.commit).toHaveBeenCalledWith('SET_ERROR', 'Forbidden');
    });

    it('deletes a repository and drops it from the list', async () => {
        const ctx = context();
        await repoConfigs.actions.deleteRepoConfig(ctx, 3);

        expect(window.axios.delete).toHaveBeenCalledWith('/api/repo-configs/3');
        expect(ctx.commit).toHaveBeenCalledWith('REMOVE_FROM_LIST', 3);

        const state = { list: [{ id: 3 }, { id: 4 }] };
        repoConfigs.mutations.REMOVE_FROM_LIST(state, 3);
        expect(state.list).toEqual([{ id: 4 }]);
    });

    it('rethrows a failed delete so the page can react', async () => {
        window.axios.delete.mockRejectedValue(apiError('Repo has active runs'));
        const ctx = context();

        await expect(repoConfigs.actions.deleteRepoConfig(ctx, 3)).rejects.toThrow();
        expect(ctx.commit).toHaveBeenCalledWith('SET_ERROR', 'Repo has active runs');
    });
});

describe('localRepos store', () => {
    it('browses a folder and stores the result', async () => {
        const result = { path: 'projects', entries: [{ name: 'api' }] };
        window.axios.get.mockResolvedValue({ data: result });
        const ctx = context();

        await expect(localRepos.actions.browse(ctx, 'projects')).resolves.toEqual(result);

        expect(window.axios.get).toHaveBeenCalledWith('/api/local-repos/browse', { params: { path: 'projects' } });
        expect(ctx.commit).toHaveBeenCalledWith('SET_BROWSE_RESULT', result);

        const state = {};
        localRepos.mutations.SET_BROWSE_RESULT(state, result);
        expect(state).toEqual({ path: 'projects', entries: [{ name: 'api' }] });
    });

    it('browses the root by default and rethrows failures', async () => {
        window.axios.get.mockRejectedValue(apiError('Not a directory'));
        const ctx = context();

        await expect(localRepos.actions.browse(ctx)).rejects.toThrow();

        expect(window.axios.get).toHaveBeenCalledWith('/api/local-repos/browse', { params: { path: '' } });
        expect(ctx.commit).toHaveBeenCalledWith('SET_ERROR', 'Not a directory');
        expect(ctx.commit).toHaveBeenLastCalledWith('SET_LOADING', false);
    });

    it('connects repositories and refreshes the repo list', async () => {
        const ctx = context();
        await localRepos.actions.connectRepositories(ctx, ['/repos/api']);

        expect(window.axios.post).toHaveBeenCalledWith('/api/local-repos/connect', { repositories: ['/repos/api'] });
        expect(ctx.dispatch).toHaveBeenCalledWith(...REFRESH_REPOS);
    });

    it('rethrows a failed connect', async () => {
        window.axios.post.mockRejectedValue(apiError('Not a git repo'));
        const ctx = context();

        await expect(localRepos.actions.connectRepositories(ctx, ['/x'])).rejects.toThrow();
        expect(ctx.dispatch).not.toHaveBeenCalled();
    });
});

describe('usage store', () => {
    it.each([
        ['fetchTokensThisMonth', '/api/usage/tokens-this-month', 'SET_TOKENS_THIS_MONTH'],
        ['fetchDetails', '/api/usage/tokens-this-month/details', 'SET_DETAILS'],
    ])('%s loads and stores the usage', async (action, url, mutation) => {
        window.axios.get.mockResolvedValue({ data: { tokens: 515402 } });
        const ctx = context();

        await expect(usage.actions[action](ctx)).resolves.toEqual({ tokens: 515402 });

        expect(window.axios.get).toHaveBeenCalledWith(url);
        expect(ctx.commit).toHaveBeenCalledWith(mutation, { tokens: 515402 });
    });

    it.each(['fetchTokensThisMonth', 'fetchDetails'])('%s records a failure without throwing', async (action) => {
        window.axios.get.mockRejectedValue(new Error('Network Error'));
        const ctx = context();

        await expect(usage.actions[action](ctx)).resolves.toBeUndefined();
        expect(ctx.commit).toHaveBeenCalledWith('SET_ERROR', 'Network Error');
    });
});

describe('github store', () => {
    it('loads connections, recording a failure without throwing', async () => {
        window.axios.get.mockResolvedValueOnce({ data: { data: [{ id: 1 }] } });
        const ctx = context();
        await expect(github.actions.fetchConnections(ctx)).resolves.toEqual([{ id: 1 }]);
        expect(ctx.commit).toHaveBeenCalledWith('SET_CONNECTIONS', [{ id: 1 }]);

        window.axios.get.mockRejectedValueOnce(apiError('Token expired'));
        await expect(github.actions.fetchConnections(ctx)).resolves.toBeUndefined();
        expect(ctx.commit).toHaveBeenCalledWith('SET_ERROR', 'Token expired');
    });

    it('lists a connection’s repositories with excluded organisations', async () => {
        window.axios.get.mockResolvedValue({ data: { data: [{ name: 'api' }], excluded_organizations: ['old-org'] } });

        await expect(github.actions.fetchRepositories(context(), 7)).resolves.toEqual({
            repositories: [{ name: 'api' }],
            excludedOrganizations: ['old-org'],
        });
        expect(window.axios.get).toHaveBeenCalledWith('/api/github/connections/7/repositories');
    });

    it('defaults excluded organisations to an empty list', async () => {
        window.axios.get.mockResolvedValue({ data: { data: [] } });

        await expect(github.actions.fetchRepositories(context(), 7)).resolves.toEqual({ repositories: [], excludedOrganizations: [] });
    });

    it('connects repositories and refreshes the repo list', async () => {
        const ctx = context();
        await github.actions.connectRepositories(ctx, { connectionId: 7, repositories: ['acme/api'] });

        expect(window.axios.post).toHaveBeenCalledWith('/api/github/connections/7/repositories', { repositories: ['acme/api'] });
        expect(ctx.dispatch).toHaveBeenCalledWith(...REFRESH_REPOS);
    });

    it('excludes an organisation (URL-encoded) and refreshes the repo list', async () => {
        const ctx = context();
        await github.actions.excludeOrganization(ctx, { connectionId: 7, organization: 'acme corp' });

        expect(window.axios.delete).toHaveBeenCalledWith('/api/github/connections/7/organizations/acme%20corp');
        expect(ctx.dispatch).toHaveBeenCalledWith(...REFRESH_REPOS);
    });

    it('restores an organisation', async () => {
        await github.actions.restoreOrganization(context(), { connectionId: 7, organization: 'acme' });

        expect(window.axios.post).toHaveBeenCalledWith('/api/github/connections/7/organizations/acme/restore');
    });

    it.each([
        ['fetchRepositories', 7, 'get'],
        ['connectRepositories', { connectionId: 7, repositories: [] }, 'post'],
        ['excludeOrganization', { connectionId: 7, organization: 'a' }, 'delete'],
        ['restoreOrganization', { connectionId: 7, organization: 'a' }, 'post'],
    ])('%s records and rethrows a failure', async (action, payload, method) => {
        window.axios[method].mockRejectedValue(apiError('GitHub said no'));
        const ctx = context();

        await expect(github.actions[action](ctx, payload)).rejects.toThrow();
        expect(ctx.commit).toHaveBeenCalledWith('SET_ERROR', 'GitHub said no');
    });
});

describe('store getters and simple mutations', () => {
    it.each([
        ['repoConfigs', repoConfigs],
        ['localRepos', localRepos],
        ['usage', usage],
        ['github', github],
    ])('%s exposes its state through getters and SET_ERROR', (_name, module) => {
        const state = JSON.parse(JSON.stringify(module.state));
        module.mutations.SET_ERROR(state, 'boom');

        expect(module.getters.error(state)).toBe('boom');
        Object.entries(module.getters).forEach(([key, getter]) => expect(getter(state)).toBe(state[key]));
    });
});
