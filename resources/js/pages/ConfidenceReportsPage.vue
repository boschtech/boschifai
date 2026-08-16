<template>
    <div>
        <h1 class="text-xl font-bold text-fg-strong mb-1">Confidence Reports</h1>
        <p class="text-sm text-fg-muted mb-6">Every run's composite confidence score and the four components behind it.</p>

        <p v-if="loading" class="text-fg-muted">Loading…</p>
        <p v-else-if="error" class="text-danger">{{ error }}</p>
        <template v-else>
            <div class="mb-6 grid grid-cols-2 gap-4 sm:grid-cols-4">
                <div class="rounded-md border border-border bg-surface p-4">
                    <p class="text-xs font-semibold uppercase text-fg-muted">Scored runs</p>
                    <p class="text-2xl font-bold text-fg-strong">{{ scoredRuns.length }}</p>
                </div>
                <div class="rounded-md border border-border bg-surface p-4">
                    <p class="text-xs font-semibold uppercase text-fg-muted">Average confidence</p>
                    <p class="text-2xl font-bold text-fg-strong">{{ averageConfidence !== null ? averageConfidence + '%' : '—' }}</p>
                </div>
                <div class="rounded-md border border-border bg-surface p-4">
                    <p class="text-xs font-semibold uppercase text-fg-muted">Green (≥80%)</p>
                    <p class="text-2xl font-bold text-success">{{ bandCounts.Green }}</p>
                </div>
                <div class="rounded-md border border-border bg-surface p-4">
                    <p class="text-xs font-semibold uppercase text-fg-muted">Yellow / Red</p>
                    <p class="text-2xl font-bold text-warning">{{ bandCounts.Yellow }} <span class="text-danger">/ {{ bandCounts.Red }}</span></p>
                </div>
            </div>

            <p v-if="!list.length" class="text-fg-muted">No runs yet.</p>
            <div v-else class="overflow-x-auto rounded-md border border-border">
            <table class="w-full min-w-max text-sm">
                <thead class="bg-surface-alt text-left text-fg-muted">
                    <tr>
                        <th class="px-4 py-2 font-semibold">Requirement</th>
                        <th class="px-4 py-2 font-semibold">Repo</th>
                        <th class="px-4 py-2 font-semibold">Composite</th>
                        <th class="px-4 py-2 font-semibold">Testability</th>
                        <th class="px-4 py-2 font-semibold">Test-Case Quality</th>
                        <th class="px-4 py-2 font-semibold">Local Execution</th>
                        <th class="px-4 py-2 font-semibold">CI</th>
                        <th class="px-4 py-2 font-semibold">Updated</th>
                    </tr>
                </thead>
                <tbody class="bg-surface">
                    <tr
                        v-for="run in sortedRuns"
                        :key="run.id"
                        class="border-t border-border transition hover:bg-surface-alt cursor-pointer"
                        @click="$router.push({ name: 'runs.show', params: { id: run.id } })"
                    >
                        <td class="px-4 py-2 max-w-xs truncate text-fg">{{ run.requirement_summary }}</td>
                        <td class="px-4 py-2 text-fg">{{ run.repo_name }}</td>
                        <td class="px-4 py-2">
                            <span v-if="run.confidence_score !== null" class="rounded-full px-2 py-0.5 text-xs font-semibold" :class="bandClasses(run.confidence_score)">
                                {{ run.confidence_score }}%
                            </span>
                            <span v-else class="text-fg-muted">—</span>
                        </td>
                        <td class="px-4 py-2 text-fg">{{ component(run, 'testability') }}</td>
                        <td class="px-4 py-2 text-fg">{{ component(run, 'test_case_quality') }}</td>
                        <td class="px-4 py-2 text-fg">{{ component(run, 'local_execution') }}</td>
                        <td class="px-4 py-2 text-fg">{{ component(run, 'ci') }}</td>
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
    name: 'ConfidenceReportsPage',

    computed: {
        ...mapGetters('runs', ['list', 'loading', 'error']),

        scoredRuns() {
            return this.list.filter((run) => run.confidence_score !== null);
        },

        // Confidence is only known once a run reaches CI (PollCiStatusJob is what actually
        // stamps confidence_score/confidence_breakdown) — sorting scored runs to the top by
        // score, then everything still-unscored below by recency, keeps the report's headline
        // number where it's easy to scan instead of interleaved with dozens of "—" rows.
        sortedRuns() {
            return [...this.list].sort((a, b) => {
                if (a.confidence_score === null && b.confidence_score === null) return 0;
                if (a.confidence_score === null) return 1;
                if (b.confidence_score === null) return -1;
                return b.confidence_score - a.confidence_score;
            });
        },

        averageConfidence() {
            if (!this.scoredRuns.length) return null;
            const sum = this.scoredRuns.reduce((total, run) => total + run.confidence_score, 0);
            return Math.round(sum / this.scoredRuns.length);
        },

        bandCounts() {
            return this.scoredRuns.reduce(
                (counts, run) => {
                    counts[this.band(run.confidence_score)] += 1;
                    return counts;
                },
                { Green: 0, Yellow: 0, Red: 0 }
            );
        },
    },

    created() {
        this.fetchRuns();
    },

    methods: {
        ...mapActions('runs', ['fetchRuns']),

        // Same 80/60 thresholds as ConfidenceReportCard.vue's single-run breakdown — kept
        // consistent so a run's badge here matches its own page.
        band(score) {
            if (score >= 80) return 'Green';
            if (score >= 60) return 'Yellow';
            return 'Red';
        },

        bandClasses(score) {
            return {
                Green: 'bg-success/10 text-success',
                Yellow: 'bg-warning/10 text-warning',
                Red: 'bg-danger/10 text-danger',
            }[this.band(score)];
        },

        component(run, key) {
            return run.confidence_breakdown?.[key] !== undefined ? `${run.confidence_breakdown[key]}%` : '—';
        },
    },
};
</script>
