import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import TestCaseReviewPanel from '@/components/TestCaseReviewPanel.vue';

describe('TestCaseReviewPanel', () => {
    it('renders with null test cases (same null-from-API guard as the Gate 1 panel)', () => {
        const wrapper = mount(TestCaseReviewPanel, { propsData: { testCasesMarkdown: null } });

        expect(wrapper.text()).toContain('Generated test cases');
        expect(wrapper.text()).not.toContain('—');
    });

    it('counts TC headings and shows the validator verdict', () => {
        const wrapper = mount(TestCaseReviewPanel, {
            propsData: { testCasesMarkdown: '## TC-REQ-001 A\n\n## TC-REQ-002 B\n\n### Not a case', verdict: 'NEEDS_FIXES' },
        });

        expect(wrapper.text()).toContain('— 2 test cases');
        expect(wrapper.text()).toContain('Validator: NEEDS_FIXES');
    });

    it('is collapsed by default and expands on click', async () => {
        const wrapper = mount(TestCaseReviewPanel, { propsData: { testCasesMarkdown: '## TC-REQ-001 Only one' } });
        expect(wrapper.text()).toContain('Show test cases');
        expect(wrapper.text()).toContain('— 1 test case');

        await wrapper.find('button').trigger('click');

        expect(wrapper.text()).toContain('Hide test cases');
        expect(wrapper.find('.prose').exists()).toBe(true);
    });
});
