<template>
    <section class="rounded-lg border border-border bg-surface p-4">
        <button
            type="button"
            class="flex w-full items-center justify-between gap-3 text-left"
            @click="expanded = !expanded"
        >
            <h2 class="text-sm font-semibold text-fg">
                Generated test file
                <span class="font-mono font-normal text-fg-muted">{{ filePath }}</span>
                <span v-if="lineCount" class="font-normal text-fg-muted">— {{ lineCount }} lines</span>
            </h2>
            <span class="flex shrink-0 items-center gap-1 text-xs font-medium text-fg-muted transition hover:text-primary">
                {{ expanded ? 'Hide code' : 'Show code' }}
                <svg
                    xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4 transition-transform"
                    :class="expanded ? 'rotate-180' : ''"
                >
                    <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.168l3.71-3.938a.75.75 0 1 1 1.08 1.04l-4.25 4.5a.75.75 0 0 1-1.08 0l-4.25-4.5a.75.75 0 0 1 .02-1.06Z" clip-rule="evenodd" />
                </svg>
            </span>
        </button>

        <!-- Collapsed by default: the reviewer's attention belongs on the test cases and local
             execution result above — the raw generated source is available on demand, one
             click away, rather than dominating the page by default. -->
        <div v-if="expanded" class="terminal-glow mt-3 overflow-hidden rounded-lg border border-border">
            <pre class="overflow-x-auto bg-gray-900 p-4 text-xs text-gray-100 font-mono">{{ code }}</pre>
        </div>
    </section>
</template>

<script>
export default {
    name: 'GeneratedCodeViewer',

    props: {
        filePath: { type: String, required: true },
        code: { type: String, default: '' },
    },

    data() {
        return {
            expanded: false,
        };
    },

    computed: {
        lineCount() {
            return this.code ? this.code.split('\n').length : 0;
        },
    },
};
</script>
