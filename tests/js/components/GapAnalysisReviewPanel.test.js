import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import GapAnalysisReviewPanel from '@/components/GapAnalysisReviewPanel.vue';

function mountPanel(propsData = {}) {
    return mount(GapAnalysisReviewPanel, {
        propsData: {
            runType: 'requirement',
            testabilityReviewMarkdown: '# Review',
            testPlanMarkdown: '# Plan',
            testabilityScore: 82,
            ...propsData,
        },
    });
}

function button(wrapper, label) {
    return wrapper.findAll('button').filter((b) => b.text() === label).at(0);
}

describe('GapAnalysisReviewPanel', () => {
    // Regression: the API sends null (not undefined) for test_cases_markdown at Gate 1 of a
    // requirement run, so the prop default never applied and `.match` on null threw — Vue then
    // dropped the whole panel and Gate 1 rendered blank.
    it('renders when a scored requirement run has no test cases yet (null from the API)', () => {
        const wrapper = mountPanel({ testCasesMarkdown: null, testabilityScore: 67 });

        expect(wrapper.text()).toContain('Testability Score');
        expect(wrapper.text()).toContain('Approve & Generate');
        expect(wrapper.text()).toContain('Testability review');
        expect(wrapper.text()).toContain('Test plan');
    });

    it.each([
        [82, 'Green'],
        [65, 'Yellow'],
        [54, 'Red'],
    ])('shows a score of %i as the %s band', (score, band) => {
        expect(mountPanel({ testabilityScore: score }).text()).toContain(band);
    });

    it('warns about a Red requirement', () => {
        expect(mountPanel({ testabilityScore: 40 }).text()).toContain('this requirement is likely to produce ambiguous');
    });

    it('hides the score block when there is no score', () => {
        expect(mountPanel({ testabilityScore: null }).text()).not.toContain('Testability Score');
    });

    it('shows the codebase analysis and proposed test cases for coverage runs', () => {
        const wrapper = mountPanel({
            runType: 'coverage',
            codebaseKnowledgeBaseMarkdown: '# Stack',
            testCasesMarkdown: '## TC-COV-001\n\n## TC-COV-002\n',
            testabilityScore: 75,
        });

        expect(wrapper.text()).toContain('Codebase Understanding Score');
        expect(wrapper.text()).toContain('Codebase analysis');
        expect(wrapper.text()).toContain('Proposed test cases');
        expect(wrapper.text()).toContain('2 test cases');
    });

    it('approves straight away', async () => {
        const wrapper = mountPanel();

        await button(wrapper, 'Approve & Generate').trigger('click');

        expect(wrapper.emitted('approve')).toHaveLength(1);
    });

    it('requires a comment before requesting changes, then emits it', async () => {
        const wrapper = mountPanel();
        await button(wrapper, 'Request changes').trigger('click');

        expect(wrapper.text()).toContain('what should change');
        expect(wrapper.text()).toContain('a new linked run starts');
        expect(button(wrapper, 'Confirm request changes').attributes('disabled')).toBeDefined();

        await wrapper.find('textarea').setValue('Also cover partial refunds');
        await button(wrapper, 'Confirm request changes').trigger('click');

        expect(wrapper.emitted('request-changes')).toEqual([['Also cover partial refunds']]);
        expect(wrapper.emitted('reject')).toBeUndefined();
        expect(wrapper.find('textarea').exists()).toBe(false);
    });

    // Regression: both modes used to be independent flags, so clicking Reject after Request
    // changes still submitted changes_requested.
    it('submits a rejection when Reject is clicked after Request changes', async () => {
        const wrapper = mountPanel();
        await button(wrapper, 'Request changes').trigger('click');
        await button(wrapper, 'Reject').trigger('click');

        expect(wrapper.text()).toContain('reason for rejection');
        expect(wrapper.text()).toContain('Ends this run');

        await wrapper.find('textarea').setValue('Not worth testing');
        await button(wrapper, 'Confirm rejection').trigger('click');

        expect(wrapper.emitted('reject')).toEqual([['Not worth testing']]);
        expect(wrapper.emitted('request-changes')).toBeUndefined();
    });
});
