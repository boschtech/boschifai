import { afterEach, describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import RunsIndexPage from '@/pages/RunsIndexPage.vue';
import TestHistoryPage from '@/pages/TestHistoryPage.vue';
import ConfidenceReportsPage from '@/pages/ConfidenceReportsPage.vue';
import CoverageReportsPage from '@/pages/CoverageReportsPage.vue';
import TokenUsagePage from '@/pages/TokenUsagePage.vue';
import runs from '@/store/modules/runs';
import usage from '@/store/modules/usage';
import { apiError, flushPromises, makeLocalVueWithRouter, makeRealStore, mockApi } from '../helpers';

function run(overrides = {}) {
    return {
        id: 'r1',
        run_type: 'requirement',
        requirement_summary: 'Partial refunds',
        repo_name: 'acme/api',
        state: 'report_ready',
        archived_at: null,
        confidence_score: 88,
        confidence_breakdown: { testability: 90, test_case_quality: 85, local_execution: 100, ci: 75 },
        execution_result: { total: 4, passed: 4, failed: 0, duration_ms: 1200, coverage_report_url: '/coverage/r1' },
        ci_conclusion: 'success',
        generated_file_path: 'tests/Feature/RefundTest.php',
        target_file_path: 'app/Services/RefundService.php',
        testability_score: 80,
        updated_at: '2026-10-12',
        ...overrides,
    };
}

const RUNS = [
    run(),
    run({ id: 'r2', requirement_summary: 'Login lockout', repo_name: 'acme/web', state: 'failed', confidence_score: 55, ci_conclusion: 'failure', execution_result: { total: 3, passed: 1, failed: 2, duration_ms: 900 }, run_type: 'coverage', target_file_path: 'app/Auth/Lockout.php' }),
    run({ id: 'r3', requirement_summary: 'Export CSV', state: 'gap_analysis_ready', confidence_score: null, confidence_breakdown: null, execution_result: null, ci_conclusion: null, generated_file_path: null, testability_score: null }),
    run({ id: 'r4', requirement_summary: 'Bulk import', state: 'cancelled', confidence_score: 65, ci_conclusion: 'neutral' }),
];

let wrapper;

async function mountWith(Page, { api = {}, modules = { runs } } = {}) {
    mockApi(api);
    const { localVue, router } = makeLocalVueWithRouter({ path: '/x' });
    const store = makeRealStore(localVue, modules);
    wrapper = mount(Page, { localVue, store, router });
    await flushPromises();

    return { router, store };
}

function runsApi(list = RUNS, archivedList = []) {
    return { 'GET /api/runs': (config) => ({ data: config.params.archived ? archivedList : list }) };
}

function rows() {
    return wrapper.findAll('tbody tr').wrappers.map((tr) => tr.findAll('td').at(0).text());
}

function button(text) {
    return wrapper.findAll('button').filter((b) => b.text().includes(text)).at(0);
}

afterEach(() => {
    wrapper?.destroy();
    wrapper = undefined;
});

describe('RunsIndexPage', () => {
    it('lists runs with their state and confidence, and opens one on click', async () => {
        const { router } = await mountWith(RunsIndexPage, { api: runsApi() });

        expect(rows()).toEqual(['Partial refunds', 'Login lockout', 'Export CSV', 'Bulk import']);
        expect(wrapper.text()).toContain('88%');

        await wrapper.findAll('tbody tr').at(1).trigger('click');
        expect(router.currentRoute.params.id).toBe('r2');
    });

    it.each([
        ['report_ready', 'bg-success/10'],
        ['failed', 'bg-danger/10'],
        ['gap_analysis_ready', 'bg-warning/10'],
        ['cancelled', 'bg-surface-alt'],
        ['generation_running', 'bg-primary/10'],
    ])('colours the %s state', async (state, cls) => {
        await mountWith(RunsIndexPage, { api: runsApi([run({ state })]) });

        expect(wrapper.find('tbody span').classes()).toContain(cls);
    });

    it('offers Edit only for states that can be resubmitted', async () => {
        const { router } = await mountWith(RunsIndexPage, { api: runsApi() });
        const editButtons = () => wrapper.findAll('button[title="Edit run"]');

        expect(editButtons()).toHaveLength(2); // failed + cancelled

        await editButtons().at(0).trigger('click');
        expect(router.currentRoute.name).toBe('runs.edit');
        expect(router.currentRoute.params.id).toBe('r2');
    });

    it('invites a first submission when there are no runs', async () => {
        await mountWith(RunsIndexPage, { api: runsApi([]) });

        expect(wrapper.text()).toContain('No runs yet.');
        expect(wrapper.text()).toContain('Submit a requirement');
    });

    it('shows the API error', async () => {
        await mountWith(RunsIndexPage, { api: { 'GET /api/runs': () => { throw apiError('Database unavailable'); } } });

        expect(wrapper.text()).toContain('Database unavailable');
    });

    it('switches between active and archived runs', async () => {
        await mountWith(RunsIndexPage, { api: runsApi(RUNS, []) });

        await button('Show archived').trigger('click');
        await flushPromises();

        expect(wrapper.text()).toContain('Archived Runs');
        expect(wrapper.text()).toContain('No archived runs.');

        await button('Show active runs').trigger('click');
        await flushPromises();
        expect(rows()).toHaveLength(4);
    });

    it('archives an active run and restores an archived one', async () => {
        const archive = vi.fn(() => ({ data: run() }));
        const unarchive = vi.fn(() => ({ data: run() }));
        await mountWith(RunsIndexPage, {
            api: {
                ...runsApi([run(), run({ id: 'r9', archived_at: '2026-10-01' })]),
                'POST /api/runs/r1/archive': archive,
                'POST /api/runs/r9/unarchive': unarchive,
            },
        });

        await wrapper.find('button[title="Archive run"]').trigger('click');
        await flushPromises();
        await wrapper.find('button[title="Restore run"]').trigger('click');
        await flushPromises();

        expect(archive).toHaveBeenCalled();
        expect(unarchive).toHaveBeenCalled();
        expect(wrapper.vm.archivingId).toBeNull();
    });

    it('deletes a run only after confirmation', async () => {
        const deleted = vi.fn(() => ({}));
        await mountWith(RunsIndexPage, { api: { ...runsApi(), 'DELETE /api/runs/r1': deleted } });

        await wrapper.findAll('button[title="Delete run"]').at(0).trigger('click');
        expect(wrapper.text()).toContain('"Partial refunds"');
        expect(wrapper.text()).toContain('This cannot be undone.');

        await button('Cancel').trigger('click');
        expect(deleted).not.toHaveBeenCalled();

        await wrapper.findAll('button[title="Delete run"]').at(0).trigger('click');
        await wrapper.findAll('button').filter((b) => b.text() === 'Delete').at(0).trigger('click');
        await flushPromises();

        expect(deleted).toHaveBeenCalled();
        expect(rows()).not.toContain('Partial refunds');
    });
});

describe('TestHistoryPage', () => {
    it('lists every run with tests and CI results', async () => {
        await mountWith(TestHistoryPage, { api: runsApi() });

        expect(rows()).toHaveLength(4);
        expect(wrapper.text()).toContain('4/4');
        expect(wrapper.text()).toContain('1/3');
    });

    it.each([
        ['PARTIAL', ['Partial refunds']],
        ['ACME/WEB', ['Login lockout']],
        ['lockout.php', ['Login lockout']],
        ['RefundTest', ['Partial refunds', 'Login lockout', 'Bulk import']],
    ])('searches requirement, repo and file paths for "%s"', async (term, expected) => {
        await mountWith(TestHistoryPage, { api: runsApi() });

        await wrapper.find('input[type="text"]').setValue(term);

        expect(rows()).toEqual(expected);
    });

    it('filters by state and run type, offering only states that exist', async () => {
        await mountWith(TestHistoryPage, { api: runsApi() });
        const [stateSelect, typeSelect] = wrapper.findAll('select').wrappers;

        expect(stateSelect.findAll('option').wrappers.map((o) => o.text())).toEqual(['All states', 'cancelled', 'failed', 'gap_analysis_ready', 'report_ready']);

        await stateSelect.setValue('failed');
        expect(rows()).toEqual(['Login lockout']);

        await stateSelect.setValue('');
        await typeSelect.setValue('coverage');
        expect(rows()).toEqual(['Login lockout']);
    });

    it('says when nothing matches', async () => {
        await mountWith(TestHistoryPage, { api: runsApi() });
        await wrapper.find('input[type="text"]').setValue('nothing like this');

        expect(wrapper.text()).toContain('No runs match this search.');
    });

    it('colours states and CI conclusions', async () => {
        await mountWith(TestHistoryPage, { api: runsApi() });

        expect(wrapper.vm.stateClasses('report_ready')).toContain('success');
        expect(wrapper.vm.stateClasses('failed')).toContain('danger');
        expect(wrapper.vm.stateClasses('local_execution_complete')).toContain('warning');
        expect(wrapper.vm.stateClasses('cancelled')).toContain('muted');
        expect(wrapper.vm.stateClasses('pushing')).toContain('primary');
        expect(wrapper.vm.ciClasses('success')).toContain('success');
        expect(wrapper.vm.ciClasses('cancelled')).toContain('danger');
        expect(wrapper.vm.ciClasses('neutral')).toContain('primary');
    });

    it('can include archived runs', async () => {
        await mountWith(TestHistoryPage, { api: runsApi(RUNS, [run({ id: 'old', requirement_summary: 'Old one' })]) });

        await button('Include archived').trigger('click');
        await flushPromises();

        expect(rows()).toEqual(['Old one']);
        expect(button('Show active runs').exists()).toBe(true);
    });

    it('opens a run on click, and shows API errors', async () => {
        const { router } = await mountWith(TestHistoryPage, { api: runsApi() });
        await wrapper.findAll('tbody tr').at(0).trigger('click');
        expect(router.currentRoute.params.id).toBe('r1');
        wrapper.destroy();

        await mountWith(TestHistoryPage, { api: { 'GET /api/runs': () => { throw apiError('Boom'); } } });
        expect(wrapper.text()).toContain('Boom');
    });
});

describe('ConfidenceReportsPage', () => {
    it('summarises scored runs by band and average', async () => {
        await mountWith(ConfidenceReportsPage, { api: runsApi() });

        // Scored: 88 (Green), 55 (Red), 65 (Yellow) → average 69
        expect(wrapper.text()).toMatch(/Scored runs\s*3/);
        expect(wrapper.text()).toMatch(/Average confidence\s*69%/);
        expect(wrapper.text()).toMatch(/Green \(≥80%\)\s*1/);
        expect(wrapper.text()).toMatch(/Yellow \/ Red\s*1\s*\/ 1/);
    });

    it('sorts by confidence with unscored runs last, and shows each component', async () => {
        await mountWith(ConfidenceReportsPage, { api: runsApi() });

        expect(rows()).toEqual(['Partial refunds', 'Bulk import', 'Login lockout', 'Export CSV']);
        const firstRow = wrapper.findAll('tbody tr').at(0).text();
        expect(firstRow).toContain('90%');
        expect(firstRow).toContain('75%');
        expect(wrapper.findAll('tbody tr').at(3).text()).toContain('—');
    });

    it('handles no scored runs, no runs, errors and clicks', async () => {
        await mountWith(ConfidenceReportsPage, { api: runsApi([run({ confidence_score: null })]) });
        expect(wrapper.text()).toMatch(/Average confidence\s*—/);
        wrapper.destroy();

        await mountWith(ConfidenceReportsPage, { api: runsApi([]) });
        expect(wrapper.text()).toContain('No runs yet.');
        wrapper.destroy();

        await mountWith(ConfidenceReportsPage, { api: { 'GET /api/runs': () => { throw apiError('Nope'); } } });
        expect(wrapper.text()).toContain('Nope');
        wrapper.destroy();

        const { router } = await mountWith(ConfidenceReportsPage, { api: runsApi() });
        await wrapper.findAll('tbody tr').at(0).trigger('click');
        expect(router.currentRoute.params.id).toBe('r1');
    });

    it('sorts two unscored runs stably', async () => {
        await mountWith(ConfidenceReportsPage, { api: runsApi([run({ id: 'a', confidence_score: null, requirement_summary: 'A' }), run({ id: 'b', confidence_score: null, requirement_summary: 'B' })]) });

        expect(rows()).toEqual(['A', 'B']);
    });
});

describe('CoverageReportsPage', () => {
    it('lists only runs that generated a test file, with totals', async () => {
        await mountWith(CoverageReportsPage, { api: runsApi() });

        expect(rows()).toEqual(['Partial refunds', 'Login lockout', 'Bulk import']);
        expect(wrapper.text()).toMatch(/Files generated\s*3/);
        expect(wrapper.text()).toMatch(/Tests run locally\s*11/);
        expect(wrapper.text()).toMatch(/Passed\s*9/);
        expect(wrapper.text()).toMatch(/Failed\s*2/);
    });

    it('marks failures, links the coverage report and handles missing results', async () => {
        await mountWith(CoverageReportsPage, { api: runsApi([...RUNS, run({ id: 'r5', requirement_summary: 'Pending', execution_result: null, testability_score: null })]) });

        expect(wrapper.find('a[href="/coverage/r1"]').text()).toBe('View');
        expect(wrapper.findAll('tbody tr').at(1).find('.bg-danger\\/10').text()).toContain('1/3 passed');
        expect(wrapper.findAll('tbody tr').at(0).find('.bg-success\\/10').exists()).toBe(true);
        expect(wrapper.findAll('tbody tr').at(3).text()).toContain('Not run yet');
    });

    it('handles no files, errors and clicks', async () => {
        await mountWith(CoverageReportsPage, { api: runsApi([run({ generated_file_path: null })]) });
        expect(wrapper.text()).toContain('No generated test files yet.');
        wrapper.destroy();

        await mountWith(CoverageReportsPage, { api: { 'GET /api/runs': () => { throw apiError('Nope'); } } });
        expect(wrapper.text()).toContain('Nope');
        wrapper.destroy();

        const { router } = await mountWith(CoverageReportsPage, { api: runsApi() });
        await wrapper.findAll('tbody tr').at(0).trigger('click');
        expect(router.currentRoute.params.id).toBe('r1');
    });
});

describe('TokenUsagePage', () => {
    const DETAILS = {
        summary: {
            month: 'October 2026', total_tokens: 515402, total_cost_usd: 0.5612,
            input_tokens: 1200, output_tokens: 3400, cache_creation_input_tokens: 10000, cache_read_input_tokens: 500802,
        },
        invocations: [
            { id: 1, run_id: 'r1', requirement_summary: 'Partial refunds', repo_name: 'acme/api', step_key: 'gap_analysis', total_tokens: 400000, total_cost_usd: 0.41, num_turns: 8, duration_ms: 65000, stop_reason: 'end_turn', timed_out: false, created_at: '2026-10-12' },
            { id: 2, run_id: 'r2', requirement_summary: null, repo_name: null, step_key: 'test_plan', total_tokens: 0, total_cost_usd: null, num_turns: null, duration_ms: null, stop_reason: null, timed_out: true, created_at: '2026-10-12' },
            { id: 3, run_id: 'r3', requirement_summary: 'x', repo_name: 'y', step_key: 'push', total_tokens: 10, total_cost_usd: 0, num_turns: 1, duration_ms: 1000, stop_reason: null, timed_out: false, created_at: '2026-10-12' },
        ],
    };

    function mountUsage(api) {
        return mountWith(TokenUsagePage, { api, modules: { usage } });
    }

    it('shows the month’s totals and every invocation', async () => {
        await mountUsage({ 'GET /api/usage/tokens-this-month/details': DETAILS });

        expect(wrapper.text()).toContain('515,402');
        expect(wrapper.text()).toContain('$0.56');
        expect(wrapper.text()).toContain('500,802');
        expect(wrapper.text()).toContain('3 invocation(s) in October 2026');

        const [first, second] = wrapper.findAll('tbody tr').wrappers;
        expect(first.text()).toContain('$0.4100');
        expect(first.text()).toContain('65.0s');
        expect(first.text()).toContain('end_turn');
        expect(second.text()).toContain('Timed out');
        expect(second.findAll('td').at(0).text()).toBe('—');
    });

    it('opens the run behind an invocation', async () => {
        const { router } = await mountUsage({ 'GET /api/usage/tokens-this-month/details': DETAILS });

        await wrapper.findAll('tbody tr').at(1).trigger('click');

        expect(router.currentRoute.params.id).toBe('r2');
    });

    it('says when nothing has run this month', async () => {
        await mountUsage({ 'GET /api/usage/tokens-this-month/details': { ...DETAILS, invocations: [] } });

        expect(wrapper.text()).toContain('No Claude invocations recorded yet this month.');
    });

    it('shows the API error', async () => {
        await mountUsage({ 'GET /api/usage/tokens-this-month/details': () => { throw apiError('Usage unavailable'); } });

        expect(wrapper.text()).toContain('Usage unavailable');
    });
});
