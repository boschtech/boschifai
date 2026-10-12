import { afterEach, describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import PushApprovalPanel from '@/components/PushApprovalPanel.vue';
import PrePushConfidencePanel from '@/components/PrePushConfidencePanel.vue';
import ConfidenceReportCard from '@/components/ConfidenceReportCard.vue';
import GeneratedCodeViewer from '@/components/GeneratedCodeViewer.vue';
import LocalExecutionResultsPanel from '@/components/LocalExecutionResultsPanel.vue';
import CiStatusPanel from '@/components/CiStatusPanel.vue';
import ScoreDonutChart from '@/components/ScoreDonutChart.vue';
import * as report from '@/lib/prePushReport';

const PASSING = {
    total: 3, passed: 3, failed: 0, skipped: 0, duration_ms: 840, coverage_report_url: '/coverage/run-1',
    tests: [{ name: 'refund works', status: 'passed', message: null }],
};
const FAILING = {
    total: 3, passed: 2, failed: 1, skipped: 0, duration_ms: 900,
    tests: [
        { name: 'refund works', status: 'passed', message: null },
        { name: 'refund rejects', status: 'failed', message: 'Expected 422, got 500' },
        { name: 'refund later', status: 'skipped', message: null },
    ],
};

let wrapper;

function mountPanel(props = {}) {
    wrapper = mount(PushApprovalPanel, {
        propsData: {
            run: { id: 'run-1', pre_push_confidence: { composite: 84, testability: 80, test_case_quality: 90, local_execution: 100 } },
            testabilityScore: 82,
            testCasesMarkdown: '## TC-REQ-001',
            testCaseVerdict: 'APPROVED',
            generatedFilePath: 'tests/Feature/RefundTest.php',
            generatedCode: '<?php\nclass RefundTest {}\n',
            executionResult: PASSING,
            hasMultiTenantTest: true,
            ...props,
        },
    });

    return wrapper;
}

function buttons(text) {
    return wrapper.findAll('button').filter((b) => b.text().includes(text));
}

function button(text) {
    return buttons(text).at(0);
}

/** Each table row as its cells joined by single spaces. */
function rowTexts(w) {
    return w.findAll('tbody tr').wrappers.map((tr) => tr.findAll('td').wrappers.map((td) => td.text()).join(' '));
}

afterEach(() => {
    wrapper?.destroy();
    wrapper = undefined;
});

describe('PushApprovalPanel (Gate 2)', () => {
    it('shows the pre-push confidence, score, test results and review sections', () => {
        mountPanel();

        expect(wrapper.text()).toContain('Pre-Push Confidence');
        expect(wrapper.text()).toContain('84%');
        expect(wrapper.text()).toContain('Green');
        expect(wrapper.text()).toContain('3/3 passed');
        expect(wrapper.text()).toContain('840ms');
        expect(wrapper.find('a[href="/coverage/run-1"]').exists()).toBe(true);
        expect(wrapper.text()).toContain('Generated test cases');
        expect(wrapper.text()).toContain('Generated test file');
    });

    it('approves from either the top or the bottom of the page', async () => {
        mountPanel();
        const approveButtons = wrapper.findAll('button').filter((b) => b.text().includes('Approve & Push to GitHub'));

        expect(approveButtons).toHaveLength(2);
        await approveButtons.at(0).trigger('click');
        await approveButtons.at(1).trigger('click');

        expect(wrapper.emitted('approve')).toHaveLength(2);
    });

    it('requires a reason before rejecting', async () => {
        mountPanel();
        await button('Reject').trigger('click');

        expect(button('Confirm rejection').attributes('disabled')).toBeDefined();

        await wrapper.find('textarea').setValue('Missing tenant isolation test');
        await button('Confirm rejection').trigger('click');

        expect(wrapper.emitted('reject')).toEqual([['Missing tenant isolation test']]);
        expect(wrapper.find('textarea').exists()).toBe(false);
    });

    it('offers to fix failing tests only when some failed, and marks the result red', async () => {
        mountPanel();
        expect(buttons('Fix failing tests')).toHaveLength(0);
        wrapper.destroy();

        mountPanel({ executionResult: FAILING });
        expect(wrapper.find('.bg-danger\\/10').text()).toContain('2/3 passed');

        await button('Fix failing tests').trigger('click');
        await button('Re-run tests').trigger('click');

        expect(wrapper.emitted('fix-failing-tests')).toHaveLength(1);
        expect(wrapper.emitted('rerun-tests')).toHaveLength(1);
    });

    it('expands the per-test results', async () => {
        mountPanel({ executionResult: FAILING });
        expect(wrapper.text()).not.toContain('Expected 422, got 500');

        await button('Show tests').trigger('click');

        expect(wrapper.text()).toContain('Expected 422, got 500');
        expect(button('Hide tests').exists()).toBe(true);
    });

    it.each([
        [82, 'Green'],
        [65, 'Yellow'],
        [40, 'Red'],
    ])('bands a testability score of %i as %s', (testabilityScore, band) => {
        mountPanel({ testabilityScore });

        expect(wrapper.text()).toContain(band);
    });

    it('hides the score card when there is no score, and the confidence panel when there is none', () => {
        mountPanel({ testabilityScore: null, run: { id: 'run-1', pre_push_confidence: null } });

        expect(wrapper.text()).not.toContain('Testability Score');
        expect(wrapper.text()).not.toContain('Pre-Push Confidence');
        expect(wrapper.vm.band).toBeNull();
    });

    it('warns when no cross-team access test was detected', () => {
        expect(mountPanel({ hasMultiTenantTest: false }).text()).toContain('No cross-team access test detected.');
        wrapper.destroy();
        expect(mountPanel({ hasMultiTenantTest: true }).text()).not.toContain('No cross-team access test detected.');
    });

    it('downloads the HTML report for this run', async () => {
        const download = vi.spyOn(report, 'downloadPrePushReport').mockImplementation(() => {});
        mountPanel();

        await button('Download HTML report').trigger('click');

        expect(download).toHaveBeenCalledWith(expect.objectContaining({ id: 'run-1' }));
    });

    it('scrolls back to the top', async () => {
        const scrollTo = vi.spyOn(window, 'scrollTo').mockImplementation(() => {});
        mountPanel();

        await button('Back to top').trigger('click');

        expect(scrollTo).toHaveBeenCalledWith({ top: 0, behavior: 'smooth' });
    });
});

describe('PrePushConfidencePanel', () => {
    it.each([
        [85, 'Green', 'border-success'],
        [70, 'Yellow', 'border-warning'],
        [30, 'Red', 'border-danger'],
    ])('shows a composite of %i as %s', (composite, band, cls) => {
        const panel = mount(PrePushConfidencePanel, { propsData: { breakdown: { composite, testability: 70, test_case_quality: 80, local_execution: 90 } } });

        expect(panel.text()).toContain(`(${band})`);
        expect(panel.find(`.${cls}`).exists()).toBe(true);
        expect(rowTexts(panel)).toEqual([
            'Testability / Codebase understanding 70% 25%',
            'Test-case generation quality 80% 20%',
            'Local execution 90% 25%',
        ]);
    });
});

describe('ConfidenceReportCard', () => {
    it('shows each weighted component and its contribution', () => {
        const card = mount(ConfidenceReportCard, { propsData: { breakdown: { composite: 86, testability: 80, test_case_quality: 90, local_execution: 100, ci: 80 } } });

        expect(rowTexts(card)).toEqual([
            'Testability 80% 25% 20.0',
            'Test-case generation quality 90% 20% 18.0',
            'Local execution 100% 25% 25.0',
            'CI (Sonar Scan) 80% 30% 24.0',
        ]);
        expect(card.text()).toContain('Green');
        expect(card.find('.text-success').text()).toBe('86%');
    });

    it('labels coverage runs and treats a missing component as 0', () => {
        const card = mount(ConfidenceReportCard, { propsData: { runType: 'coverage', breakdown: { composite: 50, testability: 70 } } });

        expect(card.text()).toContain('Codebase understanding');
        expect(card.text()).toContain('Red');
        expect(rowTexts(card)[3]).toBe('CI (Sonar Scan) 0% 30% 0.0');
    });

    it('uses Yellow between 60 and 79, and requirement labels for an unknown run type', () => {
        const card = mount(ConfidenceReportCard, { propsData: { runType: 'mystery', breakdown: { composite: 70 } } });

        expect(card.text()).toContain('Yellow');
        expect(card.text()).toContain('Testability');
        expect(card.find('.text-warning').exists()).toBe(true);
    });
});

describe('GeneratedCodeViewer', () => {
    it('collapses the code by default and shows its size', async () => {
        const viewer = mount(GeneratedCodeViewer, { propsData: { filePath: 'tests/RefundTest.php', code: 'a\nb\nc' } });

        expect(viewer.text()).toContain('— 3 lines');
        expect(viewer.find('pre').exists()).toBe(false);

        await viewer.find('button').trigger('click');

        expect(viewer.find('pre').text()).toBe('a\nb\nc');
        expect(viewer.text()).toContain('Hide code');
    });

    it('omits the line count for empty code', () => {
        expect(mount(GeneratedCodeViewer, { propsData: { filePath: 'x.php' } }).text()).not.toContain('lines');
    });
});

describe('LocalExecutionResultsPanel', () => {
    it('lists each test with its status and failure message', () => {
        const panel = mount(LocalExecutionResultsPanel, { propsData: { result: FAILING } });

        expect(panel.findAll('li')).toHaveLength(3);
        expect(panel.find('p.text-danger').text()).toBe('refund rejects');
        expect(panel.find('pre').text()).toBe('Expected 422, got 500');
    });

    it('renders no list when there are no per-test results', () => {
        expect(mount(LocalExecutionResultsPanel, { propsData: { result: { total: 0 } } }).find('ul').exists()).toBe(false);
    });
});

describe('CiStatusPanel', () => {
    it.each([
        ['success', 'text-success', 'success'],
        ['failure', 'text-danger', 'failure'],
        [null, 'text-warning', 'pending'],
    ])('shows a %s conclusion', (conclusion, cls, label) => {
        const panel = mount(CiStatusPanel, { propsData: { prUrl: 'https://github.com/acme/api/pull/7', conclusion } });

        expect(panel.find('a').attributes('href')).toBe('https://github.com/acme/api/pull/7');
        expect(panel.find(`.${cls}`).text()).toBe(label);
        expect(panel.text()).toContain('Sonar Scan:');
    });
});

describe('ScoreDonutChart', () => {
    it('draws the score as a share of the circle, clamped to 0–100', () => {
        const arc = (score) => mount(ScoreDonutChart, { propsData: { score, size: 100, strokeWidth: 10 } }).vm;

        expect(arc(50).scoreLength).toBeCloseTo(arc(50).circumference / 2);
        expect(arc(150).scoreLength).toBeCloseTo(arc(150).circumference);
        expect(arc(-5).scoreLength).toBe(0);
        expect(mount(ScoreDonutChart, { propsData: { score: 66.6 } }).text()).toBe('67%');
    });
});
