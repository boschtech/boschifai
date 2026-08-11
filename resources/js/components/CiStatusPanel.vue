<template>
    <section class="rounded-lg border border-border bg-surface p-4">
        <h2 class="text-sm font-semibold text-fg mb-2">CI status</h2>
        <p class="text-sm text-fg mb-1">
            PR: <a :href="prUrl" target="_blank" rel="noopener" class="text-primary underline">{{ prUrl }}</a>
        </p>
        <p class="text-sm text-fg">
            {{ checkName }}:
            <span class="font-medium" :class="statusClasses">{{ conclusion || 'pending' }}</span>
        </p>
    </section>
</template>

<script>
export default {
    name: 'CiStatusPanel',

    props: {
        prUrl: { type: String, required: true },
        checkName: { type: String, default: 'Sonar Scan' },
        conclusion: { type: String, default: null },
    },

    computed: {
        statusClasses() {
            if (this.conclusion === 'success') return 'text-success';
            if (this.conclusion === 'failure') return 'text-danger';
            return 'text-warning';
        },
    },
};
</script>
