import { describe, expect, it, vi } from 'vitest';
import runs from '@/store/modules/runs';

/** Runs an action with spy commit/dispatch, the same way Vuex would call it. */
async function run(actionName, payload, { state = {} } = {}) {
    const commit = vi.fn();
    const dispatch = vi.fn(() => Promise.resolve('dispatched'));
    const result = await runs.actions[actionName]({ commit, dispatch, state }, payload);

    return { commit, dispatch, result };
}

function apiError(message) {
    return Object.assign(new Error('Request failed'), { response: { data: { message } } });
}

describe('runs store mutations', () => {
    it('removes a deleted run from the list', () => {
        const state = { list: [{ id: 1 }, { id: 2 }] };
        runs.mutations.REMOVE_FROM_LIST(state, 1);

        expect(state.list).toEqual([{ id: 2 }]);
    });
});

describe('runs store actions', () => {
    it('fetchRuns loads the list and toggles loading', async () => {
        window.axios.get.mockResolvedValue({ data: { data: [{ id: 1 }] } });

        const { commit } = await run('fetchRuns');

        expect(window.axios.get).toHaveBeenCalledWith('/api/runs', { params: {} });
        expect(commit.mock.calls).toEqual([
            ['SET_LOADING', true],
            ['SET_ERROR', null],
            ['SET_LIST', [{ id: 1 }]],
            ['SET_LOADING', false],
        ]);
    });

    it('fetchRuns asks for archived runs when requested', async () => {
        window.axios.get.mockResolvedValue({ data: { data: [] } });

        await run('fetchRuns', { archived: true });

        expect(window.axios.get).toHaveBeenCalledWith('/api/runs', { params: { archived: 1 } });
    });

    it('fetchRuns records the API error message and still stops loading', async () => {
        window.axios.get.mockRejectedValue(apiError('Server exploded'));

        const { commit } = await run('fetchRuns');

        expect(commit).toHaveBeenCalledWith('SET_ERROR', 'Server exploded');
        expect(commit).toHaveBeenLastCalledWith('SET_LOADING', false);
    });

    it('fetchRun stores and returns the run', async () => {
        window.axios.get.mockResolvedValue({ data: { data: { id: 7, state: 'failed' } } });

        const { commit, result } = await run('fetchRun', 7);

        expect(window.axios.get).toHaveBeenCalledWith('/api/runs/7');
        expect(commit).toHaveBeenCalledWith('SET_CURRENT', { id: 7, state: 'failed' });
        expect(result).toEqual({ id: 7, state: 'failed' });
    });

    it('fetchRun falls back to the generic error message', async () => {
        window.axios.get.mockRejectedValue(new Error('Network Error'));

        const { commit, result } = await run('fetchRun', 7);

        expect(commit).toHaveBeenCalledWith('SET_ERROR', 'Network Error');
        expect(result).toBeUndefined();
    });

    it('createRun posts the payload and returns the new run', async () => {
        window.axios.post.mockResolvedValue({ data: { data: { id: 'new' } } });

        const { result } = await run('createRun', { requirement_text: 'x' });

        expect(window.axios.post).toHaveBeenCalledWith('/api/runs', { requirement_text: 'x' });
        expect(result).toEqual({ id: 'new' });
    });

    it('createRun records and rethrows a validation error', async () => {
        window.axios.post.mockRejectedValue(apiError('The requirement text field is required.'));

        await expect(run('createRun', {})).rejects.toThrow();
    });

    it.each([
        ['decideGapAnalysis', { id: 3, decision: 'changes_requested', comment: 'More cases' }, '/api/runs/3/approvals/gap-analysis', { decision: 'changes_requested', comment: 'More cases' }],
        ['decidePush', { id: 3, decision: 'approved' }, '/api/runs/3/approvals/push', { decision: 'approved', comment: undefined }],
    ])('%s posts the decision and refetches the run', async (action, payload, url, body) => {
        const { dispatch, result } = await run(action, payload);

        expect(window.axios.post).toHaveBeenCalledWith(url, body);
        expect(dispatch).toHaveBeenCalledWith('fetchRun', 3);
        expect(result).toBe('dispatched');
    });

    it.each([
        ['retryStep', { id: 3, stepId: 9 }, '/api/runs/3/steps/9/retry'],
        ['cancelRun', 3, '/api/runs/3/cancel'],
        ['rerunLocalExecution', 3, '/api/runs/3/local-execution/rerun'],
        ['fixFailingTests', 3, '/api/runs/3/local-execution/fix-failing-tests'],
    ])('%s posts to its endpoint and refetches the run', async (action, payload, url) => {
        const { dispatch } = await run(action, payload);

        expect(window.axios.post).toHaveBeenCalledWith(url);
        expect(dispatch).toHaveBeenCalledWith('fetchRun', 3);
    });

    it('deleteRun deletes and drops the run from the list', async () => {
        const { commit } = await run('deleteRun', 4);

        expect(window.axios.delete).toHaveBeenCalledWith('/api/runs/4');
        expect(commit).toHaveBeenCalledWith('REMOVE_FROM_LIST', 4);
    });

    it.each(['archiveRun', 'unarchiveRun'])('%s updates the current run', async (action) => {
        window.axios.post.mockResolvedValue({ data: { data: { id: 5, archived_at: 'x' } } });

        const { commit, result } = await run(action, 5);

        expect(window.axios.post).toHaveBeenCalledWith(`/api/runs/5/${action === 'archiveRun' ? 'archive' : 'unarchive'}`);
        expect(commit).toHaveBeenCalledWith('SET_CURRENT', { id: 5, archived_at: 'x' });
        expect(result).toEqual({ id: 5, archived_at: 'x' });
    });

    it.each(['archiveRun', 'unarchiveRun'])('%s records and rethrows a conflict', async (action) => {
        window.axios.post.mockRejectedValue(apiError('Repo already has an active run'));
        const commit = vi.fn();

        await expect(runs.actions[action]({ commit }, 5)).rejects.toThrow();
        expect(commit).toHaveBeenCalledWith('SET_ERROR', 'Repo already has an active run');
    });
});
