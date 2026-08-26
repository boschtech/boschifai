<template>
    <router-link
        v-if="usage"
        :to="{ name: 'reporting.token-usage' }"
        class="inline-flex items-center gap-1.5 rounded-md border border-border bg-surface px-3 py-1.5 text-xs font-medium text-fg-muted transition hover:border-primary hover:text-primary-dark"
        :title="`ANTHROPIC_API_KEY usage across every Claude-driven pipeline step this month (${usage.month}) — ${formatted(usage.input_tokens)} input, ${formatted(usage.output_tokens)} output, ${formatted(usage.cache_creation_input_tokens)} cache write, ${formatted(usage.cache_read_input_tokens)} cache read. Click for the full breakdown.`"
    >
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4 text-primary">
            <path fill-rule="evenodd" d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16Zm.75-11.25a.75.75 0 0 0-1.5 0v3.69l-1.72 1.72a.75.75 0 1 0 1.06 1.06l2.03-2.03a.75.75 0 0 0 .13-.44V6.75Z" clip-rule="evenodd" />
        </svg>
        <span>{{ formatted(usage.total_tokens) }} tokens · ${{ usage.total_cost_usd.toFixed(2) }} this month</span>
    </router-link>
</template>

<script>
import { mapGetters, mapActions } from 'vuex';

export default {
    name: 'TokenUsageBadge',

    computed: {
        ...mapGetters('usage', ['tokensThisMonth']),
        usage() {
            return this.tokensThisMonth;
        },
    },

    created() {
        this.fetchTokensThisMonth();
    },

    methods: {
        ...mapActions('usage', ['fetchTokensThisMonth']),

        // 1,234,567 rather than a raw digit dump — cache-heavy multi-turn steps routinely run
        // into the millions of tokens (prompt-cache reads count toward the total), so a plain
        // number would be unreadable at a glance.
        formatted(n) {
            return new Intl.NumberFormat('en-US').format(n);
        },
    },
};
</script>
