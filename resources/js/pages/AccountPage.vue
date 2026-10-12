<template>
    <div class="max-w-md">
        <h1 class="text-xl font-bold text-fg-strong mb-1">Account</h1>
        <p class="text-sm text-fg-muted mb-6">{{ user.name }} · {{ user.email }}</p>

        <h2 class="text-sm font-semibold text-fg mb-3">Change password</h2>
        <form @submit.prevent="submit">
            <label for="current_password" class="block text-sm font-medium text-fg mb-1">Current password</label>
            <input id="current_password" v-model="currentPassword" type="password" autocomplete="current-password" required class="w-full rounded-sm border border-border bg-surface px-3 py-2 text-sm text-fg mb-4 transition focus:outline-none focus:border-primary" />

            <label for="password" class="block text-sm font-medium text-fg mb-1">New password</label>
            <input id="password" v-model="password" type="password" autocomplete="new-password" required class="w-full rounded-sm border border-border bg-surface px-3 py-2 text-sm text-fg mb-4 transition focus:outline-none focus:border-primary" />

            <label for="password_confirmation" class="block text-sm font-medium text-fg mb-1">Confirm new password</label>
            <input id="password_confirmation" v-model="passwordConfirmation" type="password" autocomplete="new-password" required class="w-full rounded-sm border border-border bg-surface px-3 py-2 text-sm text-fg mb-4 transition focus:outline-none focus:border-primary" />

            <p class="mb-4 text-xs text-fg-muted">At least 12 characters, with letters and numbers.</p>

            <p v-if="saved" class="mb-4 text-sm text-success" role="status">Your password has been changed.</p>
            <ul v-if="errors.length" class="mb-4 text-sm text-danger" role="alert">
                <li v-for="message in errors" :key="message">{{ message }}</li>
            </ul>

            <button type="submit" :disabled="submitting" class="rounded-sm bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-dark disabled:opacity-50">
                {{ submitting ? 'Saving…' : 'Change password' }}
            </button>
        </form>
    </div>
</template>

<script>
import { mapGetters } from 'vuex';

export default {
    name: 'AccountPage',

    data() {
        return { currentPassword: '', password: '', passwordConfirmation: '', submitting: false, saved: false, errors: [] };
    },

    computed: {
        ...mapGetters('auth', ['user']),
    },

    methods: {
        async submit() {
            this.submitting = true;
            this.saved = false;
            this.errors = [];
            try {
                await this.$store.dispatch('auth/changePassword', {
                    current_password: this.currentPassword,
                    password: this.password,
                    password_confirmation: this.passwordConfirmation,
                });
                this.saved = true;
                this.currentPassword = '';
                this.password = '';
                this.passwordConfirmation = '';
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
