import { afterEach, describe, expect, it, vi } from 'vitest';
import { buildPrePushReportHtml, downloadPrePushReport } from '@/lib/prePushReport';

function makeRun(overrides = {}) {
    return {
        id: 'run-1',
        run_type: 'requirement',
        repo_name: 'acme/backend',
        target_file_path: 'app/Services/RefundService.php',
        created_at: '2026-10-12T10:00:00Z',
        execution_result: null,
        pre_push_confidence: null,
        testability_review_markdown: '# Review\n\nLooks testable.',
        codebase_knowledge_base_markdown: '# KB',
        test_cases_markdown: '## TC-REQ-001',
        generated_file_path: 'tests/Feature/RefundTest.php',
        generated_code: '<?php // test',
        has_multi_tenant_test: true,
        ...overrides,
    };
}

function parse(html) {
    return new DOMParser().parseFromString(html, 'text/html');
}

describe('buildPrePushReportHtml', () => {
    it('includes the identifying metadata', () => {
        const doc = parse(buildPrePushReportHtml(makeRun(), '12 Oct 2026'));

        expect(doc.title).toContain('acme/backend');
        expect(doc.body.textContent).toContain('Generated 12 Oct 2026');
        expect(doc.body.textContent).toContain('Requirement');
        expect(doc.body.textContent).toContain('app/Services/RefundService.php');
    });

    it('falls back when the target file was left blank', () => {
        const doc = parse(buildPrePushReportHtml(makeRun({ target_file_path: '' }), 'now'));

        expect(doc.body.textContent).toContain('(determined by the pipeline)');
    });

    it('labels coverage runs and shows the codebase analysis instead of the testability review', () => {
        const doc = parse(buildPrePushReportHtml(makeRun({ run_type: 'coverage', codebase_knowledge_base_markdown: '# Stack: Laravel' }), 'now'));

        expect(doc.body.textContent).toContain('Coverage scan');
        expect(doc.querySelector('#analysis h2').textContent).toBe('Codebase Analysis');
        expect(doc.querySelector('#analysis').textContent).toContain('Stack: Laravel');
        expect(doc.querySelector('#test-cases h2').textContent).toBe('Proposed Test Cases');
    });

    it('says when local execution and confidence are not available yet', () => {
        const doc = parse(buildPrePushReportHtml(makeRun(), 'now'));

        expect(doc.querySelector('#local-execution').textContent).toContain('Local execution has not completed.');
        expect(doc.querySelector('#confidence').textContent).toContain('Not available.');
    });

    it('renders execution results with one row per test', () => {
        const run = makeRun({
            execution_result: {
                total: 2, passed: 1, failed: 1, skipped: 0, duration_ms: 1500,
                tests: [
                    { name: 'refund works', status: 'passed', message: null },
                    { name: 'refund rejects', status: 'failed', message: 'Expected 422' },
                ],
            },
        });
        const doc = parse(buildPrePushReportHtml(run, 'now'));
        const section = doc.querySelector('#local-execution');

        expect(section.textContent).toContain('1.5s');
        expect(section.querySelectorAll('tbody tr')).toHaveLength(2);
        expect(section.querySelector('.status-failed').textContent).toBe('failed');
        expect(section.textContent).toContain('Expected 422');
    });

    it.each([
        [85, 'Green'],
        [80, 'Green'],
        [79, 'Yellow'],
        [60, 'Yellow'],
        [59, 'Red'],
    ])('bands a composite confidence of %i as %s', (composite, label) => {
        const run = makeRun({ pre_push_confidence: { composite, testability: 70, test_case_quality: 80, local_execution: 90 } });
        const doc = parse(buildPrePushReportHtml(run, 'now'));

        expect(doc.querySelector('.confidence-badge').textContent).toContain(`${composite}%`);
        expect(doc.querySelector('.band-label').textContent).toBe(`(${label})`);
    });

    it('warns when no cross-team access test was detected', () => {
        expect(buildPrePushReportHtml(makeRun({ has_multi_tenant_test: false }), 'now')).toContain('No cross-team access test detected');
        expect(buildPrePushReportHtml(makeRun({ has_multi_tenant_test: true }), 'now')).not.toContain('No cross-team access test detected');
    });

    it('escapes generated code and metadata instead of rendering it as HTML', () => {
        const run = makeRun({ repo_name: '<b>evil</b>', generated_code: '<script>alert(1)</script>' });
        const html = buildPrePushReportHtml(run, 'now');

        expect(html).not.toContain('<script>alert(1)</script>');
        expect(html).toContain('&lt;script&gt;alert(1)&lt;/script&gt;');
        expect(html).toContain('&lt;b&gt;evil&lt;/b&gt;');
    });

    it('sanitizes AI-generated markdown', () => {
        const run = makeRun({ testability_review_markdown: 'Hi <img src=x onerror="alert(1)"> <script>alert(2)</script>' });
        const doc = parse(buildPrePushReportHtml(run, 'now'));
        const analysis = doc.querySelector('#analysis');

        expect(analysis.querySelector('script')).toBeNull();
        expect(analysis.querySelector('img').getAttribute('onerror')).toBeNull();
    });
});

describe('downloadPrePushReport', () => {
    afterEach(() => {
        delete URL.createObjectURL;
        delete URL.revokeObjectURL;
    });

    it('downloads the report as a named .html file and releases the object URL', () => {
        URL.createObjectURL = vi.fn(() => 'blob:report');
        URL.revokeObjectURL = vi.fn();
        const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {});

        downloadPrePushReport(makeRun({ id: 'abc' }));

        const blob = URL.createObjectURL.mock.calls[0][0];
        expect(blob.type).toBe('text/html');
        expect(click).toHaveBeenCalledTimes(1);
        expect(click.mock.contexts[0].download).toBe('boschifai-pre-push-report-abc.html');
        expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:report');
        expect(document.querySelector('a[download]')).toBeNull();
    });
});
