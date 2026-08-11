<template>
    <div>
        <h1 class="text-xl font-bold text-fg-strong mb-6">Runs</h1>

        <p v-if="loading" class="text-fg-muted">Loading…</p>
        <p v-else-if="error" class="text-danger">{{ error }}</p>
        <p v-else-if="!list.length" class="text-fg-muted">
            No runs yet. <router-link :to="{ name: 'runs.create' }" class="text-primary underline">Submit a requirement</router-link> to start one.
        </p>

        <table v-else class="w-full text-sm border border-border rounded-md overflow-hidden">
            <thead class="bg-surface-alt text-left text-fg-muted">
                <tr>
                    <th class="px-4 py-2 font-semibold">Requirement</th>
                    <th class="px-4 py-2 font-semibold">Repo</th>
                    <th class="px-4 py-2 font-semibold">State</th>
                    <th class="px-4 py-2 font-semibold">Confidence</th>
                    <th class="px-4 py-2 font-semibold">Updated</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody class="bg-surface">
                <tr
                    v-for="run in list"
                    :key="run.id"
                    class="border-t border-border transition hover:bg-surface-alt cursor-pointer"
                    @click="$router.push({ name: 'runs.show', params: { id: run.id } })"
                >
                    <td class="px-4 py-2 max-w-xs truncate text-fg">{{ run.requirement_summary }}</td>
                    <td class="px-4 py-2 text-fg">{{ run.repo_name }}</td>
                    <td class="px-4 py-2">
                        <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="stateClasses(run.state)">
                            {{ run.state }}
                        </span>
                    </td>
                    <td class="px-4 py-2 text-fg">
                        <span v-if="run.confidence_score !== null">{{ run.confidence_score }}%</span>
                        <span v-else class="text-fg-muted">—</span>
                    </td>
                    <td class="px-4 py-2 text-fg-muted">{{ run.updated_at }}</td>
                    <td class="px-4 py-2 text-right">
                        <button
                            v-if="isEditable(run.state)"
                            type="button"
                            class="mr-3 text-fg-muted transition hover:text-primary"
                            title="Edit run"
                            @click.stop="$router.push({ name: 'runs.edit', params: { id: run.id } })"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4">
                                <path d="M13.586 3.586a2 2 0 1 1 2.828 2.828l-.793.793-2.828-2.828.793-.793ZM11.379 5.793 3 14.172V17h2.828l8.38-8.379-2.83-2.828Z" />
                            </svg>
                        </button>
                        <button
                            type="button"
                            :disabled="deletingId === run.id"
                            class="text-fg-muted transition hover:text-danger disabled:opacity-50"
                            title="Delete run"
                            @click.stop="deleteRunWithConfirm(run)"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4">
                                <path
                                    fill-rule="evenodd"
                                    d="M8.75 1A2.75 2.75 0 0 0 6 3.75v.443c-.795.077-1.584.176-2.365.298a.75.75 0 1 0 .23 1.482l.149-.022.841 10.518A2.75 2.75 0 0 0 7.596 19h4.807a2.75 2.75 0 0 0 2.742-2.53l.841-10.52.149.023a.75.75 0 0 0 .23-1.482 41.03 41.03 0 0 0-2.365-.298V3.75A2.75 2.75 0 0 0 11.25 1h-2.5ZM10 4c.84 0 1.673.025 2.5.075V3.75c0-.69-.56-1.25-1.25-1.25h-2.5c-.69 0-1.25.56-1.25 1.25v.325C8.327 4.025 9.16 4 10 4ZM8.58 7.72a.75.75 0 0 0-1.5.06l.3 7.5a.75.75 0 1 0 1.5-.06l-.3-7.5Zm4.34.06a.75.75 0 1 0-1.5-.06l-.3 7.5a.75.75 0 1 0 1.5.06l.3-7.5Z"
                                    clip-rule="evenodd"
                                />
                            </svg>
                        </button>
                    </td>
                </tr>
            </tbody>
        </table>

        <confirm-dialog
            :open="!!pendingDelete"
            title="Delete this run permanently?"
            :message="deleteConfirmMessage"
            confirm-label="Delete"
            @confirm="confirmDelete"
            @cancel="pendingDelete = null"
        />
    </div>
</template>

<script>
import { mapGetters, mapActions } from 'vuex';
import ConfirmDialog from '../components/ConfirmDialog.vue';

const HUMAN_GATE_STATES = ['gap_analysis_ready', 'local_execution_complete'];
const TERMINAL_BAD_STATES = ['gap_analysis_rejected', 'push_rejected', 'generation_blocked', 'failed'];

// Runs are immutable (plan J5) — "Edit" never mutates a row, it opens a prefilled form that
// creates a brand-new linked run on submit. Only offered where "edit and resubmit" makes sense:
// nothing generated yet or worth keeping (draft/failed/cancelled), or a human already rejected
// it (gap_analysis_rejected/generation_blocked/push_rejected). Excluded: human-review gates on
// real generated artifacts (gap_analysis_ready/local_execution_complete), anything mid-flight
// (*_running/pushing/ci_pending), and successful terminal runs (report_ready).
const EDITABLE_STATES = ['draft', 'failed', 'cancelled', 'gap_analysis_rejected', 'generation_blocked', 'push_rejected'];

export default {
    name: 'RunsIndexPage',

    components: { ConfirmDialog },

    data() {
        return {
            deletingId: null,
            pendingDelete: null,
        };
    },

    computed: {
        ...mapGetters('runs', ['list', 'loading', 'error']),

        deleteConfirmMessage() {
            if (!this.pendingDelete) return '';
            return `"${this.pendingDelete.requirement_summary}"\n\nThis also removes its worktree and generated artifacts. This cannot be undone.`;
        },
    },

    created() {
        this.fetchRuns();
    },

    methods: {
        ...mapActions('runs', ['fetchRuns', 'deleteRun']),

        isEditable(state) {
            return EDITABLE_STATES.includes(state);
        },

        stateClasses(state) {
            // Neutral, not danger-red: cancelling is a deliberate user action, not a failure.
            if (state === 'cancelled') return 'bg-surface-alt text-fg-muted';
            if (state === 'report_ready') return 'bg-success/10 text-success';
            if (TERMINAL_BAD_STATES.includes(state)) return 'bg-danger/10 text-danger';
            if (HUMAN_GATE_STATES.includes(state)) return 'bg-warning/10 text-warning';
            return 'bg-primary/10 text-primary-dark';
        },

        deleteRunWithConfirm(run) {
            this.pendingDelete = run;
        },

        async confirmDelete() {
            const run = this.pendingDelete;
            this.pendingDelete = null;
            this.deletingId = run.id;
            try {
                await this.deleteRun(run.id);
            } finally {
                this.deletingId = null;
            }
        },
    },
};
</script>
