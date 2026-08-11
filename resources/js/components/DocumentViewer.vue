<template>
    <p v-if="!content" class="rounded-lg border border-border bg-surface p-6 text-sm text-fg-muted">
        <slot name="empty">Nothing here yet.</slot>
    </p>

    <div v-else class="flex overflow-hidden rounded-lg border border-border bg-surface">
        <!-- Top-level sections only — a flat sidebar listing every sub-heading in a long
             generated document would be noise, not navigation. Skipped entirely for short
             documents (nothing to navigate between). -->
        <nav
            v-if="sections.length > 1"
            class="hidden w-64 shrink-0 overflow-y-auto border-r border-border bg-surface-alt/60 p-3 md:block"
            :style="{ maxHeight }"
        >
            <p class="px-2 pb-2 text-xs font-semibold uppercase tracking-wide text-fg-muted">Contents</p>
            <ul>
                <li v-for="section in sections" :key="section.id">
                    <button
                        type="button"
                        :title="section.text"
                        class="block w-full truncate rounded-sm px-2 py-1 text-left text-xs transition hover:bg-surface hover:text-primary"
                        :class="activeId === section.id ? 'bg-primary/10 font-medium text-primary-dark' : 'text-fg'"
                        @click="scrollToId(section.id)"
                    >
                        {{ section.text }}
                    </button>
                </li>
            </ul>
        </nav>

        <div ref="content" class="min-w-0 flex-1 overflow-y-auto p-6" :style="{ maxHeight }" @click="onContentClick">
            <markdown-view ref="markdown" :content="content" />
        </div>
    </div>
</template>

<script>
import MarkdownView from './MarkdownView.vue';

/**
 * Shared "long AI-generated document" viewer — sidebar index of top-level sections next to a
 * scrollable content pane, replacing the earlier pattern of dumping raw-height markdown (or a
 * cramped fixed-height scroll box) with no way to jump around. Used for testability reviews,
 * test plans, and generated test cases — all three are the same shape of problem (a long,
 * heading-structured markdown doc with its own internal Table of Contents links that need a
 * real anchor to jump to, see MarkdownView's heading-id slugger).
 */
export default {
    name: 'DocumentViewer',

    components: { MarkdownView },

    props: {
        content: { type: String, default: '' },
        headingSelector: { type: String, default: 'h2[id]' },
        maxHeight: { type: String, default: '75vh' },
    },

    data() {
        return {
            sections: [],
            activeId: null,
        };
    },

    watch: {
        content: {
            immediate: true,
            handler() {
                this.$nextTick(this.buildSections);
            },
        },
    },

    methods: {
        // Built from the rendered DOM (not re-parsed from the raw markdown) so the sidebar can
        // never drift out of sync with whatever heading ids MarkdownView's slugger produced.
        buildSections() {
            const root = this.$refs.markdown?.$el;
            if (!root) {
                this.sections = [];
                return;
            }

            this.sections = Array.from(root.querySelectorAll(this.headingSelector)).map((el) => ({
                id: el.id,
                text: el.textContent,
            }));
        },

        scrollToId(id) {
            const target = this.$refs.content?.querySelector(`#${CSS.escape(id)}`);
            if (!target) return;

            target.scrollIntoView({ behavior: 'smooth', block: 'start' });
            this.activeId = id;
        },

        // The document's own internal links (its Table of Contents, every section's "back to
        // contents" footer) are plain <a href="#..."> tags inside v-html — a native click would
        // jump the whole page's scroll position looking for the id, not this inner scroll pane.
        // Intercepting here keeps that navigation contained to the pane, consistent with the
        // sidebar's own scrollToId behavior above.
        onContentClick(event) {
            const link = event.target.closest('a[href^="#"]');
            if (!link) return;

            event.preventDefault();
            this.scrollToId(decodeURIComponent(link.getAttribute('href').slice(1)));
        },
    },
};
</script>
