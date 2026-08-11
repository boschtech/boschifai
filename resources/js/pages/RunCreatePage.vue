<template>
    <div class="max-w-2xl">
        <h1 class="text-xl font-bold text-fg-strong mb-1">{{ editingRunId ? 'Edit & resubmit requirement' : 'Submit a requirement' }}</h1>
        <p class="text-sm text-fg-muted" :class="editingRunId ? 'mb-1' : 'mb-6'">
            PHPUnit Feature-test generation only, against a repository connected to Boschifai.
        </p>
        <p v-if="editingRunId" class="text-sm text-fg-muted mb-6">
            Runs are never modified in place — submitting here creates a new run linked to the original;
            the original is left untouched and stays in the list.
        </p>

        <div class="mb-6 rounded-md border border-warning/30 bg-warning/10 p-4 text-sm text-warning">
            <strong>Do not paste real customer data.</strong> Never include real tenant/landlord names,
            ID numbers, banking details, or other PII in the requirement text below — this content is sent
            to an AI model and stored. Use synthetic example data only. See your POPIA compliance owner
            with questions.
        </div>

        <form @submit.prevent="submit">
            <label class="block text-sm font-medium text-fg mb-1">Target repository</label>
            <select
                v-model="form.repo_config_id"
                required
                class="w-full rounded-sm border border-border bg-surface px-3 py-2 text-sm text-fg mb-1 transition focus:outline-none focus:border-primary"
            >
                <option :value="null" disabled>Select a repository…</option>
                <option v-for="repo in repoConfigsList" :key="repo.id" :value="repo.id">
                    {{ repo.display_name }}<template v-if="!repo.has_docker_image"> — no test-runner image configured</template>
                </option>
            </select>
            <p v-if="selectedRepoMissingDockerImage" class="text-xs text-danger mb-4">
                This repository has no Docker test-runner image configured — an admin must set one
                before requirements can be run against it.
            </p>
            <p class="text-xs text-fg-muted mb-6">
                Don't see your repository?
                <router-link :to="{ name: 'settings.github' }" class="text-primary underline">Connect it here</router-link>.
            </p>

            <label class="block text-sm font-medium text-fg mb-1">Requirement text</label>
            <textarea
                v-model="form.requirement_text"
                rows="10"
                required
                class="w-full rounded-sm border border-border bg-surface px-3 py-2 text-sm text-fg mb-4 transition focus:outline-none focus:border-primary"
                :placeholder="requirementPlaceholder"
            ></textarea>

            <label class="block text-sm font-medium text-fg mb-1">Target file</label>
            <p class="text-xs text-fg-muted mb-1">
                Path (within the target repo) to the controller/service this requirement targets, e.g.
                <code class="text-fg">app/Http/Controllers/CustomFieldController.php</code>.
                Required — Boschifai does not guess the target on a bank-integrated, multi-tenant codebase.
            </p>
            <input
                v-model="form.target_file_path"
                type="text"
                required
                class="w-full rounded-sm border border-border bg-surface px-3 py-2 text-sm text-fg mb-6 font-mono transition focus:outline-none focus:border-primary"
                placeholder="app/Http/Controllers/..."
            />

            <p v-if="error" class="text-sm text-danger mb-4">{{ error }}</p>

            <button
                type="submit"
                :disabled="submitting || !form.repo_config_id || selectedRepoMissingDockerImage"
                class="rounded-sm bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-dark disabled:opacity-50"
            >
                {{ submitting ? 'Submitting…' : (editingRunId ? 'Resubmit gap analysis' : 'Run gap analysis') }}
            </button>
        </form>
    </div>
</template>

<script>
import { mapActions, mapGetters } from 'vuex';

export default {
    name: 'RunCreatePage',

    props: {
        // Set when reached via the "Edit" flow on an existing run (plan J5: Runs are immutable —
        // this never mutates that run, it prefills this form from it and submit() creates a new,
        // separately-approved run linked via previous_run_id).
        editingRunId: { type: [String, Number], default: null },
    },

    data() {
        return {
            form: {
                repo_config_id: null,
                requirement_text: '',
                target_file_path: '',
            },
            submitting: false,
            error: null,
            // A real newline, not the `&#10;` HTML-entity form: a static template attribute
            // sets this via setAttribute() rather than HTML parsing, so entities are never
            // decoded and render as literal text — confirmed by a real rendering bug caught
            // in a live browser screenshot.
            requirementPlaceholder: 'As a ... I want ... so that ...\n\nAcceptance criteria:\n- ...',
        };
    },

    computed: {
        ...mapGetters('repoConfigs', { repoConfigsList: 'list' }),

        selectedRepo() {
            return this.repoConfigsList.find((r) => r.id === this.form.repo_config_id);
        },

        selectedRepoMissingDockerImage() {
            return !!this.selectedRepo && !this.selectedRepo.has_docker_image;
        },
    },

    created() {
        this.fetchRepoConfigs();

        if (this.editingRunId) {
            this.fetchRun(this.editingRunId).then((run) => {
                if (!run) return;
                this.form.repo_config_id = run.repo_config_id;
                this.form.requirement_text = run.requirement_text;
                this.form.target_file_path = run.target_file_path;
            });
        }
    },

    methods: {
        ...mapActions('runs', ['createRun', 'fetchRun']),
        ...mapActions('repoConfigs', ['fetchRepoConfigs']),

        async submit() {
            this.submitting = true;
            this.error = null;
            try {
                const payload = this.editingRunId
                    ? { ...this.form, previous_run_id: this.editingRunId }
                    : this.form;
                const run = await this.createRun(payload);
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
