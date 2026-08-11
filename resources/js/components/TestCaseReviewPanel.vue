<template>
    <section>
        <div class="flex items-center gap-3 mb-2">
            <h2 class="text-sm font-semibold text-fg">Generated test cases</h2>
            <span
                v-if="verdict"
                class="rounded-full px-2 py-0.5 text-xs font-medium"
                :class="verdict === 'APPROVED' ? 'bg-success/10 text-success' : 'bg-warning/10 text-warning'"
            >
                Validator: {{ verdict }}
            </span>
        </div>

        <document-viewer :content="testCasesMarkdown">
            <template #empty>No test cases yet.</template>
        </document-viewer>
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
};
</script>
