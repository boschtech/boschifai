<template>
    <div class="max-w-3xl">
        <h1 class="text-xl font-bold text-fg-strong mb-1">AI provider</h1>
        <p class="text-sm text-fg-muted mb-6">
            Which Anthropic account this workspace's pipeline steps run on, and who pays for them.
        </p>

        <p v-if="loading && !credential" class="text-sm text-fg-muted">Loading…</p>
        <p v-else-if="error" class="text-sm text-danger" role="alert">{{ error }}</p>

        <template v-else-if="credential">
            <section class="mb-8 rounded-md border border-border bg-surface p-4 text-sm text-fg" data-current>
                <template v-if="!usesOwnKey">
                    <p class="font-medium text-fg-strong">BoschifAI's Anthropic account</p>
                    <p class="text-fg-muted">Runs are paid for with this workspace's credits.</p>
                </template>
                <template v-else>
                    <p class="font-medium text-fg-strong">This workspace's own Anthropic API key ({{ credential.secret_hint }})</p>
                    <p class="text-fg-muted">Anthropic bills your account directly; runs don't use credits.</p>
                    <p v-if="credential.status === 'valid'" class="mt-2 text-success" data-status>
                        Accepted by Anthropic<template v-if="credential.validated_at"> · checked {{ formatDate(credential.validated_at) }}</template>
                    </p>
                    <p v-else class="mt-2 text-danger" data-status>{{ credential.validation_error }}</p>
                    <div class="mt-3 flex gap-3">
                        <button type="button" :disabled="busy" :class="secondaryButton" @click="check">Check key</button>
                        <button type="button" :disabled="busy" :class="secondaryButton" @click="confirmingRemoval = true">Remove key</button>
                    </div>
                </template>
            </section>

            <section>
                <h2 class="text-sm font-semibold text-fg mb-2">{{ usesOwnKey ? 'Replace the key' : 'Use your own Anthropic API key' }}</h2>
                <form class="flex flex-wrap gap-2" @submit.prevent="save">
                    <input
                        id="api-key"
                        v-model="apiKey"
                        type="password"
                        autocomplete="off"
                        spellcheck="false"
                        required
                        placeholder="sk-ant-…"
                        aria-label="Anthropic API key"
                        class="min-w-0 flex-1 rounded-sm border border-border bg-surface px-3 py-2 text-sm text-fg transition focus:outline-none focus:border-primary"
                    />
                    <button type="submit" :disabled="busy || !apiKey.trim()" :class="primaryButton">
                        {{ busy ? 'Checking with Anthropic…' : 'Save key' }}
                    </button>
                </form>
                <p class="mt-2 text-xs text-fg-muted">
                    The key is checked with Anthropic before it's saved, stored encrypted, and never shown again.
                    Create one in the Anthropic Console under API keys.
                </p>
            </section>

            <p v-if="actionError" class="mt-4 text-sm text-danger" role="alert">{{ actionError }}</p>
            <p v-if="saved" class="mt-4 text-sm text-success" role="status">{{ saved }}</p>
        </template>

        <confirm-dialog
            :open="confirmingRemoval"
            title="Remove this workspace's API key?"
            message="Runs will go back to BoschifAI's Anthropic account and use this workspace's credits."
            confirm-label="Remove key"
            @confirm="remove"
            @cancel="confirmingRemoval = false"
        />
    </div>
</template>

<script>
import { mapGetters } from 'vuex';
import ConfirmDialog from '../components/ConfirmDialog.vue';

function message(e) {
    const errors = e.response?.data?.errors;
    return (errors && Object.values(errors).flat()[0]) || e.response?.data?.message || e.message;
}

export default {
    name: 'AiProviderSettingsPage',

    components: { ConfirmDialog },

    data() {
        return {
            apiKey: '',
            busy: false,
            actionError: null,
            saved: null,
            confirmingRemoval: false,
            primaryButton: 'rounded-sm bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-dark disabled:opacity-50',
            secondaryButton: 'rounded-sm border border-border bg-surface px-4 py-2 text-sm font-medium text-fg transition hover:bg-surface-alt disabled:opacity-50',
        };
    },

    computed: {
        ...mapGetters('aiProvider', ['credential', 'usesOwnKey', 'loading', 'error']),
    },

    created() {
        this.$store.dispatch('aiProvider/fetchCredential');
    },

    methods: {
        async run(action, done) {
            this.busy = true;
            this.actionError = null;
            this.saved = null;
            try {
                await action();
                this.saved = done;
            } catch (e) {
                this.actionError = message(e);
            } finally {
                this.busy = false;
            }
        },

        async save() {
            await this.run(() => this.$store.dispatch('aiProvider/saveKey', this.apiKey.trim()), 'Saved. New runs use this key.');
            // Never keep the key in the page longer than needed — even after a refusal.
            this.apiKey = '';
        },

        check() {
            return this.run(() => this.$store.dispatch('aiProvider/checkKey'), null);
        },

        remove() {
            this.confirmingRemoval = false;
            return this.run(() => this.$store.dispatch('aiProvider/removeKey'), 'Removed. New runs use BoschifAI\'s account and this workspace\'s credits.');
        },

        formatDate(iso) {
            return new Date(iso).toLocaleDateString();
        },
    },
};
</script>
