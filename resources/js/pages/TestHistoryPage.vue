<template>
    <div>
        <h1 class="text-xl font-bold text-fg-strong mb-1">Test History</h1>
        <p class="text-sm text-fg-muted mb-6">A searchable history of every run, across every repo and state.</p>

        <div class="mb-4 flex flex-wrap items-center gap-3">
            <input
                v-model="search"
                type="text"
                placeholder="Search requirement, repo, or file…"
                class="min-w-64 flex-1 rounded-sm border border-border bg-surface px-3 py-1.5 text-sm text-fg placeholder:text-fg-muted"
            />
            <select v-model="stateFilter" class="rounded-sm border border-border bg-surface px-3 py-1.5 text-sm text-fg">
                <option value="">All states</option>
                <option v-for="state in availableStates" :key="state" :value="state">{{ state }}</option>
            </select>
            <select v-model="runTypeFilter" class="rounded-sm border border-border bg-surface px-3 py-1.5 text-sm text-fg">
                <option value="">All types</option>
                <option value="requirement">Requirement</option>
                <option value="coverage">Coverage</option>
            </select>
            <button
                type="button"
                class="rounded-sm border border-border bg-surface px-3 py-1.5 text-sm font-medium text-fg transition hover:bg-surface-alt"
                @click="toggleArchived"
            >
                {{ showArchived ? 'Show active runs' : 'Include archived' }}
            </button>
        </div>

        <p v-if="loading" class="text-fg-muted">Loading…</p>
        <p v-else-if="error" class="text-danger">{{ error }}</p>
        <p v-else-if="!filteredRuns.length" class="text-fg-muted">No runs match this search.</p>
        <div v-else class="overflow-x-auto rounded-md border border-border">
        <table class="w-full min-w-max text-sm">
            <thead class="bg-surface-alt text-left text-fg-muted">
                <tr>
                    <th class="px-4 py-2 font-semibold">Requirement</th>
                    <th class="px-4 py-2 font-semibold">Repo</th>
                    <th class="px-4 py-2 font-semibold">Type</th>
                    <th class="px-4 py-2 font-semibold">State</th>
                    <th class="px-4 py-2 font-semibold">Tests</th>
                    <th class="px-4 py-2 font-semibold">CI</th>
                    <th class="px-4 py-2 font-semibold">Updated</th>
                </tr>
            </thead>
            <tbody class="bg-surface">
                <tr
                    v-for="run in filteredRuns"
                    :key="run.id"
                    class="border-t border-border transition hover:bg-surface-alt cursor-pointer"
                    @click="$router.push({ name: 'runs.show', params: { id: run.id } })"
                >
                    <td class="px-4 py-2 max-w-xs truncate text-fg">{{ run.requirement_summary }}</td>
                    <td class="px-4 py-2 text-fg">{{ run.repo_name }}</td>
                    <td class="px-4 py-2 text-fg-muted capitalize">{{ run.run_type }}</td>
                    <td class="px-4 py-2">
                        <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="stateClasses(run.state)">{{ run.state }}</span>
                    </td>
                    <td class="px-4 py-2 text-fg">
                        <span v-if="run.execution_result">{{ run.execution_result.passed }}/{{ run.execution_result.total }}</span>
                        <span v-else class="text-fg-muted">—</span>
                    </td>
                    <td class="px-4 py-2">
                        <span v-if="run.ci_conclusion" class="rounded-full px-2 py-0.5 text-xs font-medium" :class="ciClasses(run.ci_conclusion)">{{ run.ci_conclusion }}</span>
                        <span v-else class="text-fg-muted">—</span>
                    </td>
                    <td class="px-4 py-2 text-fg-muted">{{ run.updated_at }}</td>
                </tr>
            </tbody>
        </table>
        </div>
    </div>
</template>

<script>
import { mapGetters, mapActions } from 'vuex';

const HUMAN_GATE_STATES = ['gap_analysis_ready', 'local_execution_complete'];
const TERMINAL_BAD_STATES = ['gap_analysis_rejected', 'push_rejected', 'generation_blocked', 'failed'];

export default {
    name: 'TestHistoryPage',

    data() {
        return {
            search: '',
            stateFilter: '',
            runTypeFilter: '',
            showArchived: false,
        };
    },

    computed: {
        ...mapGetters('runs', ['list', 'loading', 'error']),

        availableStates() {
            return [...new Set(this.list.map((run) => run.state))].sort();
        },

        filteredRuns() {
            const search = this.search.trim().toLowerCase();

            return this.list.filter((run) => {
                if (this.stateFilter && run.state !== this.stateFilter) return false;
                if (this.runTypeFilter && run.run_type !== this.runTypeFilter) return false;
                if (!search) return true;

                return [run.requirement_summary, run.repo_name, run.generated_file_path, run.target_file_path]
                    .filter(Boolean)
                    .some((field) => field.toLowerCase().includes(search));
            });
        },
    },

    created() {
        this.fetchRuns({ archived: this.showArchived });
    },

    methods: {
        ...mapActions('runs', ['fetchRuns']),

        toggleArchived() {
            this.showArchived = !this.showArchived;
            this.fetchRuns({ archived: this.showArchived });
        },

        // Matches RunsIndexPage.vue's own state-badge treatment, so a run's state colour reads
        // the same whether seen here or there.
        stateClasses(state) {
            if (state === 'cancelled') return 'bg-surface-alt text-fg-muted';
            if (state === 'report_ready') return 'bg-success/10 text-success';
            if (TERMINAL_BAD_STATES.includes(state)) return 'bg-danger/10 text-danger';
            if (HUMAN_GATE_STATES.includes(state)) return 'bg-warning/10 text-warning';
            return 'bg-primary/10 text-primary-dark';
        },

        ciClasses(conclusion) {
            if (conclusion === 'success') return 'bg-success/10 text-success';
            if (conclusion === 'failure' || conclusion === 'cancelled') return 'bg-danger/10 text-danger';
            return 'bg-primary/10 text-primary-dark';
        },
    },
};
</script>
