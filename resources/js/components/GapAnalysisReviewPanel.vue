<template>
    <div>
        <div v-if="testabilityScore !== null" class="mb-6 flex items-center gap-3">
            <span
                class="rounded-full px-3 py-1 text-sm font-semibold"
                :class="bandClasses"
            >
                Testability: {{ testabilityScore }}% ({{ band }})
            </span>
        </div>

        <div v-if="band === 'Red'" class="mb-6 rounded-md border border-danger/30 bg-danger/10 p-4 text-sm text-danger">
            <strong>Red — this requirement is likely to produce ambiguous or low-value test cases.</strong>
            Per BOSCHIFAI's own review standards, requirements scoring below 60% should typically be refined
            before generating tests. Consider rejecting or requesting changes below.
        </div>

        <section class="mb-6">
            <h2 class="text-sm font-semibold text-fg mb-2">Testability review</h2>
            <document-viewer :content="testabilityReviewMarkdown">
                <template #empty>No testability review yet.</template>
            </document-viewer>
        </section>

        <section class="mb-6">
            <h2 class="text-sm font-semibold text-fg mb-2">Test plan</h2>
            <document-viewer :content="testPlanMarkdown">
                <template #empty>No test plan yet.</template>
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

export default {
    name: 'GapAnalysisReviewPanel',

    components: { DocumentViewer },

    props: {
        testabilityReviewMarkdown: { type: String, default: '' },
        testPlanMarkdown: { type: String, default: '' },
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
