<template>
    <section>
        <!-- Pass/fail count, duration, and the coverage-report link moved into the Testability
             Score box at the top of PushApprovalPanel — this panel is just the per-test detail
             list now. -->
        <ul v-if="tests.length" class="max-h-[32rem] divide-y divide-border overflow-y-auto rounded-lg border border-border bg-surface">
            <li v-for="test in tests" :key="test.name" class="flex items-start gap-2 p-3">
                <svg
                    v-if="test.status === 'passed'"
                    xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"
                    class="mt-0.5 h-4 w-4 shrink-0 text-success"
                >
                    <path fill-rule="evenodd" d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16Zm3.857-9.809a.75.75 0 0 0-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 1 0-1.06 1.061l2.5 2.5a.75.75 0 0 0 1.137-.089l4-5.5Z" clip-rule="evenodd" />
                </svg>
                <svg
                    v-else-if="test.status === 'failed'"
                    xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"
                    class="mt-0.5 h-4 w-4 shrink-0 text-danger"
                >
                    <path fill-rule="evenodd" d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16ZM8.28 7.22a.75.75 0 0 0-1.06 1.06L8.94 10l-1.72 1.72a.75.75 0 1 0 1.06 1.06L10 11.06l1.72 1.72a.75.75 0 1 0 1.06-1.06L11.06 10l1.72-1.72a.75.75 0 0 0-1.06-1.06L10 8.94 8.28 7.22Z" clip-rule="evenodd" />
                </svg>
                <svg
                    v-else
                    xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"
                    class="mt-0.5 h-4 w-4 shrink-0 text-fg-muted"
                >
                    <path fill-rule="evenodd" d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16ZM7 9.25a.75.75 0 0 0 0 1.5h6a.75.75 0 0 0 0-1.5H7Z" clip-rule="evenodd" />
                </svg>

                <div class="min-w-0 flex-1">
                    <p class="truncate font-mono text-sm" :class="test.status === 'failed' ? 'font-medium text-danger' : 'text-fg'">
                        {{ test.name }}
                    </p>
                    <pre v-if="test.message" class="mt-1 whitespace-pre-wrap text-xs text-danger/90">{{ test.message }}</pre>
                </div>
            </li>
        </ul>
    </section>
</template>

<script>
export default {
    name: 'LocalExecutionResultsPanel',

    props: {
        result: { type: Object, required: true },
    },

    computed: {
        tests() {
            return this.result.tests || [];
        },
    },
};
</script>
