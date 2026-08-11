<template>
    <div v-if="run">
        <run-timeline :state="run.state" />

        <p v-if="run.previous_run_id" class="mb-6 text-sm text-fg-muted">
            Edited from a previous run —
            <router-link :to="{ name: 'runs.show', params: { id: run.previous_run_id } }" class="text-primary underline">
                view it here
            </router-link>
        </p>

        <live-activity-panel
            v-if="isRunningState"
            :run-id="id"
            :cancel-requested="!!run.cancel_requested_at"
            class="mb-6"
            @stop="stop"
        />

        <gap-analysis-review-panel
            v-if="run.state === 'gap_analysis_ready'"
            :testability-review-markdown="run.testability_review_markdown"
            :test-plan-markdown="run.test_plan_markdown"
            :testability-score="run.testability_score"
            @approve="decide('gap-analysis', 'approved')"
            @reject="(comment) => decide('gap-analysis', 'rejected', comment)"
            @request-changes="(comment) => decide('gap-analysis', 'changes_requested', comment)"
        />

        <push-approval-panel
            v-else-if="run.state === 'local_execution_complete'"
            :testability-score="run.testability_score"
            :test-cases-markdown="run.test_cases_markdown"
            :test-case-verdict="run.test_case_verdict"
            :generated-file-path="run.generated_file_path"
            :generated-code="run.generated_code"
            :execution-result="run.execution_result"
            :has-multi-tenant-test="run.has_multi_tenant_test"
            @approve="decide('push', 'approved')"
            @reject="(comment) => decide('push', 'rejected', comment)"
        />

        <ci-status-panel
            v-else-if="['pushing', 'ci_pending'].includes(run.state)"
            :pr-url="run.pr_url"
            :conclusion="run.ci_conclusion"
        />

        <confidence-report-card
            v-else-if="run.state === 'report_ready'"
            :breakdown="run.confidence_breakdown"
        />

        <div v-else-if="run.state === 'generation_blocked'" class="rounded-md border border-danger/30 bg-danger/10 p-4 text-sm text-danger">
            Test-case generation could not pass its own validator after two attempts. Needs manual
            intervention — review <code>{{ run.blocked_reason }}</code> and either edit the generated
            file directly in the worktree or reject this run and resubmit.
        </div>

        <div v-else-if="run.state === 'failed'" class="rounded-md border border-danger/30 bg-danger/10 p-4 text-sm text-danger">
            <p class="mb-3">Run failed at step <strong>{{ run.failed_step }}</strong>: {{ run.error_message }}</p>
            <button
                type="button"
                class="rounded-sm bg-fg-strong px-4 py-2 text-sm font-medium text-bg transition hover:opacity-90"
                @click="retry"
            >
                Retry step
            </button>
        </div>

        <div v-else-if="['gap_analysis_rejected', 'push_rejected'].includes(run.state)" class="rounded-md border border-border bg-surface-alt p-4 text-sm text-fg">
            This run was rejected. Nothing was pushed. See comment: {{ run.rejection_comment }}
        </div>

        <div v-else-if="run.state === 'cancelled'" class="rounded-md border border-border bg-surface-alt p-4 text-sm text-fg">
            This run was stopped before it finished. Nothing further was pushed. Use
            <router-link :to="{ name: 'runs.edit', params: { id: id } }" class="text-primary underline">Edit</router-link>
            to try again with the same (or adjusted) requirement.
        </div>
    </div>
</template>

<script>
import { mapGetters, mapActions } from 'vuex';
import RunTimeline from '../components/RunTimeline.vue';
import GapAnalysisReviewPanel from '../components/GapAnalysisReviewPanel.vue';
import PushApprovalPanel from '../components/PushApprovalPanel.vue';
import CiStatusPanel from '../components/CiStatusPanel.vue';
import ConfidenceReportCard from '../components/ConfidenceReportCard.vue';
import LiveActivityPanel from '../components/LiveActivityPanel.vue';

// 'draft' included: confirmed as a real gap by a live user report — a freshly-submitted run
// sits in 'draft' until a queue worker picks up RunGapAnalysisJob, and without polling here,
// a run stuck in the queue (e.g. no worker running at all) rendered as a static page with zero
// feedback, indistinguishable from "the page is broken."
const POLLING_STATES = [
    'draft',
    'gap_analysis_running',
    'generation_running',
    'local_execution_running',
    'pushing',
    'ci_pending',
];

export default {
    name: 'RunShowPage',

    components: { RunTimeline, GapAnalysisReviewPanel, PushApprovalPanel, CiStatusPanel, ConfidenceReportCard, LiveActivityPanel },

    props: {
        id: { type: [String, Number], required: true },
    },

    data() {
        return {
            pollTimer: null,
        };
    },

    computed: {
        ...mapGetters('runs', { run: 'current' }),

        isRunningState() {
            return POLLING_STATES.includes(this.run?.state);
        },
    },

    created() {
        this.load();
    },

    beforeDestroy() {
        clearTimeout(this.pollTimer);
    },

    methods: {
        ...mapActions('runs', ['fetchRun', 'decideGapAnalysis', 'decidePush', 'retryStep', 'cancelRun']),

        async load() {
            await this.fetchRun(this.id);
            clearTimeout(this.pollTimer);
            if (this.isRunningState) {
                this.pollTimer = setTimeout(() => this.load(), 5000);
            }
        },

        async decide(gate, decision, comment) {
            if (gate === 'gap-analysis') {
                await this.decideGapAnalysis({ id: this.id, decision, comment });
            } else {
                await this.decidePush({ id: this.id, decision, comment });
            }
            this.load();
        },

        async retry() {
            await this.retryStep({ id: this.id, stepId: this.run.failed_step_id });
            this.load();
        },

        async stop() {
            await this.cancelRun(this.id);
            this.load();
        },
    },
};
</script>
