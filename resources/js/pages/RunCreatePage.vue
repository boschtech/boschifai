<template>
    <div>
        <h1 class="text-xl font-bold text-fg-strong mb-1">{{ pageHeading }}</h1>
        <p v-if="editingRunId" class="text-sm text-fg-muted mb-6">
            Runs are never modified in place — submitting here creates a new run linked to the original;
            the original is left untouched and stays in the list.
        </p>

        <p class="text-sm text-fg-muted mb-6">
            <template v-if="form.run_type === 'coverage'">
                Scan the source code for specific functionality and create a requirement from that —
                Boschifai reads the repo itself, works out what's under-tested, and proposes test cases
                for you to review before generating anything.
            </template>
            <template v-else>
                From this page, you can use free text to enter the requirement in plain language.
                Boschifai reviews it, then generates tests against a repository connected to Boschifai.
            </template>
        </p>

        <form @submit.prevent="submit">
            <template v-if="editingRunId">
                <label class="block text-sm font-medium text-fg mb-1">Target repository</label>
                <select
                    v-model="form.repo_config_id"
                    required
                    class="w-full rounded-sm border border-border bg-surface px-3 py-2 text-sm text-fg mb-1 transition focus:outline-none focus:border-primary"
                >
                    <option :value="null" disabled>Select a repository…</option>
                    <option v-for="repo in repoConfigsList" :key="repo.id" :value="repo.id">
                        {{ repo.display_name }}
                    </option>
                </select>
            </template>
            <template v-else>
                <label class="block text-sm font-medium text-fg mb-1">Target repositories</label>
                <p class="text-xs text-fg-muted mb-2">
                    Select one or more — an independent run is started for each repository.
                </p>
                <multi-select-dropdown
                    v-model="selectedRepoIds"
                    :options="repoOptions"
                    placeholder="Select a repository…"
                    empty-message="No repositories connected yet."
                    class="mb-1"
                />
            </template>
            <p class="text-xs text-fg-muted mb-6">
                Don't see your repository?
                <router-link :to="{ name: 'settings.github' }" class="text-primary underline">Connect it here</router-link>.
            </p>

            <label class="block text-sm font-medium text-fg mb-1">
                {{ form.run_type === 'coverage' ? 'Coverage instruction' : 'Requirement text' }}
            </label>
            <textarea
                v-model="form.requirement_text"
                rows="10"
                required
                class="w-full rounded-sm border border-border bg-surface px-3 py-2 text-sm text-fg mb-4 transition focus:outline-none focus:border-primary"
                :placeholder="form.run_type === 'coverage' ? coverageInstructionPlaceholder : requirementPlaceholder"
            ></textarea>

            <template v-if="form.run_type === 'requirement'">
                <label class="block text-sm font-medium text-fg mb-1">Target file</label>
                <p class="text-xs text-fg-muted mb-1">
                    Path (within the target repo) to the controller/service this requirement targets, e.g.
                    <code class="text-fg">app/Http/Controllers/CustomFieldController.php</code>.
                    Required — Boschifai does not guess the target on a bank-integrated, multi-tenant codebase.
                    <template v-if="!editingRunId && selectedRepoIds.length > 1">
                        The same path is used for every selected repository.
                    </template>
                </p>
                <div class="flex gap-2 mb-1">
                    <input
                        v-model="form.target_file_path"
                        type="text"
                        required
                        class="w-full rounded-sm border border-border bg-surface px-3 py-2 text-sm text-fg font-mono transition focus:outline-none focus:border-primary"
                        placeholder="app/Http/Controllers/..."
                    />
                    <button
                        type="button"
                        :disabled="!browseRepoId"
                        :title="browseRepoId ? `Browse ${browseRepoDisplayName}` : 'Select a repository first'"
                        class="shrink-0 rounded-sm border border-border bg-surface px-3 py-2 text-sm font-medium text-fg transition hover:bg-surface-alt disabled:opacity-50"
                        @click="fileBrowserOpen = true"
                    >
                        Browse files…
                    </button>
                </div>
                <p class="text-xs text-fg-muted mb-6">
                    Only lists a repository that's been checked out already (i.e. it's had at least one run) —
                    otherwise there's nothing on disk yet to browse; type the path directly instead.
                </p>

                <label class="block text-sm font-medium text-fg mb-1">Attach supporting files (optional)</label>
                <p class="text-xs text-fg-muted mb-1">
                    Reference material from your own machine — a spec doc, a screenshot, a sample payload —
                    copied alongside the requirement for extra context. Like the requirement text itself,
                    this is sent to an AI model and stored:
                    <strong>never attach real customer data, ID numbers, or banking details.</strong>
                </p>
                <div class="mb-6">
                    <input
                        type="file"
                        multiple
                        class="mb-1 block w-full text-sm text-fg file:mr-3 file:rounded-sm file:border-0 file:bg-surface-alt file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-fg hover:file:bg-border"
                        @change="onAttachmentsChosen"
                    />
                    <ul v-if="attachments.length" class="space-y-1">
                        <li
                            v-for="(file, index) in attachments"
                            :key="`${file.name}-${index}`"
                            class="flex items-center justify-between rounded-sm border border-border bg-surface px-3 py-1.5 text-sm text-fg"
                        >
                            <span class="truncate">{{ file.name }}</span>
                            <button type="button" class="ml-2 shrink-0 text-fg-muted transition hover:text-danger" @click="removeAttachment(index)">
                                Remove
                            </button>
                        </li>
                    </ul>
                </div>
            </template>
            <p v-else class="text-xs text-fg-muted mb-6">
                No target file needed — Boschifai proposes one after it analyzes the repo, for you to
                confirm at the review gate below.
            </p>

            <p v-if="error" class="text-sm text-danger mb-4">{{ error }}</p>

            <button
                type="submit"
                :disabled="submitting || !canSubmit"
                class="rounded-sm bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-dark disabled:opacity-50"
            >
                {{ submitting ? 'Submitting…' : submitLabel }}
            </button>
        </form>

        <repo-file-browser
            :open="fileBrowserOpen"
            :repo-config-id="browseRepoId"
            :repo-display-name="browseRepoDisplayName"
            @select="onTargetFileSelected"
            @close="fileBrowserOpen = false"
        />
    </div>
</template>

<script>
import { mapActions, mapGetters } from 'vuex';
import MultiSelectDropdown from '../components/MultiSelectDropdown.vue';
import RepoFileBrowser from '../components/RepoFileBrowser.vue';

export default {
    name: 'RunCreatePage',

    components: { MultiSelectDropdown, RepoFileBrowser },

    props: {
        // Set when reached via the "Edit" flow on an existing run (plan J5: Runs are immutable —
        // this never mutates that run, it prefills this form from it and submit() creates a new,
        // separately-approved run linked via previous_run_id).
        editingRunId: { type: [String, Number], default: null },
        // Fixed per-route (see router/index.js's two 'runs.create'/'runs.create.coverage'
        // routes) — the sidebar link the user clicked IS the mode selector now, so this page no
        // longer shows its own in-page toggle between modes.
        runType: { type: String, default: 'requirement' },
    },

    data() {
        return {
            form: {
                run_type: this.runType,
                repo_config_id: null,
                requirement_text: '',
                target_file_path: '',
            },
            // Only used for a fresh (non-edit) submission — see the template's v-else branch.
            // Each RepoConfig has its own persistent checkout (RepoCheckoutManager) and the
            // "one active run per repo" guard in RunController::store() is already scoped per
            // repo, so running the same requirement/instruction against several repos at once
            // is just several independent Runs, not a change to what a Run itself represents.
            selectedRepoIds: [],
            fileBrowserOpen: false,
            // Raw File objects — deliberately NOT part of `form`, since form gets spread
            // straight into a plain-JSON createRun payload in the common (no-attachments) case;
            // Files aren't JSON-serializable, so submit() branches to FormData only when this
            // is non-empty (see submit()'s own comment).
            attachments: [],
            submitting: false,
            error: null,
            // A real newline, not the `&#10;` HTML-entity form: a static template attribute
            // sets this via setAttribute() rather than HTML parsing, so entities are never
            // decoded and render as literal text — confirmed by a real rendering bug caught
            // in a live browser screenshot.
            requirementPlaceholder: 'As a ... I want ... so that ...\n\nAcceptance criteria:\n- ...',
            coverageInstructionPlaceholder: 'Increase test coverage for the billing module\'s refund calculation.',
        };
    },

    computed: {
        ...mapGetters('repoConfigs', { repoConfigsList: 'list' }),

        repoOptions() {
            return this.repoConfigsList.map((repo) => ({ value: repo.id, label: repo.display_name }));
        },

        // Which repo the file browser opens against: the one being edited, or the first
        // selected repo in the fresh-submission multi-select. A single target_file_path already
        // applies across every selected repo (see the multi-select's own submission logic), so
        // "browse the first one" is consistent with that existing simplification rather than a
        // new inconsistency the browser introduces.
        browseRepoId() {
            return this.editingRunId ? this.form.repo_config_id : (this.selectedRepoIds[0] ?? null);
        },

        browseRepoDisplayName() {
            return this.repoConfigsList.find((repo) => repo.id === this.browseRepoId)?.display_name ?? '';
        },

        pageHeading() {
            if (this.editingRunId) return 'Edit & resubmit';
            return this.form.run_type === 'requirement' ? 'Write Requirement' : 'Code Requirement';
        },

        canSubmit() {
            return this.editingRunId ? !!this.form.repo_config_id : this.selectedRepoIds.length > 0;
        },

        submitLabel() {
            if (this.editingRunId) return 'Resubmit gap analysis';
            return this.selectedRepoIds.length > 1
                ? `Run gap analysis (×${this.selectedRepoIds.length} repos)`
                : 'Run gap analysis';
        },
    },

    // 'Write Requirement', 'Code Requirement', and 'Edit an existing run' are three different
    // routes that all render this same component (see router/index.js) — Vue
    // Router reuses the existing instance when navigating between routes matched to the same
    // component, so `created()` alone only ever ran once, on the very first visit. Clicking
    // between these sidebar links kept showing whatever the FIRST one loaded until a full page
    // reload — confirmed as a real bug via live testing. Watching the two props that actually
    // differ per route and re-running the same initialization logic on either fixes this
    // without needing a $route watcher (route/query changes irrelevant to this page, like
    // scroll restoration, would otherwise trigger a needless reset).
    watch: {
        editingRunId: 'initializeForm',
        runType: 'initializeForm',
    },

    created() {
        this.fetchRepoConfigs();
        this.initializeForm();
    },

    methods: {
        ...mapActions('runs', ['createRun', 'fetchRun']),
        ...mapActions('repoConfigs', ['fetchRepoConfigs']),

        initializeForm() {
            this.error = null;
            this.selectedRepoIds = [];
            this.attachments = [];
            this.fileBrowserOpen = false;
            this.form = {
                run_type: this.runType,
                repo_config_id: null,
                requirement_text: '',
                target_file_path: '',
            };

            if (this.editingRunId) {
                this.fetchRun(this.editingRunId).then((run) => {
                    if (!run) return;
                    this.form.run_type = run.run_type;
                    this.form.repo_config_id = run.repo_config_id;
                    this.form.requirement_text = run.requirement_text;
                    this.form.target_file_path = run.target_file_path;
                });
            }
        },

        onTargetFileSelected(path) {
            this.form.target_file_path = path;
        },

        onAttachmentsChosen(event) {
            // Accumulate rather than replace, and clear the input's own value afterwards — both
            // so a second file-picker use adds to the list instead of wiping it, and so
            // re-choosing the exact same file again still fires a change event.
            this.attachments = [...this.attachments, ...Array.from(event.target.files)];
            event.target.value = '';
        },

        removeAttachment(index) {
            this.attachments.splice(index, 1);
        },

        // Plain object in the common case (createRun's axios call sends it as JSON, unchanged
        // from before attachments existed); a FormData instance only when files are attached —
        // File objects aren't JSON-serializable, and axios sends a FormData body as real
        // multipart/form-data automatically once it sees the instance.
        buildPayload(overrides) {
            const fields = {
                ...this.form,
                ...(this.form.run_type === 'coverage' ? { target_file_path: undefined } : {}),
                ...overrides,
            };

            if (!this.attachments.length) {
                return fields;
            }

            const formData = new FormData();
            Object.entries(fields).forEach(([key, value]) => {
                if (value !== undefined && value !== null) {
                    formData.append(key, value);
                }
            });
            this.attachments.forEach((file) => formData.append('attachments[]', file));

            return formData;
        },

        async submit() {
            this.submitting = true;
            this.error = null;
            try {
                if (this.editingRunId) {
                    const run = await this.createRun(this.buildPayload({ previous_run_id: this.editingRunId }));
                    this.$router.push({ name: 'runs.show', params: { id: run.id } });

                    return;
                }

                await this.submitToSelectedRepos();
            } catch (e) {
                this.error = e.response?.data?.message || e.message;
            } finally {
                this.submitting = false;
            }
        },

        // Fires one independent createRun per selected repo — allSettled, not all(), so one
        // repo already having a run in progress (a real 422 from RunController::store's own
        // guard) doesn't abort the rest of the batch. Only navigates away on a clean sweep
        // (matches the single-repo case exactly, which is just N=1 of this); any failure keeps
        // the user on this page with a summary of what did/didn't start, since silently losing
        // track of which repos never got a run would be worse than an extra click.
        async submitToSelectedRepos() {
            const results = await Promise.allSettled(
                this.selectedRepoIds.map((repoConfigId) => this.createRun(this.buildPayload({ repo_config_id: repoConfigId })))
            );

            const succeeded = results.filter((r) => r.status === 'fulfilled').map((r) => r.value);
            const failed = results
                .map((r, index) => ({ result: r, repo: this.repoConfigsList.find((c) => c.id === this.selectedRepoIds[index]) }))
                .filter(({ result }) => result.status === 'rejected');

            if (failed.length === 0) {
                if (succeeded.length === 1) {
                    this.$router.push({ name: 'runs.show', params: { id: succeeded[0].id } });
                } else {
                    this.$router.push({ name: 'runs.index' });
                }

                return;
            }

            const failureDetails = failed
                .map(({ result, repo }) => `${repo?.display_name ?? 'unknown repo'}: ${result.reason?.response?.data?.message || result.reason?.message}`)
                .join('; ');
            this.error = `${succeeded.length} of ${results.length} run(s) started. Failed — ${failureDetails}`;
        },
    },
};
</script>
