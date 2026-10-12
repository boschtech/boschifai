<template>
    <auth-card title="Sign in">
        <form @submit.prevent="submit">
            <label for="email" class="block text-sm font-medium text-fg mb-1">Email</label>
            <input id="email" v-model="email" type="email" autocomplete="username" required class="w-full rounded-sm border border-border bg-surface px-3 py-2 text-sm text-fg mb-4 transition focus:outline-none focus:border-primary" />

            <label for="password" class="block text-sm font-medium text-fg mb-1">Password</label>
            <input id="password" v-model="password" type="password" autocomplete="current-password" required class="w-full rounded-sm border border-border bg-surface px-3 py-2 text-sm text-fg mb-4 transition focus:outline-none focus:border-primary" />

            <label class="mb-4 flex items-center gap-2 text-sm text-fg">
                <input v-model="remember" type="checkbox" class="h-4 w-4 rounded-sm border-border text-primary focus:ring-primary" />
                Keep me signed in on this device
            </label>

            <p v-if="error" class="mb-4 text-sm text-danger" role="alert">{{ error }}</p>

            <button type="submit" :disabled="submitting" class="w-full rounded-sm bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-dark disabled:opacity-50">
                {{ submitting ? 'Signing in…' : 'Sign in' }}
            </button>
        </form>

        <p class="mt-4 text-center text-sm">
            <router-link :to="{ name: 'password.forgot' }" class="text-primary underline">Forgot your password?</router-link>
        </p>
    </auth-card>
</template>

<script>
import AuthCard from '../../components/AuthCard.vue';
import { safeRedirect } from '../../lib/authHttp';

export default {
    name: 'LoginPage',

    components: { AuthCard },

    data() {
        return { email: '', password: '', remember: false, submitting: false, error: null };
    },

    methods: {
        async submit() {
            this.submitting = true;
            this.error = null;
            try {
                await this.$store.dispatch('auth/login', { email: this.email, password: this.password, remember: this.remember });
                this.$router.replace(safeRedirect(this.$route.query.redirect)).catch(() => {});
            } catch (e) {
                // 422: wrong email/password (Fortify deliberately doesn't say which); 429: too many attempts.
                this.error = e.response?.data?.errors?.email?.[0] || e.response?.data?.message || 'Sign-in failed. Please try again.';
                this.password = '';
            } finally {
                this.submitting = false;
            }
        },
    },
};
</script>
