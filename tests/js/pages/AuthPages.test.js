import { afterEach, describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import LoginPage from '@/pages/auth/LoginPage.vue';
import ForgotPasswordPage from '@/pages/auth/ForgotPasswordPage.vue';
import ResetPasswordPage from '@/pages/auth/ResetPasswordPage.vue';
import AccountPage from '@/pages/AccountPage.vue';
import auth from '@/store/modules/auth';
import { apiError, flushPromises, makeLocalVueWithRouter, makeRealStore, mockApi } from '../helpers';

const USER = { id: 1, name: 'Ops Admin', email: 'ops@example.com' };

function validationError(errors, status = 422) {
    return Object.assign(new Error('Request failed'), { response: { status, data: { message: 'invalid', errors } } });
}

let wrapper;

async function mountPage(Page, { api = {}, path = '/login', query = {}, propsData, signedIn = false } = {}) {
    mockApi({ 'GET /sanctum/csrf-cookie': {}, ...api });
    window.axios.put = vi.fn((url, body) => {
        const handler = api[`PUT ${url}`];
        try {
            return Promise.resolve({ data: typeof handler === 'function' ? handler(body) : handler });
        } catch (e) {
            return Promise.reject(e);
        }
    });
    const { localVue, router } = makeLocalVueWithRouter({ path, query });
    const store = makeRealStore(localVue, { auth });
    if (signedIn) store.commit('auth/SET_USER', USER);
    wrapper = mount(Page, { localVue, store, router, propsData });
    await flushPromises();

    return { router, store };
}

async function fill(values) {
    for (const [id, value] of Object.entries(values)) {
        await wrapper.find(`#${id}`).setValue(value);
    }
}

afterEach(() => {
    wrapper?.destroy();
    wrapper = undefined;
});

describe('LoginPage', () => {
    it('signs in and goes to the page the user originally asked for', async () => {
        const posted = vi.fn(() => ({}));
        const { router, store } = await mountPage(LoginPage, {
            api: { 'POST /login': posted, 'GET /api/me': { data: USER } },
            query: { redirect: '/runs/abc' },
        });

        await fill({ email: 'ops@example.com', password: 'correct-horse-battery-9' });
        await wrapper.find('input[type="checkbox"]').setChecked(true);
        await wrapper.find('form').trigger('submit');
        await flushPromises();

        expect(posted).toHaveBeenCalledWith({ email: 'ops@example.com', password: 'correct-horse-battery-9', remember: true });
        expect(store.getters['auth/user']).toEqual(USER);
        expect(router.currentRoute.fullPath).toBe('/runs/abc');
    });

    it('never follows a redirect to another site', async () => {
        const { router } = await mountPage(LoginPage, {
            api: { 'POST /login': {}, 'GET /api/me': { data: USER } },
            query: { redirect: '//evil.example' },
        });

        await fill({ email: 'ops@example.com', password: 'x' });
        await wrapper.find('form').trigger('submit');
        await flushPromises();

        expect(router.currentRoute.fullPath).toBe('/');
    });

    it('shows the server’s message and clears the password when sign-in is rejected', async () => {
        await mountPage(LoginPage, { api: { 'POST /login': () => { throw validationError({ email: ['These credentials do not match our records.'] }); } } });

        await fill({ email: 'ops@example.com', password: 'wrong' });
        await wrapper.find('form').trigger('submit');
        await flushPromises();

        expect(wrapper.find('[role="alert"]').text()).toBe('These credentials do not match our records.');
        expect(wrapper.find('#password').element.value).toBe('');
        expect(wrapper.find('button[type="submit"]').text()).toBe('Sign in');
    });

    it('falls back to a generic message when there are no field errors', async () => {
        await mountPage(LoginPage, { api: { 'POST /login': () => { throw new Error('Network Error'); } } });

        await fill({ email: 'ops@example.com', password: 'x' });
        await wrapper.find('form').trigger('submit');
        await flushPromises();

        expect(wrapper.find('[role="alert"]').text()).toBe('Sign-in failed. Please try again.');
    });

    it('links to the forgot-password page', async () => {
        await mountPage(LoginPage);

        expect(wrapper.find('a[href="/forgot-password"]').exists()).toBe(true);
    });
});

describe('ForgotPasswordPage', () => {
    it('shows the same neutral confirmation whatever the address', async () => {
        const posted = vi.fn(() => ({ message: "If an account exists for that email address, we've sent a link to reset its password." }));
        await mountPage(ForgotPasswordPage, { api: { 'POST /forgot-password': posted } });

        await fill({ email: 'someone@example.com' });
        await wrapper.find('form').trigger('submit');
        await flushPromises();

        expect(posted).toHaveBeenCalledWith({ email: 'someone@example.com' });
        expect(wrapper.find('[role="status"]').text()).toContain('If an account exists');
        expect(wrapper.find('form').exists()).toBe(false);
    });

    it('shows an error when the request itself fails', async () => {
        await mountPage(ForgotPasswordPage, { api: { 'POST /forgot-password': () => { throw apiError('Too many requests.'); } } });

        await fill({ email: 'someone@example.com' });
        await wrapper.find('form').trigger('submit');
        await flushPromises();

        expect(wrapper.find('[role="alert"]').text()).toBe('Too many requests.');
    });
});

describe('ResetPasswordPage', () => {
    it('prefills the email from the link and saves the new password', async () => {
        const posted = vi.fn(() => ({}));
        await mountPage(ResetPasswordPage, {
            api: { 'POST /reset-password': posted },
            path: '/reset-password/tok-123', query: { email: 'ops@example.com' }, propsData: { token: 'tok-123' },
        });

        expect(wrapper.find('#email').element.value).toBe('ops@example.com');

        await fill({ password: 'a-brand-new-pass-77', password_confirmation: 'a-brand-new-pass-77' });
        await wrapper.find('form').trigger('submit');
        await flushPromises();

        expect(posted).toHaveBeenCalledWith({
            token: 'tok-123', email: 'ops@example.com', password: 'a-brand-new-pass-77', password_confirmation: 'a-brand-new-pass-77',
        });
        expect(wrapper.find('[role="status"]').text()).toContain('Your password has been changed.');
        expect(wrapper.find('a[href="/login"]').exists()).toBe(true);
    });

    it('lists every validation problem', async () => {
        await mountPage(ResetPasswordPage, {
            api: { 'POST /reset-password': () => { throw validationError({ email: ['This password reset token is invalid.'], password: ['The password field must be at least 12 characters.'] }); } },
            path: '/reset-password/t', propsData: { token: 't' },
        });

        await fill({ email: 'ops@example.com', password: 'short', password_confirmation: 'short' });
        await wrapper.find('form').trigger('submit');
        await flushPromises();

        expect(wrapper.findAll('[role="alert"] li').wrappers.map((li) => li.text())).toEqual([
            'This password reset token is invalid.',
            'The password field must be at least 12 characters.',
        ]);
    });

    it('shows a generic message when the server gives no details', async () => {
        await mountPage(ResetPasswordPage, { api: { 'POST /reset-password': () => { throw new Error('Network Error'); } }, path: '/reset-password/t', propsData: { token: 't' } });

        await fill({ email: 'a@b.c', password: 'x', password_confirmation: 'x' });
        await wrapper.find('form').trigger('submit');
        await flushPromises();

        expect(wrapper.find('[role="alert"]').text()).toBe('Something went wrong. Please try again.');
    });
});

describe('AccountPage', () => {
    it('shows who is signed in and changes the password', async () => {
        const changed = vi.fn(() => ({}));
        await mountPage(AccountPage, { api: { 'PUT /user/password': changed }, path: '/account', signedIn: true });

        expect(wrapper.text()).toContain('Ops Admin · ops@example.com');

        await fill({ current_password: 'old-password-1', password: 'a-brand-new-pass-77', password_confirmation: 'a-brand-new-pass-77' });
        await wrapper.find('form').trigger('submit');
        await flushPromises();

        expect(changed).toHaveBeenCalledWith({ current_password: 'old-password-1', password: 'a-brand-new-pass-77', password_confirmation: 'a-brand-new-pass-77' });
        expect(wrapper.find('[role="status"]').text()).toBe('Your password has been changed.');
        expect(wrapper.find('#current_password').element.value).toBe('');
    });

    it('shows why the change was refused', async () => {
        await mountPage(AccountPage, {
            api: { 'PUT /user/password': () => { throw validationError({ current_password: ['The provided password does not match your current password.'] }); } },
            path: '/account', signedIn: true,
        });

        await fill({ current_password: 'wrong', password: 'a-brand-new-pass-77', password_confirmation: 'a-brand-new-pass-77' });
        await wrapper.find('form').trigger('submit');
        await flushPromises();

        expect(wrapper.find('[role="alert"]').text()).toBe('The provided password does not match your current password.');
        expect(wrapper.find('[role="status"]').exists()).toBe(false);
    });

    it('shows a generic message when the server gives no details', async () => {
        await mountPage(AccountPage, { api: { 'PUT /user/password': () => { throw new Error('Network Error'); } }, path: '/account', signedIn: true });

        await fill({ current_password: 'a', password: 'b', password_confirmation: 'b' });
        await wrapper.find('form').trigger('submit');
        await flushPromises();

        expect(wrapper.find('[role="alert"]').text()).toBe('Something went wrong. Please try again.');
    });
});
