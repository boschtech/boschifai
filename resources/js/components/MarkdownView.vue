<template>
    <!--
        No `prose-slate`/`prose-gray` color modifier: those set the typography plugin's
        --tw-prose-* vars to a fixed palette, which would override the theme-aware values
        tailwind.config.js's `theme.typography.DEFAULT.css` already points at our own
        fg/fg-strong/border tokens. Plain `prose` picks up that DEFAULT customization, so this
        follows the light/dark toggle automatically without a `dark:prose-invert` variant.
    -->
    <div class="prose prose-sm max-w-none" v-html="safeHtml"></div>
</template>

<script>
import { marked, Renderer } from 'marked';
import DOMPurify from 'dompurify';

// Renders AI-generated markdown (testability reviews, test plans, test cases) as real
// formatted HTML instead of raw text in a <pre> block. This intentionally replaces the
// earlier <pre>-only rendering: injecting markdown-derived HTML via v-html is only safe once
// it's been sanitized — DOMPurify strips script tags, event-handler attributes, and
// javascript: URLs, closing the exact XSS gap a prompt-injected or malformed report could
// otherwise exploit. Never render marked's output directly without this sanitize step.
marked.setOptions({ gfm: true, breaks: false });

// marked stopped assigning heading `id`s by default years ago (the old `headerIds` option is
// gone entirely as of v5) — without this, every `[...](#some-heading)` link these generated
// docs are full of (in-document Table of Contents, "back to contents" footers) silently does
// nothing, since there's no matching id anywhere in the rendered HTML. Reimplemented directly
// rather than pulling in `marked-gfm-heading-id`: that package's peer range tops out at
// marked@12, and this app is on marked@18 — forcing an incompatible peer dependency for ~15
// lines of logic isn't worth the risk of it silently breaking on marked's internals.
//
// Matches GitHub's actual slug algorithm closely enough to reproduce it exactly for every
// anchor these AI-generated documents use in practice (verified against a real 51-test-case
// document's own hand-written TOC links): lowercase, strip characters that aren't a letter/
// number/underscore/hyphen/space (deleted, not replaced — critically, a run of punctuation
// between two spaces like " — " or " & " leaves both spaces behind, which is why GitHub's own
// slugs often contain a double hyphen), then convert each remaining space to a hyphen.
function slugify(text) {
    return text
        .trim()
        .toLowerCase()
        .replace(/[^\p{L}\p{N}_ -]/gu, '')
        .replace(/ /g, '-');
}

/** A fresh instance (and fresh de-dup counter) per render — no state shared across documents. */
function createHeadingRenderer() {
    const renderer = new Renderer();
    const seen = new Map();

    renderer.heading = function ({ tokens, depth }) {
        const text = this.parser.parseInline(tokens, this.parser.textRenderer);
        const base = slugify(text) || 'section';
        const occurrence = seen.get(base) ?? 0;
        seen.set(base, occurrence + 1);
        const id = occurrence === 0 ? base : `${base}-${occurrence}`;

        return `<h${depth} id="${id}">${this.parser.parseInline(tokens)}</h${depth}>\n`;
    };

    return renderer;
}

export default {
    name: 'MarkdownView',

    props: {
        content: { type: String, default: '' },
    },

    computed: {
        safeHtml() {
            const rawHtml = marked.parse(this.content || '', { renderer: createHeadingRenderer() });

            return DOMPurify.sanitize(rawHtml, {
                ALLOWED_URI_REGEXP: /^(?:(?:https?|mailto):|[^a-z]|[a-z+.\-]+(?:[^a-z+.\-:]|$))/i,
            });
        },
    },
};
</script>
