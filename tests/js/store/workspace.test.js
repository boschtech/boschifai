import { describe, expect, it, vi } from 'vitest';
import workspace from '@/store/modules/workspace';
import { apiError, mockApi } from '../helpers';

const WORKSPACE = {
    id: 10, name: 'Acme',
    members: [{ id: 1, name: 'Ops', email: 'ops@example.com', role: 'owner' }],
    invitations: [{ id: 5, email: 'new@example.com', role: 'member' }],
};

function context() {
    return { commit: vi.fn(), dispatch: vi.fn(() => Promise.resolve()) };
}

describe('workspace store', () => {
    it('exposes the workspace, its members and invitations', () => {
        const state = { ...workspace.state, workspace: WORKSPACE };

        expect(workspace.getters.members(state)).toEqual(WORKSPACE.members);
        expect(workspace.getters.invitations(state)).toEqual(WORKSPACE.invitations);
        expect(workspace.getters.members({ workspace: null })).toEqual([]);
        expect(workspace.getters.invitations({ workspace: null })).toEqual([]);
    });

    it('loads the workspace', async () => {
        mockApi({ 'GET /api/workspace': { data: WORKSPACE } });
        const ctx = context();

        await workspace.actions.fetchWorkspace(ctx);

        expect(ctx.commit.mock.calls).toEqual([['SET_LOADING', true], ['SET_ERROR', null], ['SET_WORKSPACE', WORKSPACE], ['SET_LOADING', false]]);
    });

    it('records a load failure', async () => {
        mockApi({ 'GET /api/workspace': () => { throw apiError('Only workspace admins and owners can do this.'); } });
        const ctx = context();

        await workspace.actions.fetchWorkspace(ctx);

        expect(ctx.commit).toHaveBeenCalledWith('SET_ERROR', 'Only workspace admins and owners can do this.');
    });

    it('renames the workspace', async () => {
        const patched = vi.fn(() => ({ data: { ...WORKSPACE, name: 'Acme Rentals' } }));
        mockApi({ 'PATCH /api/workspace': patched });
        const ctx = context();

        await workspace.actions.rename(ctx, 'Acme Rentals');

        expect(patched).toHaveBeenCalledWith({ name: 'Acme Rentals' });
        expect(ctx.commit).toHaveBeenCalledWith('SET_WORKSPACE', expect.objectContaining({ name: 'Acme Rentals' }));
    });

    it.each([
        ['invite', { email: 'a@b.co', role: 'admin' }, 'POST /api/workspace/invitations', { email: 'a@b.co', role: 'admin' }],
        ['revokeInvitation', 5, 'DELETE /api/workspace/invitations/5', undefined],
        ['changeRole', { memberId: 2, role: 'admin' }, 'PATCH /api/workspace/members/2', { role: 'admin' }],
        ['removeMember', 2, 'DELETE /api/workspace/members/2', undefined],
    ])('%s calls the API and reloads the workspace', async (action, payload, route, body) => {
        const handler = vi.fn(() => ({}));
        mockApi({ [route]: handler });
        const ctx = context();

        await workspace.actions[action](ctx, payload);

        expect(handler).toHaveBeenCalledWith(body);
        expect(ctx.dispatch).toHaveBeenCalledWith('fetchWorkspace');
    });

    it('lets errors reach the page', async () => {
        mockApi({ 'POST /api/workspace/invitations': () => { throw apiError('already a member'); } });

        await expect(workspace.actions.invite(context(), { email: 'x', role: 'member' })).rejects.toThrow();
    });
});
