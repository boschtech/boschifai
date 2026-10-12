import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import MarkdownView from '@/components/MarkdownView.vue';

function render(content) {
    return mount(MarkdownView, { propsData: { content } });
}

describe('MarkdownView', () => {
    it('renders markdown, including GFM tables', () => {
        const wrapper = render('# Title\n\n| A | B |\n|---|---|\n| 1 | 2 |');

        expect(wrapper.find('h1').text()).toBe('Title');
        expect(wrapper.findAll('td').wrappers.map((td) => td.text())).toEqual(['1', '2']);
    });

    it('renders nothing for empty content', () => {
        expect(render('').html()).toContain('<div class="prose prose-sm max-w-none"></div>');
    });

    // Must match GitHub's slugs exactly — the generated docs' own TOC links depend on it.
    it.each([
        ['## Test Cases', 'test-cases'],
        ['## Scope & Risks', 'scope--risks'],
        ['## 1. Overview — Summary', '1-overview--summary'],
        ['## Café Ünïcode', 'café-ünïcode'],
        ['## !!!', 'section'],
    ])('gives "%s" the id "%s"', (markdown, id) => {
        expect(render(markdown).find('h2').attributes('id')).toBe(id);
    });

    it('de-duplicates repeated headings within one document', () => {
        const ids = render('## Notes\n\n## Notes\n\n## Notes').findAll('h2').wrappers.map((h) => h.attributes('id'));

        expect(ids).toEqual(['notes', 'notes-1', 'notes-2']);
    });

    it('does not carry the de-dup counter over to the next document', () => {
        render('## Notes');

        expect(render('## Notes').find('h2').attributes('id')).toBe('notes');
    });

    it('keeps in-document anchor links working', () => {
        expect(render('[Jump](#test-cases)').find('a').attributes('href')).toBe('#test-cases');
    });

    it('strips scripts, event handlers and javascript: links from AI-generated content', () => {
        const wrapper = render('<script>alert(1)</script>\n\n<img src="x" onerror="alert(2)">\n\n[click](javascript:alert(3))');

        expect(wrapper.find('script').exists()).toBe(false);
        expect(wrapper.find('img').attributes('onerror')).toBeUndefined();
        expect(wrapper.find('a').attributes('href')).toBeUndefined();
    });
});
