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
                    <template v-if="credential.provider === 'vertex'">
                        <p class="font-medium text-fg-strong">This workspace's own Google Vertex AI</p>
                        <p class="text-fg-muted">
                            {{ credential.config.client_email }} · project {{ credential.config.project_id }} · {{ credential.config.region }} · {{ credential.config.model }}.
                            Google bills your project directly; runs don't use credits (costs shown in BoschifAI are estimates).
                        </p>
                    </template>
                    <template v-else-if="credential.provider === 'bedrock'">
                        <p class="font-medium text-fg-strong">This workspace's own Amazon Bedrock</p>
                        <p class="text-fg-muted">
                            Role {{ credential.config.role_arn }} · {{ credential.config.region }} · {{ credential.config.model }}.
                            AWS bills your account directly; runs don't use credits (costs shown in BoschifAI are estimates).
                        </p>
                    </template>
                    <template v-else>
                        <p class="font-medium text-fg-strong">This workspace's own Anthropic API key ({{ credential.secret_hint }})</p>
                        <p class="text-fg-muted">Anthropic bills your account directly; runs don't use credits.</p>
                    </template>
                    <p v-if="credential.status === 'valid'" class="mt-2 text-success" data-status>
                        Accepted by Anthropic<template v-if="credential.validated_at"> · checked {{ formatDate(credential.validated_at) }}</template>
                    </p>
                    <p v-else class="mt-2 text-danger" data-status>{{ credential.validation_error }}</p>
                    <div class="mt-3 flex gap-3">
                        <button type="button" :disabled="busy" :class="secondaryButton" @click="check">{{ credential.provider === 'anthropic' ? 'Check key' : 'Check access' }}</button>
                        <button type="button" :disabled="busy" :class="secondaryButton" @click="confirmingRemoval = true">{{ stopLabel }}</button>
                    </div>
                </template>
            </section>

            <div class="mb-4 flex flex-wrap gap-2" role="tablist">
                <button
                    v-for="option in setupOptions"
                    :key="option.key"
                    type="button"
                    role="tab"
                    :aria-selected="String(setup === option.key)"
                    :class="setup === option.key ? 'rounded-sm border border-primary bg-primary/10 px-3 py-1.5 text-sm font-medium text-fg-strong' : secondaryButton"
                    @click="setup = option.key"
                >
                    {{ option.label }}
                </button>
            </div>

            <section v-if="setup === 'vertex'" data-vertex-setup>
                <h2 class="text-sm font-semibold text-fg mb-2">Use your own Google Vertex AI</h2>
                <ol class="mb-3 list-decimal pl-5 text-sm text-fg-muted">
                    <li>In a Google Cloud project for this (a dedicated one is best), enable the Vertex AI API and enable the Claude model you want in Model Garden.</li>
                    <li>Create a service account with only the <strong>Vertex AI User</strong> role (roles/aiplatform.user).</li>
                    <li>Create a JSON key for it and choose that file below.</li>
                </ol>
                <p class="mb-3 text-sm text-fg-muted">
                    The key is stored encrypted and never shown again; revoke it in Google Cloud whenever you like and upload a new one.
                    Saving checks it end to end with one tiny model request (a fraction of a cent on your Google Cloud bill).
                </p>
                <form class="grid gap-2" @submit.prevent="saveVertex">
                    <input id="service-account-key" type="file" accept="application/json,.json" required aria-label="Service-account key file" class="text-sm text-fg" @change="readKeyFile" />
                    <div class="flex flex-wrap gap-2">
                        <input id="vertex-region" v-model="vertex.region" required placeholder="us-east5 or global" aria-label="Region" :class="field" />
                        <input id="vertex-model" v-model="vertex.model" required placeholder="claude-sonnet-4-5@20250929" aria-label="Model ID" :class="field" />
                        <input id="vertex-project" v-model="vertex.project_id" placeholder="Project ID (default: the key's)" aria-label="Project ID" :class="field" />
                    </div>
                    <div>
                        <button type="submit" :disabled="busy || !vertex.service_account_key" :class="primaryButton">{{ busy ? 'Checking with Google…' : 'Save and check' }}</button>
                    </div>
                </form>
            </section>

            <section v-else-if="setup === 'bedrock'" data-bedrock-setup>
                <h2 class="text-sm font-semibold text-fg mb-2">Use your own Amazon Bedrock</h2>
                <p class="mb-2 text-sm text-fg-muted">
                    BoschifAI never stores AWS keys: for each step it assumes a role in your AWS account for a
                    short-lived session. In the AWS account that has Bedrock model access, create an IAM role with
                    this <strong>trust policy</strong>:
                </p>
                <pre class="mb-3 overflow-x-auto rounded-sm border border-border bg-surface-alt p-3 text-xs text-fg" data-trust-policy>{{ trustPolicy }}</pre>
                <p class="mb-2 text-sm text-fg-muted">and these <strong>permissions</strong>:</p>
                <pre class="mb-3 overflow-x-auto rounded-sm border border-border bg-surface-alt p-3 text-xs text-fg" data-permissions-policy>{{ permissionsPolicy }}</pre>
                <p class="mb-3 text-sm text-fg-muted">
                    Saving checks the role end to end with one tiny model request (a fraction of a cent on your AWS bill).
                </p>
                <form class="grid gap-2" @submit.prevent="saveBedrock">
                    <input id="role-arn" v-model="bedrock.role_arn" required placeholder="arn:aws:iam::123456789012:role/BoschifAIBedrock" aria-label="Role ARN" :class="field" />
                    <div class="flex flex-wrap gap-2">
                        <input id="region" v-model="bedrock.region" required placeholder="us-east-1" aria-label="AWS region" :class="field" />
                        <input id="model" v-model="bedrock.model" required placeholder="us.anthropic.claude-sonnet-4-6" aria-label="Model (inference profile ID or ARN)" :class="field" />
                    </div>
                    <div>
                        <button type="submit" :disabled="busy" :class="primaryButton">{{ busy ? 'Checking with AWS…' : 'Save and check' }}</button>
                    </div>
                </form>
            </section>

            <section v-else>
                <h2 class="text-sm font-semibold text-fg mb-2">{{ credential.provider === 'anthropic' ? 'Replace the key' : 'Use your own Anthropic API key' }}</h2>
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
            :title="removalTitle"
            message="Runs will go back to BoschifAI's Anthropic account and use this workspace's credits."
            :confirm-label="stopLabel"
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
            // Which setup form is showing.
            setup: 'anthropic',
            bedrock: { role_arn: '', region: '', model: '' },
            vertex: { service_account_key: '', project_id: '', region: '', model: '' },
            field: 'min-w-0 flex-1 rounded-sm border border-border bg-surface px-3 py-2 text-sm text-fg transition focus:outline-none focus:border-primary',
            primaryButton: 'rounded-sm bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-dark disabled:opacity-50',
            secondaryButton: 'rounded-sm border border-border bg-surface px-4 py-2 text-sm font-medium text-fg transition hover:bg-surface-alt disabled:opacity-50',
        };
    },

    computed: {
        ...mapGetters('aiProvider', ['credential', 'usesOwnKey', 'loading', 'error']),

        setupOptions() {
            return [
                { key: 'anthropic', label: 'Anthropic API key' },
                ...(this.credential.bedrock?.available ? [{ key: 'bedrock', label: 'Amazon Bedrock' }] : []),
                { key: 'vertex', label: 'Google Vertex AI' },
            ];
        },

        removalTitle() {
            return { bedrock: 'Stop using Amazon Bedrock?', vertex: 'Stop using Google Vertex AI?' }[this.credential?.provider] ?? 'Remove this workspace\'s API key?';
        },

        stopLabel() {
            return { bedrock: 'Stop using Bedrock', vertex: 'Stop using Vertex' }[this.credential?.provider] ?? 'Remove key';
        },

        trustPolicy() {
            return JSON.stringify({
                Version: '2012-10-17',
                Statement: [{
                    Effect: 'Allow',
                    Principal: { AWS: this.credential.bedrock.principal_arn },
                    Action: 'sts:AssumeRole',
                    Condition: { StringEquals: { 'sts:ExternalId': this.credential.bedrock.external_id } },
                }],
            }, null, 2);
        },

        // The permissions Claude Code's Amazon Bedrock documentation lists.
        permissionsPolicy() {
            return JSON.stringify({
                Version: '2012-10-17',
                Statement: [
                    {
                        Effect: 'Allow',
                        Action: ['bedrock:InvokeModel', 'bedrock:InvokeModelWithResponseStream', 'bedrock:GetInferenceProfile'],
                        Resource: ['arn:aws:bedrock:*:*:inference-profile/*', 'arn:aws:bedrock:*:*:application-inference-profile/*', 'arn:aws:bedrock:*:*:foundation-model/*'],
                    },
                    { Effect: 'Allow', Action: 'bedrock:ListInferenceProfiles', Resource: '*' },
                    {
                        Effect: 'Allow',
                        Action: ['aws-marketplace:ViewSubscriptions', 'aws-marketplace:Subscribe'],
                        Resource: '*',
                        Condition: { StringEquals: { 'aws:CalledViaLast': 'bedrock.amazonaws.com' } },
                    },
                ],
            }, null, 2);
        },
    },

    async created() {
        await this.$store.dispatch('aiProvider/fetchCredential');
        if (this.credential?.provider === 'bedrock') {
            this.setup = 'bedrock';
            this.bedrock = { ...this.credential.config };
        } else if (this.credential?.provider === 'vertex') {
            this.setup = 'vertex';
            const { project_id, region, model } = this.credential.config;
            this.vertex = { service_account_key: '', project_id, region, model };
        }
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

        saveBedrock() {
            const settings = Object.fromEntries(Object.entries(this.bedrock).map(([k, v]) => [k, v.trim()]));
            return this.run(() => this.$store.dispatch('aiProvider/saveBedrock', settings), 'Saved. New runs use your Amazon Bedrock.');
        },

        // The key file's contents are kept only until the save request is sent.
        async readKeyFile(event) {
            const file = event.target.files?.[0];
            this.vertex.service_account_key = file ? await file.text() : '';
        },

        async saveVertex() {
            const { service_account_key, project_id, region, model } = this.vertex;
            await this.run(
                () => this.$store.dispatch('aiProvider/saveVertex', { service_account_key, project_id: project_id.trim() || null, region: region.trim(), model: model.trim() }),
                'Saved. New runs use your Google Vertex AI.',
            );
            this.vertex.service_account_key = '';
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
