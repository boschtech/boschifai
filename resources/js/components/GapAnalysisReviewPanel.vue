<template>
    <div>
        <!-- Duplicate of the action row at the bottom of this panel — approving/rejecting is
             the whole point of Gate 1, and a long testability review/test-plan document
             otherwise forces a scroll past everything just to act. Reject/Request changes still
             opens its comment box at the bottom (a written reason needs real reading room), but
             Approve works immediately from here. -->
        <div class="mb-6 flex flex-wrap items-center gap-3">
            <button
                type="button"
                class="rounded-sm bg-success px-4 py-2 text-sm font-semibold text-white transition hover:opacity-90"
                @click="$emit('approve')"
            >
                Approve &amp; Generate
            </button>
            <button
                type="button"
                class="rounded-sm bg-surface border border-border px-4 py-2 text-sm font-medium text-fg transition hover:bg-surface-alt"
                @click="showRequestChanges = true"
            >
                Request changes
            </button>
            <button
                type="button"
                class="rounded-sm bg-surface border border-danger/40 px-4 py-2 text-sm font-medium text-danger transition hover:bg-danger/10"
                @click="showReject = true"
            >
                Reject
            </button>
        </div>

        <div v-if="testabilityScore !== null" class="mb-6 flex items-center gap-4 rounded-md border border-border bg-surface p-4">
            <score-donut-chart :score="testabilityScore" :size="96" :stroke-width="12" />
            <div>
                <h2 class="text-sm font-semibold text-fg">{{ isCoverage ? 'Codebase Understanding Score' : 'Testability Score' }}</h2>
                <div class="mt-1 flex items-center gap-2">
                    <span class="rounded-full px-2 py-0.5 text-xs font-semibold" :class="bandClasses">{{ band }}</span>
                    <span v-if="testCaseCount > 0" class="rounded-full bg-surface-alt px-2 py-0.5 text-xs font-semibold text-fg-muted">
                        {{ testCaseCount }} test case{{ testCaseCount === 1 ? '' : 's' }}
                    </span>
                </div>
            </div>
        </div>

        <div v-if="band === 'Red'" class="mb-6 rounded-md border border-danger/30 bg-danger/10 p-4 text-sm text-danger">
            <template v-if="isCoverage">
                <strong>Red — Boschifai wasn't confident it understood this repo well enough.</strong>
                Review the codebase analysis below carefully before approving; consider rejecting or
                requesting changes if the detected stack, files, or coverage gap look wrong.
            </template>
            <template v-else>
                <strong>Red — this requirement is likely to produce ambiguous or low-value test cases.</strong>
                Per BOSCHIFAI's own review standards, requirements scoring below 60% should typically be refined
                before generating tests. Consider rejecting or requesting changes below.
            </template>
        </div>

        <section v-if="isCoverage" class="mb-6">
            <h2 class="text-sm font-semibold text-fg mb-2">Codebase analysis</h2>
            <document-viewer :content="codebaseKnowledgeBaseMarkdown">
                <template #empty>No codebase analysis yet.</template>
            </document-viewer>
        </section>
        <section v-else class="mb-6">
            <h2 class="text-sm font-semibold text-fg mb-2">Testability review</h2>
            <document-viewer :content="testabilityReviewMarkdown">
                <template #empty>No testability review yet.</template>
            </document-viewer>
        </section>

        <section class="mb-6">
            <h2 class="text-sm font-semibold text-fg mb-2">{{ isCoverage ? 'Proposed test cases' : 'Test plan' }}</h2>
            <document-viewer :content="isCoverage ? testCasesMarkdown : testPlanMarkdown">
                <template #empty>{{ isCoverage ? 'No test cases yet.' : 'No test plan yet.' }}</template>
            </document-viewer>
        </section>

        <div class="flex flex-wrap items-center gap-3">
            <button
                type="button"
                class="rounded-sm bg-success px-4 py-2 text-sm font-semibold text-white transition hover:opacity-90"
                @click="$emit('approve')"
            >
                Approve &amp; Generate
            </button>
            <button
                type="button"
                class="rounded-sm bg-surface border border-border px-4 py-2 text-sm font-medium text-fg transition hover:bg-surface-alt"
                @click="showRequestChanges = true"
            >
                Request changes
            </button>
            <button
                type="button"
                class="rounded-sm bg-surface border border-danger/40 px-4 py-2 text-sm font-medium text-danger transition hover:bg-danger/10"
                @click="showReject = true"
            >
                Reject
            </button>
        </div>

        <div v-if="showReject || showRequestChanges" class="mt-4">
            <label class="block text-sm font-medium text-fg mb-1">
                Comment ({{ showRequestChanges ? 'what should change' : 'reason for rejection' }}) — required
            </label>
            <textarea v-model="comment" rows="3" class="w-full rounded-sm border border-border bg-surface px-3 py-2 text-sm text-fg mb-2 transition focus:outline-none focus:border-primary"></textarea>
            <button
                type="button"
                :disabled="!comment.trim()"
                class="rounded-sm bg-fg-strong px-4 py-2 text-sm font-medium text-bg transition hover:opacity-90 disabled:opacity-50"
                @click="confirmSecondaryAction"
            >
                Confirm {{ showRequestChanges ? 'request changes' : 'rejection' }}
            </button>
        </div>
    </div>
</template>

<script>
import DocumentViewer from './DocumentViewer.vue';
import ScoreDonutChart from './ScoreDonutChart.vue';

export default {
    name: 'GapAnalysisReviewPanel',

    components: { DocumentViewer, ScoreDonutChart },

    props: {
        runType: { type: String, default: 'requirement' },
        testabilityReviewMarkdown: { type: String, default: '' },
        codebaseKnowledgeBaseMarkdown: { type: String, default: '' },
        testPlanMarkdown: { type: String, default: '' },
        testCasesMarkdown: { type: String, default: '' },
        testabilityScore: { type: Number, default: null },
    },

    data() {
        return {
            showReject: false,
            showRequestChanges: false,
            comment: '',
        };
    },

    computed: {
        isCoverage() {
            return this.runType === 'coverage';
        },

        band() {
            if (this.testabilityScore === null) return null;
            if (this.testabilityScore >= 80) return 'Green';
            if (this.testabilityScore >= 60) return 'Yellow';
            return 'Red';
        },

        bandClasses() {
            return {
                Green: 'bg-success/10 text-success',
                Yellow: 'bg-warning/10 text-warning',
                Red: 'bg-danger/10 text-danger',
            }[this.band];
        },

        // Counts `## TC-<SOURCE>-<SEQ>` headings — the boschifai-test-patterns skill's own
        // per-test-case heading convention (confirmed against a real generated test_cases_*.md).
        // Requirement mode shows a test PLAN at this gate, not formatted test cases yet (those
        // are only generated after Gate 1 is approved), so this is naturally 0 there and the
        // badge stays hidden — it's really a coverage-mode-only count in practice. The API sends
        // null (not undefined) when there's no test-cases artifact yet, so the prop's '' default
        // never applies — without the `?? ''` a requirement run with a score threw here and Vue
        // dropped the whole panel, leaving Gate 1 blank.
        testCaseCount() {
            return ((this.testCasesMarkdown ?? '').match(/^##\s+TC-\S+/gm) || []).length;
        },
    },

    methods: {
        confirmSecondaryAction() {
            const decision = this.showRequestChanges ? 'changes_requested' : 'rejected';
            this.$emit(decision === 'rejected' ? 'reject' : 'request-changes', this.comment);
            this.showReject = false;
            this.showRequestChanges = false;
            this.comment = '';
        },
    },
};
</script>
