import { afterEach, describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import RunShowPage from '@/pages/RunShowPage.vue';
import { flushPromises, makePageEnv } from '../helpers';

function makeRun(overrides = {}) {
    return {
        id: 'run-1',
        run_type: 'requirement',
        state: 'gap_analysis_ready',
        archived_at: null,
        previous_run_id: null,
        testability_score: 72,
        testability_review_markdown: '# Review',
        test_plan_markdown: '# Plan',
        test_cases_markdown: null,
        ...overrides,
    };
}

let wrapper;

async function mountPage(run, runsActions = {}) {
    const env = makePageEnv({ runsState: { current: run }, runsActions });
    wrapper = mount(RunShowPage, { localVue: env.localVue, store: env.store, router: env.router, propsData: { id: run.id } });
    await flushPromises();

    return { wrapper, ...env };
}

function button(label) {
    return wrapper.findAll('button').filter((b) => b.text() === label).at(0);
}

afterEach(() => {
    wrapper?.destroy();
    wrapper = undefined;
});

describe('RunShowPage', () => {
    it('loads the run and shows Gate 1 for a run awaiting review', async () => {
        const { actions } = await mountPage(makeRun());

        expect(actions.fetchRun).toHaveBeenCalledWith('run-1');
        expect(wrapper.text()).toContain('Approve & Generate');
    });

    describe('failed runs', () => {
        it('shows the failed step and the recorded reason', async () => {
            await mountPage(makeRun({
                state: 'failed',
                failed_step: 'test_plan',
                error_message: 'The Anthropic API account used by BoschifAI has run out of credit.',
            }));

            expect(wrapper.text()).toContain('Run failed at step test_plan.');
            expect(wrapper.text()).toContain('Reason: The Anthropic API account used by BoschifAI has run out of credit.');
        });

        it('explains when no reason was recorded instead of leaving it blank', async () => {
            await mountPage(makeRun({ state: 'failed', failed_step: 'test_plan', error_message: '' }));

            expect(wrapper.text()).toContain('Reason: No reason was recorded for this failure');
        });

        it('retries the failed step', async () => {
            const { actions } = await mountPage(makeRun({ state: 'failed', failed_step: 'test_plan', failed_step_id: 42, error_message: 'x' }));

            await button('Retry step').trigger('click');
            await flushPromises();

            expect(actions.retryStep).toHaveBeenCalledWith({ id: 'run-1', stepId: 42 });
            expect(actions.fetchRun).toHaveBeenCalledTimes(2);
        });
    });

    describe('Gate 1 decisions', () => {
        it('approves and reloads', async () => {
            const { actions } = await mountPage(makeRun());

            await button('Approve & Generate').trigger('click');
            await flushPromises();

            expect(actions.decideGapAnalysis).toHaveBeenCalledWith({ id: 'run-1', decision: 'approved', comment: undefined });
            expect(actions.fetchRun).toHaveBeenCalledTimes(2);
        });

        it('follows a change request to the revised run', async () => {
            const { router, actions } = await mountPage(makeRun(), {
                decideGapAnalysis: vi.fn(() => Promise.resolve(makeRun({ state: 'gap_analysis_changes_requested', revised_run_id: 'run-2' }))),
            });

            await button('Request changes').trigger('click');
            await wrapper.find('textarea').setValue('Cover partial refunds');
            await button('Confirm request changes').trigger('click');
            await flushPromises();

            expect(actions.decideGapAnalysis).toHaveBeenCalledWith({ id: 'run-1', decision: 'changes_requested', comment: 'Cover partial refunds' });
            expect(router.currentRoute.name).toBe('runs.show');
            expect(router.currentRoute.params.id).toBe('run-2');
        });

        it('stays on the run after a rejection', async () => {
            const { router, actions } = await mountPage(makeRun());

            await button('Reject').trigger('click');
            await wrapper.find('textarea').setValue('Out of scope');
            await button('Confirm rejection').trigger('click');
            await flushPromises();

            expect(actions.decideGapAnalysis).toHaveBeenCalledWith({ id: 'run-1', decision: 'rejected', comment: 'Out of scope' });
            expect(router.currentRoute.params.id).toBeUndefined();
        });
    });

    it('shows a changes-requested run as closed, with the comment and a link to the rerun', async () => {
        await mountPage(makeRun({ state: 'gap_analysis_changes_requested', rejection_comment: 'Cover partial refunds', revised_run_id: 'run-2' }));

        expect(wrapper.text()).toContain('Changes were requested at Gate 1: Cover partial refunds');
        expect(wrapper.text()).not.toContain('This run was rejected');
        expect(wrapper.find('a[href="/runs/run-2"]').exists()).toBe(true);
    });

    it('shows a rejected run with its comment', async () => {
        await mountPage(makeRun({ state: 'gap_analysis_rejected', rejection_comment: 'Out of scope' }));

        expect(wrapper.text()).toContain('This run was rejected. Nothing was pushed. See comment: Out of scope');
    });

    it('links back to the run it was edited from', async () => {
        await mountPage(makeRun({ previous_run_id: 'run-0' }));

        expect(wrapper.find('a[href="/runs/run-0"]').exists()).toBe(true);
    });

    it('archives an active run', async () => {
        const { actions } = await mountPage(makeRun());

        await button('Archive run').trigger('click');
        await flushPromises();

        expect(actions.archiveRun).toHaveBeenCalledWith('run-1');
    });

    it('hides the gate for an archived run and offers to restore it', async () => {
        const { actions } = await mountPage(makeRun({ archived_at: '2026-10-12' }));

        expect(wrapper.text()).toContain('This run is archived.');
        expect(wrapper.text()).not.toContain('Approve & Generate');

        await button('Restore this run').trigger('click');
        await flushPromises();
        expect(actions.unarchiveRun).toHaveBeenCalledWith('run-1');
    });

    it('polls while a step is running, and stops when the page is left', async () => {
        vi.useFakeTimers();
        window.axios.get.mockResolvedValue({ data: {} }); // live activity panel
        const { actions } = await mountPage(makeRun({ state: 'gap_analysis_running' }));

        await vi.advanceTimersByTimeAsync(5000);
        expect(actions.fetchRun).toHaveBeenCalledTimes(2);

        wrapper.destroy();
        await vi.advanceTimersByTimeAsync(15000);
        expect(actions.fetchRun).toHaveBeenCalledTimes(2);
        wrapper = undefined;
    });

    describe('Gate 2 and in-flight actions', () => {
        const GATE_2 = {
            state: 'local_execution_complete',
            generated_file_path: 'tests/Feature/RefundTest.php',
            generated_code: '<?php',
            execution_result: { total: 2, passed: 1, failed: 1, duration_ms: 500, tests: [] },
            pre_push_confidence: null,
        };

        it('approves the push and reloads', async () => {
            const { actions } = await mountPage(makeRun(GATE_2));

            await button('Approve & Push to GitHub').trigger('click');
            await flushPromises();

            expect(actions.decidePush).toHaveBeenCalledWith({ id: 'run-1', decision: 'approved', comment: undefined });
            expect(actions.fetchRun).toHaveBeenCalledTimes(2);
        });

        it('rejects the push with a reason', async () => {
            const { actions } = await mountPage(makeRun(GATE_2));

            await button('Reject').trigger('click');
            await wrapper.find('textarea').setValue('Wrong target');
            await button('Confirm rejection').trigger('click');
            await flushPromises();

            expect(actions.decidePush).toHaveBeenCalledWith({ id: 'run-1', decision: 'rejected', comment: 'Wrong target' });
        });

        it('re-runs the tests and asks Claude to fix failing ones', async () => {
            const { actions } = await mountPage(makeRun(GATE_2));

            await button('Re-run tests').trigger('click');
            await flushPromises();
            await button('Fix failing tests').trigger('click');
            await flushPromises();

            expect(actions.rerunLocalExecution).toHaveBeenCalledWith('run-1');
            expect(actions.fixFailingTests).toHaveBeenCalledWith('run-1');
        });

        it('stops a running step after confirmation', async () => {
            vi.useFakeTimers();
            window.axios.get.mockResolvedValue({ data: {} }); // live activity panel
            const { actions } = await mountPage(makeRun({ state: 'generation_running' }));

            await button('Stop').trigger('click');
            await button('Stop run').trigger('click');
            await flushPromises();

            expect(actions.cancelRun).toHaveBeenCalledWith('run-1');
        });

        it('shows CI progress, the final report and a standalone action’s document', async () => {
            await mountPage(makeRun({ state: 'ci_pending', pr_url: 'https://github.com/acme/api/pull/7', ci_conclusion: null }));
            expect(wrapper.text()).toContain('CI status');
            expect(wrapper.text()).toContain('pending');
            wrapper.destroy();

            await mountPage(makeRun({ state: 'report_ready', confidence_breakdown: { composite: 90, testability: 90, test_case_quality: 90, local_execution: 90, ci: 90 } }));
            expect(wrapper.text()).toContain('90%');
            expect(wrapper.text()).toContain('Green');
            wrapper.destroy();

            await mountPage(makeRun({ state: 'standalone_complete', run_type: 'build_skills', testability_score: 77, project_skill_markdown: '# Project skill' }));
            expect(wrapper.text()).toContain('77%');
            expect(wrapper.find('h1').text()).toBe('Project skill');
            wrapper.destroy();

            await mountPage(makeRun({ state: 'standalone_complete', run_type: 'build_knowledge_base', testability_score: null, codebase_knowledge_base_markdown: null }));
            expect(wrapper.text()).toContain('No document was produced.');
        });

        it('explains blocked and cancelled runs', async () => {
            await mountPage(makeRun({ state: 'generation_blocked', blocked_reason: 'validator failed twice' }));
            expect(wrapper.text()).toContain('validator failed twice');
            wrapper.destroy();

            await mountPage(makeRun({ state: 'cancelled' }));
            expect(wrapper.text()).toContain('This run was stopped before it finished.');
            expect(wrapper.find('a[href="/runs/run-1/edit"]').exists()).toBe(true);
        });

        it('reloads when navigating straight to another run', async () => {
            const { actions } = await mountPage(makeRun());

            await wrapper.setProps({ id: 'run-2' });
            await flushPromises();

            expect(actions.fetchRun).toHaveBeenLastCalledWith('run-2');
        });
    });
});
