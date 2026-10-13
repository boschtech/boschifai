import { afterEach, describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import WorkspaceSettingsPage from '@/pages/WorkspaceSettingsPage.vue';
import AcceptInvitationPage from '@/pages/auth/AcceptInvitationPage.vue';
import SidebarNav from '@/components/SidebarNav.vue';
import SelectDropdown from '@/components/SelectDropdown.vue';
import auth from '@/store/modules/auth';
import workspace from '@/store/modules/workspace';
import { apiError, flushPromises, makeLocalVueWithRouter, makeRealStore, mockApi, signedInUser } from '../helpers';

const OWNER = { id: 1, name: 'Ops Owner', email: 'owner@example.com', role: 'owner' };
const ADMIN = { id: 2, name: 'Ada Admin', email: 'admin@example.com', role: 'admin' };
const DEV = { id: 3, name: 'Dev Member', email: 'dev@example.com', role: 'member' };

function workspaceData(overrides = {}) {
    return {
        id: 10, name: 'Acme',
        members: [ADMIN, DEV, OWNER],
        invitations: [
            { id: 5, email: 'new@example.com', role: 'member', invited_by: 'Ada Admin', expires_at: '2026-10-20T10:00:00Z' },
            { id: 6, email: 'boss@example.com', role: 'owner', invited_by: 'Ops Owner', expires_at: '2026-10-20T10:00:00Z' },
        ],
        ...overrides,
    };
}

function validationError(errors) {
    return Object.assign(new Error('invalid'), { response: { status: 422, data: { message: 'invalid', errors } } });
}

let wrapper;

async function mountSettings({ role = 'admin', api = {}, data = workspaceData() } = {}) {
    let current = data;
    mockApi({ 'GET /api/workspace': () => ({ data: current }), ...api });
    const { localVue, router } = makeLocalVueWithRouter({ path: '/settings/workspace' });
    const store = makeRealStore(localVue, { auth, workspace });
    const me = role === 'owner' ? OWNER : ADMIN;
    store.commit('auth/SET_USER', { ...signedInUser(), id: me.id, current_workspace: { id: 10, name: 'Acme', role, can_manage: true } });
    wrapper = mount(WorkspaceSettingsPage, { localVue, store, router, attachTo: document.body });
    await flushPromises();

    return { store, setData: (d) => { current = d; } };
}

function member(email) {
    return wrapper.find(`[data-member="${email}"]`);
}

function removeButton(email) {
    return member(email).findAll('button').filter((b) => b.text() === 'Remove').at(0);
}

function roleMenu(email) {
    return member(email).findComponent(SelectDropdown);
}

async function optionLabels(menu) {
    await menu.find('button').trigger('click');
    const labels = menu.findAll('[role="option"]').wrappers.map((o) => o.text());
    await menu.find('button').trigger('click');
    return labels;
}

async function pick(menu, label) {
    await menu.find('button').trigger('click');
    await menu.findAll('[role="option"]').filter((o) => o.text() === label).at(0).trigger('click');
}

afterEach(() => {
    wrapper?.destroy();
    wrapper = undefined;
});

describe('WorkspaceSettingsPage', () => {
    it('lists members and pending invitations', async () => {
        await mountSettings();

        expect(wrapper.find('#workspace-name').element.value).toBe('Acme');
        expect(wrapper.findAll('[data-member]').wrappers.map((li) => li.attributes('data-member'))).toEqual(['admin@example.com', 'dev@example.com', 'owner@example.com']);
        expect(member('admin@example.com').text()).toContain('(you)');
        expect(wrapper.find('[data-invitation="new@example.com"]').text()).toContain('invited by Ada Admin');
    });

    it('lets an admin manage members and admins, but not owners', async () => {
        await mountSettings({ role: 'admin' });

        expect(await optionLabels(wrapper.findComponent(SelectDropdown))).toEqual(['Member', 'Admin']);
        expect(roleMenu('dev@example.com').exists()).toBe(true);
        expect(roleMenu('owner@example.com').exists()).toBe(false);
        expect(member('owner@example.com').text()).toContain('Owner');
        expect(member('owner@example.com').findAll('button').length).toBe(0);
        // An owner invitation can only be revoked by an owner.
        expect(wrapper.find('[data-invitation="boss@example.com"] button').exists()).toBe(false);
    });

    it('lets an owner grant and manage the owner role', async () => {
        await mountSettings({ role: 'owner' });

        expect(await optionLabels(wrapper.findComponent(SelectDropdown))).toEqual(['Member', 'Admin', 'Owner']);
        expect(roleMenu('owner@example.com').exists()).toBe(true);
        expect(wrapper.find('[data-invitation="boss@example.com"] button').exists()).toBe(true);
    });

    it('renames the workspace', async () => {
        const patched = vi.fn(() => ({ data: workspaceData({ name: 'Acme Rentals' }) }));
        await mountSettings({ api: { 'PATCH /api/workspace': patched } });

        expect(wrapper.findAll('button').filter((b) => b.text() === 'Rename').at(0).attributes('disabled')).toBeDefined();
        await wrapper.find('#workspace-name').setValue('  Acme Rentals ');
        await wrapper.findAll('form').at(0).trigger('submit');
        await flushPromises();

        expect(patched).toHaveBeenCalledWith({ name: 'Acme Rentals' });
        expect(wrapper.find('#workspace-name').element.value).toBe('Acme Rentals');
    });

    it('shows why a rename was refused', async () => {
        await mountSettings({ api: { 'PATCH /api/workspace': () => { throw validationError({ name: ['The name field must not be greater than 100 characters.'] }); } } });

        await wrapper.find('#workspace-name').setValue('x');
        await wrapper.findAll('form').at(0).trigger('submit');
        await flushPromises();

        expect(wrapper.find('[role="alert"]').text()).toBe('The name field must not be greater than 100 characters.');
    });

    it('sends an invitation and confirms it', async () => {
        const posted = vi.fn(() => ({}));
        await mountSettings({ api: { 'POST /api/workspace/invitations': posted } });

        await wrapper.find('#invite-email').setValue(' new.person@agency.co.za ');
        await pick(wrapper.findComponent(SelectDropdown), 'Admin');
        expect(wrapper.find('#invite-role').text()).toBe('Admin');
        await wrapper.findAll('form').at(1).trigger('submit');
        await flushPromises();

        expect(posted).toHaveBeenCalledWith({ email: 'new.person@agency.co.za', role: 'admin' });
        expect(wrapper.find('[role="status"]').text()).toBe('Invitation sent to new.person@agency.co.za.');
        expect(wrapper.find('#invite-email').element.value).toBe('');
    });

    it('explains why an invitation was refused', async () => {
        await mountSettings({ api: { 'POST /api/workspace/invitations': () => { throw validationError({ email: ['This person is already a member of the workspace.'] }); } } });

        await wrapper.find('#invite-email').setValue('dev@example.com');
        await wrapper.findAll('form').at(1).trigger('submit');
        await flushPromises();

        expect(wrapper.find('[role="alert"]').text()).toBe('This person is already a member of the workspace.');
        expect(wrapper.find('[role="status"]').exists()).toBe(false);
    });

    it('changes a role', async () => {
        const patched = vi.fn(() => ({}));
        await mountSettings({ api: { 'PATCH /api/workspace/members/3': patched } });

        expect(roleMenu('dev@example.com').text()).toBe('Member');
        await pick(roleMenu('dev@example.com'), 'Admin');
        await flushPromises();

        expect(patched).toHaveBeenCalledWith({ role: 'admin' });
    });

    it('puts the role menu back when a change is refused', async () => {
        await mountSettings({ role: 'owner', api: { 'PATCH /api/workspace/members/1': () => { throw validationError({ role: ['A workspace needs at least one owner. Make someone else an owner first.'] }); } } });

        await pick(roleMenu('owner@example.com'), 'Admin');
        await flushPromises();

        expect(wrapper.find('[role="alert"]').text()).toBe('A workspace needs at least one owner. Make someone else an owner first.');
        expect(roleMenu('owner@example.com').find('button').text()).toBe('Owner');
    });

    it('removes a member only after confirmation', async () => {
        const removed = vi.fn(() => ({}));
        await mountSettings({ api: { 'DELETE /api/workspace/members/3': removed } });

        await removeButton('dev@example.com').trigger('click');
        expect(wrapper.text()).toContain('Dev Member (dev@example.com) will lose access to this workspace.');

        await wrapper.findAll('button').filter((b) => b.text() === 'Cancel').at(0).trigger('click');
        expect(removed).not.toHaveBeenCalled();

        await removeButton('dev@example.com').trigger('click');
        // The dialog's button comes after every row's Remove button.
        const removeButtons = wrapper.findAll('button').filter((b) => b.text() === 'Remove');
        await removeButtons.at(removeButtons.length - 1).trigger('click');
        await flushPromises();

        expect(removed).toHaveBeenCalled();
    });

    it('revokes an invitation', async () => {
        const revoked = vi.fn(() => ({}));
        await mountSettings({ api: { 'DELETE /api/workspace/invitations/5': revoked } });

        await wrapper.find('[data-invitation="new@example.com"] button').trigger('click');
        await flushPromises();

        expect(revoked).toHaveBeenCalled();
    });

    it('shows a loading error, and hides the invitations section when there are none', async () => {
        mockApi({ 'GET /api/workspace': () => { throw apiError('Only workspace admins and owners can do this.'); } });
        const { localVue, router } = makeLocalVueWithRouter();
        const store = makeRealStore(localVue, { auth, workspace });
        wrapper = mount(WorkspaceSettingsPage, { localVue, store, router });
        await flushPromises();
        expect(wrapper.find('[role="alert"]').text()).toBe('Only workspace admins and owners can do this.');
        wrapper.destroy();

        await mountSettings({ data: workspaceData({ invitations: [] }) });
        expect(wrapper.text()).not.toContain('Pending invitations');
    });
});

describe('AcceptInvitationPage', () => {
    const INVITATION = { workspace: 'Acme', email: 'new@example.com', role: 'member', invited_by: 'Ada Admin', has_account: false, signed_in_as: null };

    async function mountAccept(invitation = INVITATION, api = {}) {
        const reload = vi.fn();
        mockApi({ 'GET /api/invitations/tok-1': typeof invitation === 'function' ? invitation : { data: invitation }, 'GET /sanctum/csrf-cookie': {}, ...api });
        const { localVue, router } = makeLocalVueWithRouter({ path: '/invitations/tok-1' });
        const store = makeRealStore(localVue, { auth });
        wrapper = mount(AcceptInvitationPage, { localVue, store, router, propsData: { token: 'tok-1', reload } });
        await flushPromises();

        return { reload, router };
    }

    it('lets someone new create their account and join', async () => {
        const accepted = vi.fn(() => ({ data: { workspace: 'Acme' } }));
        const { reload } = await mountAccept(INVITATION, { 'POST /api/invitations/tok-1/accept': accepted });

        expect(wrapper.find('p.mb-4').text()).toBe('new@example.com has been invited to join Acme as a member by Ada Admin.');

        await wrapper.find('#name').setValue('New Person');
        await wrapper.find('#password').setValue('a-brand-new-pass-77');
        await wrapper.find('#password_confirmation').setValue('a-brand-new-pass-77');
        await wrapper.find('form').trigger('submit');
        await flushPromises();

        expect(accepted).toHaveBeenCalledWith({ name: 'New Person', password: 'a-brand-new-pass-77', password_confirmation: 'a-brand-new-pass-77' });
        expect(reload).toHaveBeenCalledTimes(1);
    });

    it('lists what was wrong with a new account and clears the passwords', async () => {
        const { reload } = await mountAccept(INVITATION, {
            'POST /api/invitations/tok-1/accept': () => { throw validationError({ name: ['The name field is required.'], password: ['The password field must be at least 12 characters.'] }); },
        });

        await wrapper.find('#password').setValue('short');
        await wrapper.find('form').trigger('submit');
        await flushPromises();

        expect(wrapper.findAll('[role="alert"] li').wrappers.map((li) => li.text())).toEqual(['The name field is required.', 'The password field must be at least 12 characters.']);
        expect(wrapper.find('#password').element.value).toBe('');
        expect(reload).not.toHaveBeenCalled();
    });

    it('asks someone with an account to sign in first, coming back here afterwards', async () => {
        await mountAccept({ ...INVITATION, has_account: true });

        expect(wrapper.find('form').exists()).toBe(false);
        expect(wrapper.find('a').attributes('href')).toBe('/login?redirect=%2Finvitations%2Ftok-1');
    });

    it('joins with one click when signed in as the invited address', async () => {
        const accepted = vi.fn(() => ({ data: { workspace: 'Acme' } }));
        const { reload } = await mountAccept({ ...INVITATION, has_account: true, signed_in_as: 'new@example.com' }, { 'POST /api/invitations/tok-1/accept': accepted });

        await wrapper.findAll('button').filter((b) => b.text() === 'Join Acme').at(0).trigger('click');
        await flushPromises();

        expect(accepted).toHaveBeenCalledWith({});
        expect(reload).toHaveBeenCalled();
    });

    it('shows the error when joining fails', async () => {
        await mountAccept({ ...INVITATION, signed_in_as: 'new@example.com' }, { 'POST /api/invitations/tok-1/accept': () => { throw new Error('Network Error'); } });

        await wrapper.findAll('button').filter((b) => b.text() === 'Join Acme').at(0).trigger('click');
        await flushPromises();

        expect(wrapper.find('[role="alert"]').text()).toBe('Something went wrong. Please try again.');
    });

    it('explains when the visitor is signed in as someone else', async () => {
        await mountAccept({ ...INVITATION, signed_in_as: 'other@example.com' });

        expect(wrapper.find('[role="alert"]').text()).toContain("You're signed in as other@example.com, but this invitation is for new@example.com.");
        expect(wrapper.find('form').exists()).toBe(false);
        expect(wrapper.find('button').exists()).toBe(false);
    });

    it('explains an expired or unknown link', async () => {
        await mountAccept(() => { throw Object.assign(new Error('404'), { response: { status: 404, data: { message: 'This invitation link is no longer valid. Ask a workspace admin to send a new one.' } } }); });

        expect(wrapper.find('[role="alert"]').text()).toContain('This invitation link is no longer valid.');
        expect(wrapper.find('a[href="/"]').exists()).toBe(true);
    });

    it('describes the invitation without an inviter name when there is none', async () => {
        await mountAccept({ ...INVITATION, role: 'admin', invited_by: null });

        expect(wrapper.find('p.mb-4').text()).toBe('new@example.com has been invited to join Acme as an admin.');
    });
});

describe('SidebarNav — settings', () => {
    async function mountNav(canManage) {
        const { localVue, router } = makeLocalVueWithRouter({ path: '/runs' });
        const store = makeRealStore(localVue, { auth });
        store.commit('auth/SET_USER', signedInUser({ canManage }));
        wrapper = mount(SidebarNav, { localVue, store, router });
        await flushPromises();
    }

    it('shows the Workspace settings link to admins and owners only', async () => {
        await mountNav(true);
        expect(wrapper.find('a[href="/settings/workspace"]').text()).toBe('Workspace');
        expect(wrapper.find('a[href="/billing"]').text()).toBe('Billing');
        expect(wrapper.find('a[href="/settings/ai-provider"]').text()).toBe('AI provider');
        wrapper.destroy();

        await mountNav(false);
        expect(wrapper.find('a[href="/settings/workspace"]').exists()).toBe(false);
        expect(wrapper.find('a[href="/billing"]').exists()).toBe(false);
        expect(wrapper.find('a[href="/settings/ai-provider"]').exists()).toBe(false);
        expect(wrapper.findAll('h2').wrappers.map((h) => h.text())).not.toContain('Settings');
    });
});
