<template>
    <ol class="flex flex-wrap items-center gap-2 mb-8">
        <li v-for="(step, idx) in steps" :key="step.key" class="flex items-center gap-2">
            <div
                class="flex items-center gap-1.5 rounded-full px-3.5 py-1.5 text-sm font-medium"
                :class="[badgeClasses(step), { 'stage-glow': step.status === 'running' }]"
            >
                <span v-if="step.isHumanGate">&#128100;</span>
                <span>{{ step.label }}</span>
                <span v-if="step.status === 'running'">…</span>
            </div>
            <span v-if="idx < steps.length - 1" class="text-fg-muted/50">&rarr;</span>
        </li>
    </ol>
</template>

<script>
const STEP_DEFS = [
    { key: 'gap_analysis', label: 'Gap analysis', isHumanGate: false },
    { key: 'gate_1', label: 'Gate 1: Review & Plan', isHumanGate: true },
    { key: 'generation', label: 'Generation', isHumanGate: false },
    { key: 'local_execution', label: 'Local execution', isHumanGate: false },
    { key: 'gate_2', label: 'Gate 2: Push', isHumanGate: true },
    { key: 'ci', label: 'CI', isHumanGate: false },
    { key: 'report', label: 'Report', isHumanGate: false },
];

// Maps a Run.state (see RunState enum) to the index of the step it represents being "at".
const STATE_TO_STEP_INDEX = {
    draft: 0,
    gap_analysis_running: 0,
    gap_analysis_ready: 1,
    gap_analysis_rejected: 1,
    generation_running: 2,
    generation_blocked: 2,
    local_execution_running: 3,
    local_execution_complete: 4,
    push_rejected: 4,
    pushing: 5,
    ci_pending: 5,
    ci_complete: 6,
    report_ready: 6,
    failed: -1,
    cancelled: -1,
};

const BAD_TERMINAL_STATES = ['gap_analysis_rejected', 'push_rejected', 'generation_blocked', 'failed'];

export default {
    name: 'RunTimeline',

    props: {
        state: { type: String, required: true },
    },

    computed: {
        currentIndex() {
            return STATE_TO_STEP_INDEX[this.state] ?? 0;
        },

        steps() {
            return STEP_DEFS.map((def, idx) => {
                let status = 'pending';
                if (idx < this.currentIndex) status = 'done';
                if (idx === this.currentIndex) {
                    status = this.state.endsWith('_running') || this.state === 'pushing' || this.state === 'ci_pending'
                        ? 'running'
                        : BAD_TERMINAL_STATES.includes(this.state)
                            ? 'blocked'
                            : 'current';
                }
                return { ...def, status };
            });
        },
    },

    methods: {
        badgeClasses(step) {
            if (step.status === 'blocked') return 'bg-danger/10 text-danger';
            if (step.status === 'done') return 'bg-success/10 text-success';
            if (step.status === 'running') return 'bg-primary/10 text-primary-dark';
            if (step.status === 'current') return 'bg-warning/10 text-warning';
            return 'bg-surface-alt text-fg-muted';
        },
    },
};
</script>

<style scoped>
/* Breathing gold glow for the step actively being worked on — same gold family as the app's
   other glow/hover-shadow accents (tailwind.config.js's card-hover token), just animated so the
   in-progress stage reads as "alive" at a glance rather than only distinguishable by color. */
@keyframes stage-pulse {
    0%,
    100% {
        box-shadow: 0 0 0 0 rgb(184 150 28 / 0.35), 0 0 8px 0 rgb(184 150 28 / 0.25);
    }
    50% {
        box-shadow: 0 0 0 4px rgb(184 150 28 / 0.12), 0 0 22px 4px rgb(184 150 28 / 0.5);
    }
}

.stage-glow {
    animation: stage-pulse 1.8s ease-in-out infinite;
}
</style>
