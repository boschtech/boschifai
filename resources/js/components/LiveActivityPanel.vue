<template>
    <section class="terminal-glow rounded-lg border border-border bg-surface p-4">
        <div class="flex items-center justify-between mb-2">
            <h2 class="text-sm font-semibold text-fg">{{ stepLabel }}</h2>
            <div class="flex items-center gap-3">
                <span v-if="elapsedLabel" class="text-xs text-fg-muted">{{ elapsedLabel }}</span>
                <button
                    type="button"
                    :disabled="cancelRequested"
                    class="rounded-sm border border-danger/40 px-3 py-1 text-xs font-medium text-danger transition hover:bg-danger/10 disabled:opacity-50"
                    @click="confirmStop"
                >
                    {{ cancelRequested ? 'Stopping…' : 'Stop' }}
                </button>
            </div>
        </div>

        <div v-if="cancelRequested" class="mb-2 rounded-md border border-danger/30 bg-danger/10 p-3 text-sm text-danger">
            Stopping — the current step will end shortly once it notices (usually within a
            second or two).
        </div>

        <div v-if="activity && activity.phase === 'queued'" class="rounded-md border border-warning/30 bg-warning/10 p-3 text-sm text-warning">
            {{ activity.message }}
        </div>

        <div v-else-if="activity && activity.message" class="text-sm text-fg">
            {{ activity.message }}
        </div>

        <pre
            v-if="activity && activity.log_lines && activity.log_lines.length"
            ref="logBox"
            class="mt-2 max-h-[70vh] overflow-y-auto whitespace-pre-wrap rounded-lg bg-gray-900 p-3 text-xs text-gray-100 font-mono"
        >{{ activity.log_lines.join('\n\n') }}</pre>

        <p v-if="activity && activity.cost_so_far_usd" class="mt-2 text-xs text-fg-muted">
            Cost so far: ${{ activity.cost_so_far_usd }}
        </p>

        <confirm-dialog
            :open="showStopConfirm"
            title="Stop this run?"
            message="The current step will be interrupted and the run cannot be resumed — you would need to resubmit (or use Edit) to try again."
            confirm-label="Stop run"
            @confirm="stop"
            @cancel="showStopConfirm = false"
        />
    </section>
</template>

<script>
import ConfirmDialog from './ConfirmDialog.vue';

export default {
    name: 'LiveActivityPanel',

    components: { ConfirmDialog },

    props: {
        runId: { type: [String, Number], required: true },
        cancelRequested: { type: Boolean, default: false },
    },

    data() {
        return {
            activity: null,
            pollTimer: null,
            showStopConfirm: false,
        };
    },

    computed: {
        stepLabel() {
            const labels = {
                gap_analysis: 'Gap analysis',
                test_plan: 'Test plan',
                test_case_generation: 'Generating test cases',
                code_generation: 'Generating code',
                local_execution: 'Local execution',
                push: 'Pushing to GitHub',
                ci_poll: 'Waiting on CI',
            };
            return labels[this.activity?.step_key] || 'Working';
        },

        elapsedLabel() {
            const seconds = this.activity?.elapsed_seconds;
            if (seconds === null || seconds === undefined) return null;
            if (seconds < 60) return `${seconds}s`;
            return `${Math.floor(seconds / 60)}m ${seconds % 60}s`;
        },
    },

    created() {
        this.poll();
    },

    beforeDestroy() {
        clearTimeout(this.pollTimer);
    },

    methods: {
        confirmStop() {
            this.showStopConfirm = true;
        },

        stop() {
            this.showStopConfirm = false;
            this.$emit('stop');
        },

        async poll() {
            try {
                const { data } = await window.axios.get(`/api/runs/${this.runId}/activity`);
                this.activity = data;
                this.$nextTick(() => {
                    const box = this.$refs.logBox;
                    if (box) box.scrollTop = box.scrollHeight;
                });
            } catch (e) {
                // A transient poll failure shouldn't stop the loop — the main run poll (see
                // RunShowPage) is the source of truth for whether the run itself has moved on.
            }
            this.pollTimer = setTimeout(() => this.poll(), 3000);
        },
    },
};
</script>
