<template>
    <section class="rounded-lg border border-border bg-surface p-6">
        <div class="flex items-center gap-3 mb-4">
            <span class="text-3xl font-bold" :class="bandTextClasses">{{ breakdown.composite }}%</span>
            <span class="rounded-full px-3 py-1 text-sm font-semibold" :class="bandClasses">{{ band }}</span>
        </div>

        <p class="text-xs text-fg-muted mb-4">
            These weights are a starting proposal for the team to tune, not a validated formula.
        </p>

        <div class="ml-2 overflow-hidden rounded-lg border border-border">
            <table class="w-full text-sm">
                <thead class="text-left text-fg-muted">
                    <tr>
                        <th class="py-2 pl-4 font-semibold">Component</th>
                        <th class="py-2 font-semibold">Score</th>
                        <th class="py-2 font-semibold">Weight</th>
                        <th class="py-2 font-semibold">Contribution</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in rows" :key="row.key" class="border-t border-border">
                        <td class="py-2 pl-4 text-fg">{{ row.label }}</td>
                        <td class="py-2 text-fg">{{ row.score }}%</td>
                        <td class="py-2 text-fg">{{ row.weight }}%</td>
                        <td class="py-2 text-fg">{{ row.contribution.toFixed(1) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
</template>

<script>
const WEIGHTS = {
    testability: 25,
    test_case_quality: 20,
    local_execution: 25,
    ci: 30,
};

const LABELS = {
    requirement: {
        testability: 'Testability',
        test_case_quality: 'Test-case generation quality',
        local_execution: 'Local execution',
        ci: 'CI (Sonar Scan)',
    },
    coverage: {
        testability: 'Codebase understanding',
        test_case_quality: 'Test-case generation quality',
        local_execution: 'Local execution',
        ci: 'CI (Sonar Scan)',
    },
};

export default {
    name: 'ConfidenceReportCard',

    props: {
        breakdown: { type: Object, required: true },
        runType: { type: String, default: 'requirement' },
    },

    computed: {
        labels() {
            return LABELS[this.runType] ?? LABELS.requirement;
        },

        band() {
            const score = this.breakdown.composite;
            if (score >= 80) return 'Green';
            if (score >= 60) return 'Yellow';
            return 'Red';
        },

        bandClasses() {
            return {
                Green: 'bg-success/10 text-success',
                Yellow: 'bg-warning/10 text-warning',
                Red: 'bg-danger/10 text-danger',
            }[this.band];
        },

        bandTextClasses() {
            return {
                Green: 'text-success',
                Yellow: 'text-warning',
                Red: 'text-danger',
            }[this.band];
        },

        rows() {
            return Object.keys(WEIGHTS).map((key) => {
                const score = this.breakdown[key] ?? 0;
                const weight = WEIGHTS[key];
                return {
                    key,
                    label: this.labels[key],
                    score,
                    weight,
                    contribution: (score * weight) / 100,
                };
            });
        },
    },
};
</script>
