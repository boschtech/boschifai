import { afterEach, describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import DocumentViewer from '@/components/DocumentViewer.vue';

const DOC = '# Title\n\n[Jump to scope](#scope--risks)\n\n## Overview\n\nText\n\n## Scope & Risks\n\nMore';

let viewer;

async function mountViewer(propsData) {
    viewer = mount(DocumentViewer, { propsData, attachTo: document.body });
    await viewer.vm.$nextTick();
    await viewer.vm.$nextTick();

    return viewer;
}

// Cleanup lives here (not at the end of each test) so a failing assertion can't leave a
// mounted viewer or a patched prototype behind for the next test.
afterEach(() => {
    viewer?.destroy();
    viewer = undefined;
    delete Element.prototype.scrollIntoView;
});

describe('DocumentViewer', () => {
    it('shows the empty slot when there is no content', async () => {
        viewer = mount(DocumentViewer, { propsData: { content: '' }, slots: { empty: 'No test plan yet.' } });

        expect(viewer.text()).toBe('No test plan yet.');
    });

    it('builds a contents sidebar from the top-level sections', async () => {
        await mountViewer({ content: DOC });

        expect(viewer.findAll('nav button').wrappers.map((b) => b.text())).toEqual(['Overview', 'Scope & Risks']);
    });

    it('skips the sidebar for a document with a single section', async () => {
        await mountViewer({ content: '## Only section\n\nText' });

        expect(viewer.find('nav').exists()).toBe(false);
    });

    it('scrolls the pane to a section from the sidebar and highlights it', async () => {
        const scrollIntoView = vi.fn();
        Element.prototype.scrollIntoView = scrollIntoView;
        await mountViewer({ content: DOC });

        await viewer.findAll('nav button').at(1).trigger('click');

        expect(scrollIntoView).toHaveBeenCalledWith({ behavior: 'smooth', block: 'start' });
        expect(viewer.vm.activeId).toBe('scope--risks');
        expect(viewer.findAll('nav button').at(1).classes()).toContain('text-primary-dark');
    });

    it('keeps in-document links inside the pane instead of jumping the whole page', async () => {
        Element.prototype.scrollIntoView = vi.fn();
        await mountViewer({ content: DOC });
        const link = viewer.find('a[href="#scope--risks"]');
        const event = new MouseEvent('click', { bubbles: true, cancelable: true });

        link.element.dispatchEvent(event);

        expect(event.defaultPrevented).toBe(true);
        expect(viewer.vm.activeId).toBe('scope--risks');
    });

    it('ignores clicks on anything other than an in-document link, and links to missing anchors', async () => {
        await mountViewer({ content: DOC });

        viewer.find('h1').element.click();
        viewer.vm.scrollToId('does-not-exist');

        expect(viewer.vm.activeId).toBeNull();
    });

    it('rebuilds the sidebar when the content changes', async () => {
        await mountViewer({ content: DOC });

        await viewer.setProps({ content: '## A\n\n## B\n\n## C' });
        await viewer.vm.$nextTick();

        expect(viewer.findAll('nav button')).toHaveLength(3);
    });
});
