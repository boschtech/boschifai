<template>
    <auth-card title="Choose a new password">
        <div v-if="done" role="status">
            <p class="mb-4 text-sm text-fg">Your password has been changed. You can now sign in with it.</p>
            <router-link :to="{ name: 'login' }" class="text-sm text-primary underline">Sign in</router-link>
        </div>

        <form v-else @submit.prevent="submit">
            <label for="email" class="block text-sm font-medium text-fg mb-1">Email</label>
            <input id="email" v-model="email" type="email" autocomplete="username" required class="w-full rounded-sm border border-border bg-surface px-3 py-2 text-sm text-fg mb-4 transition focus:outline-none focus:border-primary" />

            <label for="password" class="block text-sm font-medium text-fg mb-1">New password</label>
            <input id="password" v-model="password" type="password" autocomplete="new-password" required class="w-full rounded-sm border border-border bg-surface px-3 py-2 text-sm text-fg mb-4 transition focus:outline-none focus:border-primary" />

            <label for="password_confirmation" class="block text-sm font-medium text-fg mb-1">Confirm new password</label>
            <input id="password_confirmation" v-model="passwordConfirmation" type="password" autocomplete="new-password" required class="w-full rounded-sm border border-border bg-surface px-3 py-2 text-sm text-fg mb-4 transition focus:outline-none focus:border-primary" />

            <p class="mb-4 text-xs text-fg-muted">At least 12 characters, with letters and numbers.</p>

            <ul v-if="errors.length" class="mb-4 text-sm text-danger" role="alert">
                <li v-for="message in errors" :key="message">{{ message }}</li>
            </ul>

            <button type="submit" :disabled="submitting" class="w-full rounded-sm bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-dark disabled:opacity-50">
                {{ submitting ? 'Saving…' : 'Save new password' }}
            </button>
        </form>
    </auth-card>
</template>

<script>
import AuthCard from '../../components/AuthCard.vue';

export default {
    name: 'ResetPasswordPage',

    components: { AuthCard },

    props: {
        // From the link in the password-reset email: /reset-password/:token?email=…
        token: { type: String, required: true },
    },

    data() {
        return {
            email: this.$route.query.email || '',
            password: '',
            passwordConfirmation: '',
            submitting: false,
            done: false,
            errors: [],
        };
    },

    methods: {
        async submit() {
            this.submitting = true;
            this.errors = [];
            try {
                await this.$store.dispatch('auth/resetPassword', {
                    token: this.token,
                    email: this.email,
                    password: this.password,
                    password_confirmation: this.passwordConfirmation,
                });
                this.done = true;
            } catch (e) {
                const fieldErrors = e.response?.data?.errors;
                this.errors = fieldErrors ? Object.values(fieldErrors).flat() : [e.response?.data?.message || 'Something went wrong. Please try again.'];
            } finally {
                this.submitting = false;
            }
        },
    },
};
</script>
