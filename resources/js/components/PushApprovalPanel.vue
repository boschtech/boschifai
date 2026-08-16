<template>
    <div>
        <!-- Approve/Reject work immediately without scrolling past the review content below,
             with Download/Re-run/Fix right alongside. Reject still opens its comment box at the
             bottom (a written reason needs real reading room). -->
        <div class="mb-6 flex flex-wrap items-center gap-3">
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
            <button
                type="button"
                class="rounded-sm border border-border bg-surface px-3 py-1.5 text-sm font-medium text-fg transition hover:bg-surface-alt"
                @click="downloadReport"
            >
                Download HTML report
            </button>
            <button
                type="button"
                class="rounded-sm border border-border bg-surface px-3 py-1.5 text-sm font-medium text-fg transition hover:bg-surface-alt"
                @click="$emit('rerun-tests')"
            >
                Re-run tests
            </button>
            <button
                v-if="hasFailingTests"
                type="button"
                class="rounded-sm border border-primary/40 bg-primary/10 px-3 py-1.5 text-sm font-medium text-primary-dark transition hover:bg-primary/20"
                @click="$emit('fix-failing-tests')"
            >
                Fix failing tests
            </button>
        </div>

        <pre-push-confidence-panel v-if="run.pre_push_confidence" :breakdown="run.pre_push_confidence" />

        <!-- Same box style/size as GapAnalysisReviewPanel's Gate 1 score card, now merged with
             the per-test list below it (previously its own separate box) into one expandable
             card — same collapsed-by-default pattern as GeneratedCodeViewer/TestCaseReviewPanel.
             "View coverage report" stays outside the toggle button (a link can't nest inside a
             button element), so it's a normal independent click target. -->
        <div v-if="testabilityScore !== null" class="mb-6 rounded-md border border-border bg-surface p-4">
            <div class="flex items-center gap-4">
                <button
                    type="button"
                    class="flex flex-1 items-center gap-4 text-left"
                    @click="testsExpanded = !testsExpanded"
                >
                    <score-donut-chart :score="testabilityScore" :size="96" :stroke-width="12" />
                    <div>
                        <h2 class="text-sm font-semibold text-fg">Testability Score</h2>
                        <div class="mt-1 flex flex-wrap items-center gap-2">
                            <span class="rounded-full px-2 py-0.5 text-xs font-semibold" :class="bandClasses">{{ band }}</span>
                            <span class="rounded-full px-2 py-0.5 text-xs font-semibold" :class="hasFailingTests ? 'bg-danger/10 text-danger' : 'bg-success/10 text-success'">
                                {{ executionResult.passed }}/{{ executionResult.total }} passed
                            </span>
                            <span class="text-xs text-fg-muted">{{ executionResult.duration_ms }}ms</span>
                        </div>
                    </div>
                </button>

                <div class="flex shrink-0 flex-col items-end gap-2">
                    <a
                        v-if="executionResult.coverage_report_url"
                        :href="executionResult.coverage_report_url"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="text-xs text-primary underline"
                    >
                        View coverage report →
                    </a>
                    <button
                        type="button"
                        class="flex items-center gap-1 text-xs font-medium text-fg-muted transition hover:text-primary"
                        @click="testsExpanded = !testsExpanded"
                    >
                        {{ testsExpanded ? 'Hide tests' : 'Show tests' }}
                        <svg
                            xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4 transition-transform"
                            :class="testsExpanded ? 'rotate-180' : ''"
                        >
                            <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.168l3.71-3.938a.75.75 0 1 1 1.08 1.04l-4.25 4.5a.75.75 0 0 1-1.08 0l-4.25-4.5a.75.75 0 0 1 .02-1.06Z" clip-rule="evenodd" />
                        </svg>
                    </button>
                </div>
            </div>

            <div v-if="testsExpanded" class="mt-4">
                <local-execution-results-panel :result="executionResult" />
            </div>
        </div>

        <div class="mb-6">
            <test-case-review-panel :test-cases-markdown="testCasesMarkdown" :verdict="testCaseVerdict" />
        </div>

        <div class="mb-6">
            <generated-code-viewer :file-path="generatedFilePath" :code="generatedCode" />
        </div>

        <div v-if="hasMultiTenantTest === false" class="mb-6 rounded-md border border-warning/30 bg-warning/10 p-4 text-sm text-warning">
            <strong>No cross-team access test detected.</strong> RAMS's own conventions require a
            multi-tenant boundary test for any team-scoped resource — this is the highest-consequence
            gap category on this codebase. Review the generated file carefully before approving.
        </div>

        <button
            type="button"
            class="mb-4 flex items-center gap-1 text-sm font-medium text-primary hover:underline"
            @click="scrollToTop"
        >
            &uarr; Back to top
        </button>

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
import ScoreDonutChart from './ScoreDonutChart.vue';
import PrePushConfidencePanel from './PrePushConfidencePanel.vue';
import { downloadPrePushReport } from '../lib/prePushReport';

export default {
    name: 'PushApprovalPanel',

    components: { TestCaseReviewPanel, GeneratedCodeViewer, LocalExecutionResultsPanel, ScoreDonutChart, PrePushConfidencePanel },

    props: {
        // The full run resource, in addition to the granular props below: the downloadable
        // report needs several fields (repo name, run type, codebase/testability markdown,
        // pre-push confidence) that this panel doesn't otherwise render inline, and threading
        // them all through as individual props would just be duplication of what's already on
        // the object RunShowPage fetched.
        run: { type: Object, required: true },
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
            testsExpanded: false,
        };
    },

    computed: {
        hasFailingTests() {
            return (this.executionResult.failed ?? 0) > 0;
        },

        // Same thresholds/labels as GapAnalysisReviewPanel's own band computed — this score is
        // literally the same testability score carried forward from Gate 1, just re-displayed
        // here as a reminder before the push decision.
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
        confirmReject() {
            this.$emit('reject', this.comment);
            this.showReject = false;
            this.comment = '';
        },

        downloadReport() {
            downloadPrePushReport(this.run);
        },

        // Scrolls the whole browser window, not just this panel — this component renders
        // partway down RunShowPage (below RunTimeline/LiveActivityPanel), so an anchor-id jump
        // scoped to this component alone wouldn't actually reach the page's real top.
        scrollToTop() {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        },
    },
};
</script>
