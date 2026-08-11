<template>
    <div>
        <div class="mb-6 flex flex-wrap gap-3">
            <span class="rounded-full px-3 py-1 text-sm font-semibold bg-surface-alt text-fg">
                Testability carried forward: {{ testabilityScore }}%
            </span>
        </div>

        <div class="mb-6">
            <test-case-review-panel :test-cases-markdown="testCasesMarkdown" :verdict="testCaseVerdict" />
        </div>

        <div class="mb-6">
            <generated-code-viewer :file-path="generatedFilePath" :code="generatedCode" />
        </div>

        <div class="mb-6">
            <local-execution-results-panel :result="executionResult" />
        </div>

        <div v-if="hasMultiTenantTest === false" class="mb-6 rounded-md border border-warning/30 bg-warning/10 p-4 text-sm text-warning">
            <strong>No cross-team access test detected.</strong> RAMS's own conventions require a
            multi-tenant boundary test for any team-scoped resource — this is the highest-consequence
            gap category on this codebase. Review the generated file carefully before approving.
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <button
                type="button"
                class="rounded-sm bg-success px-4 py-2 text-sm font-semibold text-white transition hover:opacity-90"
                @click="$emit('approve')"
            >
                Approve &amp; Push to GitHub
            </button>
            <button
                type="button"
                class="rounded-sm bg-surface border border-danger/40 px-4 py-2 text-sm font-medium text-danger transition hover:bg-danger/10"
                @click="showReject = true"
            >
                Reject
            </button>
        </div>

        <div v-if="showReject" class="mt-4">
            <label class="block text-sm font-medium text-fg mb-1">Reason for rejection — required</label>
            <textarea v-model="comment" rows="3" class="w-full rounded-sm border border-border bg-surface px-3 py-2 text-sm text-fg mb-2 transition focus:outline-none focus:border-primary"></textarea>
            <button
                type="button"
                :disabled="!comment.trim()"
                class="rounded-sm bg-fg-strong px-4 py-2 text-sm font-medium text-bg transition hover:opacity-90 disabled:opacity-50"
                @click="confirmReject"
            >
                Confirm rejection
            </button>
        </div>
    </div>
</template>

<script>
import TestCaseReviewPanel from './TestCaseReviewPanel.vue';
import GeneratedCodeViewer from './GeneratedCodeViewer.vue';
import LocalExecutionResultsPanel from './LocalExecutionResultsPanel.vue';

export default {
    name: 'PushApprovalPanel',

    components: { TestCaseReviewPanel, GeneratedCodeViewer, LocalExecutionResultsPanel },

    props: {
        testabilityScore: { type: Number, default: null },
        testCasesMarkdown: { type: String, default: '' },
        testCaseVerdict: { type: String, default: null },
        generatedFilePath: { type: String, required: true },
        generatedCode: { type: String, default: '' },
        executionResult: { type: Object, required: true },
        hasMultiTenantTest: { type: Boolean, default: null },
    },

    data() {
        return {
            showReject: false,
            comment: '',
        };
    },

    methods: {
        confirmReject() {
            this.$emit('reject', this.comment);
            this.showReject = false;
            this.comment = '';
        },
    },
};
</script>
