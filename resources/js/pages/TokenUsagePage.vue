<template>
    <div>
        <h1 class="text-xl font-bold text-fg-strong mb-1">Token Usage</h1>
        <p class="text-sm text-fg-muted mb-6">
            Every headless Claude invocation this calendar month, billed against
            <code class="rounded-sm bg-surface-alt px-1 py-0.5 text-xs">ANTHROPIC_API_KEY</code> — nothing else writes
            to this table, so this is the whole of that key's usage, not an estimate.
        </p>

        <p v-if="loading" class="text-fg-muted">Loading…</p>
        <p v-else-if="error" class="text-danger">{{ error }}</p>
        <template v-else-if="data">
            <div class="mb-6 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
                <div class="rounded-md border border-border bg-surface p-4">
                    <p class="text-xs font-semibold uppercase text-fg-muted">Total tokens</p>
                    <p class="text-2xl font-bold text-fg-strong">{{ formatted(summary.total_tokens) }}</p>
                </div>
                <div class="rounded-md border border-border bg-surface p-4">
                    <p class="text-xs font-semibold uppercase text-fg-muted">Cost</p>
                    <p class="text-2xl font-bold text-fg-strong">${{ summary.total_cost_usd.toFixed(2) }}</p>
                </div>
                <div class="rounded-md border border-border bg-surface p-4">
                    <p class="text-xs font-semibold uppercase text-fg-muted">Input</p>
                    <p class="text-2xl font-bold text-fg-strong">{{ formatted(summary.input_tokens) }}</p>
                </div>
                <div class="rounded-md border border-border bg-surface p-4">
                    <p class="text-xs font-semibold uppercase text-fg-muted">Output</p>
                    <p class="text-2xl font-bold text-fg-strong">{{ formatted(summary.output_tokens) }}</p>
                </div>
                <div class="rounded-md border border-border bg-surface p-4">
                    <p class="text-xs font-semibold uppercase text-fg-muted">Cache write</p>
                    <p class="text-2xl font-bold text-fg-strong">{{ formatted(summary.cache_creation_input_tokens) }}</p>
                </div>
                <div class="rounded-md border border-border bg-surface p-4">
                    <p class="text-xs font-semibold uppercase text-fg-muted">Cache read</p>
                    <p class="text-2xl font-bold text-fg-strong">{{ formatted(summary.cache_read_input_tokens) }}</p>
                </div>
            </div>

            <h2 class="text-sm font-semibold text-fg-strong mb-2">{{ invocations.length }} invocation(s) in {{ summary.month }}</h2>

            <p v-if="!invocations.length" class="text-fg-muted">No Claude invocations recorded yet this month.</p>
            <div v-else class="overflow-x-auto rounded-md border border-border">
                <table class="w-full min-w-max text-sm">
                    <thead class="bg-surface-alt text-left text-fg-muted">
                        <tr>
                            <th class="px-4 py-2 font-semibold">Requirement</th>
                            <th class="px-4 py-2 font-semibold">Repo</th>
                            <th class="px-4 py-2 font-semibold">Step</th>
                            <th class="px-4 py-2 font-semibold">Tokens</th>
                            <th class="px-4 py-2 font-semibold">Cost</th>
                            <th class="px-4 py-2 font-semibold">Turns</th>
                            <th class="px-4 py-2 font-semibold">Duration</th>
                            <th class="px-4 py-2 font-semibold">Result</th>
                            <th class="px-4 py-2 font-semibold">When</th>
                        </tr>
                    </thead>
                    <tbody class="bg-surface">
                        <tr
                            v-for="invocation in invocations"
                            :key="invocation.id"
                            class="border-t border-border transition hover:bg-surface-alt cursor-pointer"
                            @click="$router.push({ name: 'runs.show', params: { id: invocation.run_id } })"
                        >
                            <td class="px-4 py-2 max-w-xs truncate text-fg">{{ invocation.requirement_summary || '—' }}</td>
                            <td class="px-4 py-2 text-fg">{{ invocation.repo_name || '—' }}</td>
                            <td class="px-4 py-2 font-mono text-xs text-fg">{{ invocation.step_key }}</td>
                            <td class="px-4 py-2 text-fg">{{ formatted(invocation.total_tokens) }}</td>
                            <td class="px-4 py-2 text-fg">{{ invocation.total_cost_usd !== null ? `$${invocation.total_cost_usd.toFixed(4)}` : '—' }}</td>
                            <td class="px-4 py-2 text-fg-muted">{{ invocation.num_turns ?? '—' }}</td>
                            <td class="px-4 py-2 text-fg-muted">{{ invocation.duration_ms ? `${(invocation.duration_ms / 1000).toFixed(1)}s` : '—' }}</td>
                            <td class="px-4 py-2">
                                <span
                                    v-if="invocation.timed_out"
                                    class="rounded-full bg-danger/10 px-2 py-0.5 text-xs font-semibold text-danger"
                                >
                                    Timed out
                                </span>
                                <span v-else-if="invocation.stop_reason" class="text-fg-muted text-xs">{{ invocation.stop_reason }}</span>
                                <span v-else class="text-fg-muted">—</span>
                            </td>
                            <td class="px-4 py-2 text-fg-muted">{{ invocation.created_at }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </template>
    </div>
</template>

<script>
import { mapGetters, mapActions } from 'vuex';

export default {
    name: 'TokenUsagePage',

    data() {
        return { loading: true };
    },

    computed: {
        ...mapGetters('usage', ['details', 'error']),
        data() {
            return this.details;
        },
        summary() {
            return this.details?.summary;
        },
        invocations() {
            return this.details?.invocations || [];
        },
    },

    async created() {
        await this.fetchDetails();
        this.loading = false;
    },

    methods: {
        ...mapActions('usage', ['fetchDetails']),

        formatted(n) {
            return new Intl.NumberFormat('en-US').format(n);
        },
    },
};
</script>
