<template>
    <div>
        <h1 class="text-xl font-bold text-fg-strong mb-1">{{ title }}</h1>
        <p class="text-sm text-fg-muted mb-6">{{ description }}</p>

        <form class="max-w-md" @submit.prevent="submit">
            <label class="block text-sm font-medium text-fg mb-1">Repository</label>
            <select
                v-model="repoConfigId"
                required
                class="w-full rounded-sm border border-border bg-surface px-3 py-2 text-sm text-fg mb-1 transition focus:outline-none focus:border-primary"
            >
                <option :value="null" disabled>Select a repository…</option>
                <option v-for="repo in repoConfigsList" :key="repo.id" :value="repo.id">
                    {{ repo.display_name }}
                </option>
            </select>
            <p class="text-xs text-fg-muted mb-6">
                Don't see your repository?
                <router-link :to="{ name: 'settings.github' }" class="text-primary underline">Connect it here</router-link>.
            </p>

            <p v-if="error" class="text-sm text-danger mb-4">{{ error }}</p>

            <button
                type="submit"
                :disabled="submitting || !repoConfigId"
                class="rounded-sm bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-dark disabled:opacity-50"
            >
                {{ submitting ? 'Starting…' : submitLabel }}
            </button>
        </form>
    </div>
</template>

<script>
import { mapActions, mapGetters } from 'vuex';

export default {
    name: 'StandaloneActionCreatePage',

    props: {
        // Fixed per-route (see router/index.js's 'standalone.building-skills'/
        // 'standalone.building-knowledge-base' routes) — same "the sidebar link IS the mode
        // selector" pattern as RunCreatePage's requirement/coverage props.
        runType: { type: String, required: true },
        title: { type: String, required: true },
        description: { type: String, required: true },
        submitLabel: { type: String, default: 'Start' },
    },

    data() {
        return {
            repoConfigId: null,
            submitting: false,
            error: null,
        };
    },

    computed: {
        ...mapGetters('repoConfigs', { repoConfigsList: 'list' }),
    },

    created() {
        this.fetchRepoConfigs();
    },

    methods: {
        ...mapActions('runs', ['createRun']),
        ...mapActions('repoConfigs', ['fetchRepoConfigs']),

        // No requirement_text/target_file_path sent at all — StoreRunRequest fills both in
        // server-side for these two run types (see its own prepareForValidation() comment) —
        // this form is deliberately just a repo picker.
        async submit() {
            this.submitting = true;
            this.error = null;
            try {
                const run = await this.createRun({ run_type: this.runType, repo_config_id: this.repoConfigId });
                this.$router.push({ name: 'runs.show', params: { id: run.id } });
            } catch (e) {
                this.error = e.response?.data?.message || e.message;
            } finally {
                this.submitting = false;
            }
        },
    },
};
</script>
