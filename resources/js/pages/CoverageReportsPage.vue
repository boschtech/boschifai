<template>
    <div>
        <h1 class="text-xl font-bold text-fg-strong mb-1">Coverage Reports</h1>
        <p class="text-sm text-fg-muted mb-6">Every generated test file, its local execution result, and its coverage report (where available).</p>

        <p v-if="loading" class="text-fg-muted">Loading…</p>
        <p v-else-if="error" class="text-danger">{{ error }}</p>
        <template v-else>
            <div class="mb-6 grid grid-cols-2 gap-4 sm:grid-cols-4">
                <div class="rounded-md border border-border bg-surface p-4">
                    <p class="text-xs font-semibold uppercase text-fg-muted">Files generated</p>
                    <p class="text-2xl font-bold text-fg-strong">{{ coveredRuns.length }}</p>
                </div>
                <div class="rounded-md border border-border bg-surface p-4">
                    <p class="text-xs font-semibold uppercase text-fg-muted">Tests run locally</p>
                    <p class="text-2xl font-bold text-fg-strong">{{ totals.total }}</p>
                </div>
                <div class="rounded-md border border-border bg-surface p-4">
                    <p class="text-xs font-semibold uppercase text-fg-muted">Passed</p>
                    <p class="text-2xl font-bold text-success">{{ totals.passed }}</p>
                </div>
                <div class="rounded-md border border-border bg-surface p-4">
                    <p class="text-xs font-semibold uppercase text-fg-muted">Failed</p>
                    <p class="text-2xl font-bold" :class="totals.failed > 0 ? 'text-danger' : 'text-fg-strong'">{{ totals.failed }}</p>
                </div>
            </div>

            <p v-if="!coveredRuns.length" class="text-fg-muted">No generated test files yet.</p>
            <div v-else class="overflow-x-auto rounded-md border border-border">
            <table class="w-full min-w-max text-sm">
                <thead class="bg-surface-alt text-left text-fg-muted">
                    <tr>
                        <th class="px-4 py-2 font-semibold">Requirement</th>
                        <th class="px-4 py-2 font-semibold">Repo</th>
                        <th class="px-4 py-2 font-semibold">Generated File</th>
                        <th class="px-4 py-2 font-semibold">Tests</th>
                        <th class="px-4 py-2 font-semibold">Duration</th>
                        <th class="px-4 py-2 font-semibold">Testability</th>
                        <th class="px-4 py-2 font-semibold">Coverage Report</th>
                        <th class="px-4 py-2 font-semibold">Updated</th>
                    </tr>
                </thead>
                <tbody class="bg-surface">
                    <tr
                        v-for="run in coveredRuns"
                        :key="run.id"
                        class="border-t border-border transition hover:bg-surface-alt cursor-pointer"
                        @click="$router.push({ name: 'runs.show', params: { id: run.id } })"
                    >
                        <td class="px-4 py-2 max-w-xs truncate text-fg">{{ run.requirement_summary }}</td>
                        <td class="px-4 py-2 text-fg">{{ run.repo_name }}</td>
                        <td class="px-4 py-2 max-w-xs truncate font-mono text-xs text-fg">{{ run.generated_file_path }}</td>
                        <td class="px-4 py-2">
                            <span v-if="run.execution_result" class="rounded-full px-2 py-0.5 text-xs font-semibold" :class="testBadgeClasses(run.execution_result)">
                                {{ run.execution_result.passed }}/{{ run.execution_result.total }} passed
                            </span>
                            <span v-else class="text-fg-muted">Not run yet</span>
                        </td>
                        <td class="px-4 py-2 text-fg-muted">{{ run.execution_result ? `${run.execution_result.duration_ms}ms` : '—' }}</td>
                        <td class="px-4 py-2 text-fg">{{ run.testability_score !== null ? run.testability_score + '%' : '—' }}</td>
                        <td class="px-4 py-2">
                            <a
                                v-if="run.execution_result && run.execution_result.coverage_report_url"
                                :href="run.execution_result.coverage_report_url"
                                target="_blank"
                                rel="noopener"
                                class="text-primary underline"
                                @click.stop
                            >
                                View
                            </a>
                            <span v-else class="text-fg-muted">—</span>
                        </td>
                        <td class="px-4 py-2 text-fg-muted">{{ run.updated_at }}</td>
                    </tr>
                </tbody>
            </table>
            </div>
        </template>
    </div>
</template>

<script>
import { mapGetters, mapActions } from 'vuex';

export default {
    name: 'CoverageReportsPage',

    computed: {
        ...mapGetters('runs', ['list', 'loading', 'error']),

        // Only runs that actually reached code generation are "coverage" in any meaningful
        // sense — a draft or a run that failed before generating anything has nothing to show
        // here, and would just be noise next to the ones that do.
        coveredRuns() {
            return this.list.filter((run) => run.generated_file_path !== null);
        },

        totals() {
            return this.coveredRuns.reduce(
                (totals, run) => {
                    if (!run.execution_result) return totals;
                    totals.total += run.execution_result.total;
                    totals.passed += run.execution_result.passed;
                    totals.failed += run.execution_result.failed;
                    return totals;
                },
                { total: 0, passed: 0, failed: 0 }
            );
        },
    },

    created() {
        this.fetchRuns();
    },

    methods: {
        ...mapActions('runs', ['fetchRuns']),

        testBadgeClasses(executionResult) {
            return executionResult.failed > 0 ? 'bg-danger/10 text-danger' : 'bg-success/10 text-success';
        },
    },
};
</script>
