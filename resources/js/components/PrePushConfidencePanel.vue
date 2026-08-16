<template>
    <section class="mb-6 rounded-md border border-border bg-surface p-4">
        <h2 class="mb-3 border-l-4 border-primary pl-3 text-base font-bold text-fg-strong">Pre-Push Confidence</h2>

        <div class="mb-4 inline-flex items-baseline gap-2 rounded-full border-2 px-6 py-3" :class="bandClasses">
            <span class="text-3xl font-extrabold">{{ breakdown.composite }}%</span>
            <span class="text-sm font-semibold">({{ band }})</span>
        </div>

        <p class="mb-4 text-sm text-fg-muted">
            CI has not run yet — nothing has been pushed. This score excludes the CI component and
            re-weights the other three so they still sum to 100; the final confidence report
            (generated after CI concludes) supersedes this one.
        </p>

        <div class="overflow-hidden rounded-md border border-border">
            <table class="w-full text-sm">
                <thead class="bg-surface-alt text-left text-xs font-semibold uppercase text-fg-muted">
                    <tr>
                        <th class="px-4 py-2">Component</th>
                        <th class="px-4 py-2">Score</th>
                        <th class="px-4 py-2">Weight</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    <tr v-for="row in rows" :key="row.label">
                        <td class="px-4 py-2 text-fg">{{ row.label }}</td>
                        <td class="px-4 py-2 text-fg">{{ row.score }}%</td>
                        <td class="px-4 py-2 text-fg">{{ row.weight }}%</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
</template>

<script>
// Same data (run.pre_push_confidence) and weights as buildPrePushReportHtml()'s own
// confidenceSection() in lib/prePushReport.js, shown inline on the page instead of only inside
// the downloadable standalone report — the weights (25/20/25) are this org's nominal
// boschifai.confidence_weights config values, not renormalized, matching that file exactly.
export default {
    name: 'PrePushConfidencePanel',

    props: {
        breakdown: { type: Object, required: true },
    },

    computed: {
        rows() {
            return [
                { label: 'Testability / Codebase understanding', score: this.breakdown.testability, weight: 25 },
                { label: 'Test-case generation quality', score: this.breakdown.test_case_quality, weight: 20 },
                { label: 'Local execution', score: this.breakdown.local_execution, weight: 25 },
            ];
        },

        band() {
            if (this.breakdown.composite >= 80) return 'Green';
            if (this.breakdown.composite >= 60) return 'Yellow';
            return 'Red';
        },

        bandClasses() {
            return {
                Green: 'border-success text-success',
                Yellow: 'border-warning text-warning',
                Red: 'border-danger text-danger',
            }[this.band];
        },
    },
};
</script>
