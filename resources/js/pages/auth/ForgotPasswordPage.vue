<template>
    <auth-card title="Reset your password">
        <p v-if="sent" class="text-sm text-fg" role="status">{{ sent }}</p>

        <form v-else @submit.prevent="submit">
            <p class="mb-4 text-sm text-fg-muted">Enter your email address and we'll send you a link to choose a new password.</p>

            <label for="email" class="block text-sm font-medium text-fg mb-1">Email</label>
            <input id="email" v-model="email" type="email" autocomplete="username" required class="w-full rounded-sm border border-border bg-surface px-3 py-2 text-sm text-fg mb-4 transition focus:outline-none focus:border-primary" />

            <p v-if="error" class="mb-4 text-sm text-danger" role="alert">{{ error }}</p>

            <button type="submit" :disabled="submitting" class="w-full rounded-sm bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-dark disabled:opacity-50">
                {{ submitting ? 'Sending…' : 'Send reset link' }}
            </button>
        </form>

        <p class="mt-4 text-center text-sm">
            <router-link :to="{ name: 'login' }" class="text-primary underline">Back to sign in</router-link>
        </p>
    </auth-card>
</template>

<script>
import AuthCard from '../../components/AuthCard.vue';

export default {
    name: 'ForgotPasswordPage',

    components: { AuthCard },

    data() {
        return { email: '', submitting: false, sent: null, error: null };
    },

    methods: {
        async submit() {
            this.submitting = true;
            this.error = null;
            try {
                // The server replies the same way whether or not the address has an account.
                this.sent = await this.$store.dispatch('auth/forgotPassword', this.email);
            } catch (e) {
                this.error = e.response?.data?.errors?.email?.[0] || e.response?.data?.message || 'Something went wrong. Please try again.';
            } finally {
                this.submitting = false;
            }
        },
    },
};
</script>
