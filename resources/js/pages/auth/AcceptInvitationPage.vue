<template>
    <auth-card title="Workspace invitation">
        <p v-if="loading" class="text-sm text-fg-muted">Loading…</p>

        <div v-else-if="loadError" role="alert">
            <p class="mb-4 text-sm text-danger">{{ loadError }}</p>
            <router-link :to="{ name: 'home' }" class="text-sm text-primary underline">Go to BoschifAI</router-link>
        </div>

        <template v-else>
            <p class="mb-4 text-sm text-fg">
                <strong>{{ invitation.email }}</strong> has been invited to join <strong>{{ invitation.workspace }}</strong> as
                {{ invitation.role === 'member' ? 'a' : 'an' }} {{ invitation.role }}<template v-if="invitation.invited_by"> by {{ invitation.invited_by }}</template>.
            </p>

            <!-- Signed in as the invited address: one click. -->
            <div v-if="invitation.signed_in_as === invitation.email">
                <p v-if="error" class="mb-4 text-sm text-danger" role="alert">{{ error }}</p>
                <button type="button" :disabled="submitting" :class="button" @click="accept()">
                    {{ submitting ? 'Joining…' : `Join ${invitation.workspace}` }}
                </button>
            </div>

            <!-- Signed in as someone else. -->
            <p v-else-if="invitation.signed_in_as" class="text-sm text-fg" role="alert">
                You're signed in as {{ invitation.signed_in_as }}, but this invitation is for {{ invitation.email }}.
                Sign out, then open the link again and sign in as {{ invitation.email }}.
            </p>

            <!-- Not signed in: choose a password (and, if new to BoschifAI, a name), then join. -->
            <form v-else @submit.prevent="accept(details())">
                <p v-if="invitation.has_account" class="mb-4 text-sm text-fg-muted">
                    You already have an account. Create a new password to accept — it replaces your current one
                    and signs you out everywhere else.
                </p>

                <template v-if="!invitation.has_account">
                    <label for="name" class="block text-sm font-medium text-fg mb-1">Your name</label>
                    <input id="name" v-model="name" type="text" autocomplete="name" required :class="field" />
                </template>

                <label for="password" class="block text-sm font-medium text-fg mb-1">{{ invitation.has_account ? 'New password' : 'Choose a password' }}</label>
                <input id="password" v-model="password" type="password" autocomplete="new-password" required :class="field" />

                <label for="password_confirmation" class="block text-sm font-medium text-fg mb-1">Confirm password</label>
                <input id="password_confirmation" v-model="passwordConfirmation" type="password" autocomplete="new-password" required :class="field" />

                <p class="mb-4 text-xs text-fg-muted">At least 12 characters, with letters and numbers.</p>

                <ul v-if="errors.length" class="mb-4 text-sm text-danger" role="alert">
                    <li v-for="message in errors" :key="message">{{ message }}</li>
                </ul>

                <button type="submit" :disabled="submitting" :class="button">
                    <template v-if="submitting">{{ invitation.has_account ? 'Saving your password…' : 'Creating your account…' }}</template>
                    <template v-else>{{ invitation.has_account ? 'Set password and join' : 'Create account and join' }}</template>
                </button>

                <p v-if="invitation.has_account" class="mt-4 text-center text-sm text-fg-muted">
                    Know your current password?
                    <router-link :to="{ name: 'login', query: { redirect: $route.fullPath } }" class="text-primary underline">Sign in instead</router-link>
                </p>
            </form>
        </template>
    </auth-card>
</template>

<script>
import AuthCard from '../../components/AuthCard.vue';

export default {
    name: 'AcceptInvitationPage',

    components: { AuthCard },

    props: {
        token: { type: String, required: true },
        // Injected so tests can observe it; reloading drops anything cached from before joining.
        reload: { type: Function, default: () => window.location.assign('/') },
    },

    data() {
        return {
            invitation: null,
            loading: true,
            loadError: null,
            name: '',
            password: '',
            passwordConfirmation: '',
            submitting: false,
            error: null,
            errors: [],
            field: 'w-full rounded-sm border border-border bg-surface px-3 py-2 text-sm text-fg mb-4 transition focus:outline-none focus:border-primary',
            button: 'w-full rounded-sm bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-dark disabled:opacity-50',
        };
    },

    async created() {
        try {
            const { data } = await window.axios.get(`/api/invitations/${this.token}`, { skipAuthRedirect: true });
            this.invitation = data.data;
        } catch (e) {
            this.loadError = e.response?.data?.message || 'This invitation link is no longer valid.';
        } finally {
            this.loading = false;
        }
    },

    methods: {
        details() {
            const password = { password: this.password, password_confirmation: this.passwordConfirmation };
            return this.invitation.has_account ? password : { name: this.name, ...password };
        },

        async accept(details = {}) {
            this.submitting = true;
            this.error = null;
            this.errors = [];
            try {
                await window.axios.get('/sanctum/csrf-cookie');
                await window.axios.post(`/api/invitations/${this.token}/accept`, details, { skipAuthRedirect: true });
                this.reload();
            } catch (e) {
                const fieldErrors = e.response?.data?.errors;
                const messages = fieldErrors ? Object.values(fieldErrors).flat() : [e.response?.data?.message || 'Something went wrong. Please try again.'];
                this.errors = messages;
                this.error = messages[0];
                this.password = '';
                this.passwordConfirmation = '';
            } finally {
                this.submitting = false;
            }
        },
    },
};
</script>
