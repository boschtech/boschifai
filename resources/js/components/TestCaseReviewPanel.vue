<template>
    <section class="rounded-lg border border-border bg-surface p-4">
        <button
            type="button"
            class="flex w-full items-center justify-between gap-3 text-left"
            @click="expanded = !expanded"
        >
            <div class="flex items-center gap-3">
                <h2 class="text-sm font-semibold text-fg">
                    Generated test cases
                    <span v-if="testCaseCount" class="font-normal text-fg-muted">— {{ testCaseCount }} test case{{ testCaseCount === 1 ? '' : 's' }}</span>
                </h2>
                <span
                    v-if="verdict"
                    class="rounded-full px-2 py-0.5 text-xs font-medium"
                    :class="verdict === 'APPROVED' ? 'bg-success/10 text-success' : 'bg-warning/10 text-warning'"
                >
                    Validator: {{ verdict }}
                </span>
            </div>
            <span class="flex shrink-0 items-center gap-1 text-xs font-medium text-fg-muted transition hover:text-primary">
                {{ expanded ? 'Hide test cases' : 'Show test cases' }}
                <svg
                    xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4 transition-transform"
                    :class="expanded ? 'rotate-180' : ''"
                >
                    <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.168l3.71-3.938a.75.75 0 1 1 1.08 1.04l-4.25 4.5a.75.75 0 0 1-1.08 0l-4.25-4.5a.75.75 0 0 1 .02-1.06Z" clip-rule="evenodd" />
                </svg>
            </span>
        </button>

        <!-- Collapsed by default — same rationale as GeneratedCodeViewer: the reviewer's
             attention belongs on the local execution result and confidence score above, with the
             full test-case detail one click away rather than dominating the page by default. -->
        <div v-if="expanded" class="mt-3">
            <document-viewer :content="testCasesMarkdown">
                <template #empty>No test cases yet.</template>
            </document-viewer>
        </div>
    </section>
</template>

<script>
import DocumentViewer from './DocumentViewer.vue';

export default {
    name: 'TestCaseReviewPanel',

    components: { DocumentViewer },

    props: {
        testCasesMarkdown: { type: String, default: '' },
        verdict: { type: String, default: null },
    },

    data() {
        return {
            expanded: false,
        };
    },

    computed: {
        // Same `## TC-<SOURCE>-<SEQ>` heading convention counted in GapAnalysisReviewPanel's own
        // testCaseCount — kept in sync there rather than shared, since these two components have
        // no existing common base to hang it off without a bigger refactor.
        testCaseCount() {
            return (this.testCasesMarkdown.match(/^##\s+TC-\S+/gm) || []).length;
        },
    },
};
</script>
