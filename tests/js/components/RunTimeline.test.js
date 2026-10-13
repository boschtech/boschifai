import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import RunTimeline from '@/components/RunTimeline.vue';

function statuses(propsData) {
    return mount(RunTimeline, { propsData }).vm.steps.map((s) => `${s.key}:${s.status}`);
}

describe('RunTimeline', () => {
    it('marks earlier steps done and the waiting gate as current', () => {
        expect(statuses({ state: 'gap_analysis_ready' })).toEqual([
            'gap_analysis:done', 'gate_1:current', 'generation:pending', 'local_execution:pending',
            'gate_2:pending', 'ci:pending', 'report:pending',
        ]);
    });

    it.each(['gap_analysis_running', 'generation_running', 'pushing', 'ci_pending'])('shows %s as running', (state) => {
        expect(statuses({ state })).toContain(
            { gap_analysis_running: 'gap_analysis:running', generation_running: 'generation:running', pushing: 'ci:running', ci_pending: 'ci:running' }[state],
        );
    });

    it.each(['gap_analysis_rejected', 'push_rejected', 'generation_blocked'])('shows %s as blocked', (state) => {
        expect(statuses({ state }).some((s) => s.endsWith(':blocked'))).toBe(true);
    });

    it('does not show a changes-requested run as blocked — it was not rejected', () => {
        const result = statuses({ state: 'gap_analysis_changes_requested' });

        expect(result).toContain('gate_1:current');
        expect(result.some((s) => s.endsWith(':blocked'))).toBe(false);
    });

    it.each([
        ['code_generation', 'generation:current'],
        ['gap_analysis', 'gap_analysis:current'],
        ['push', 'ci:current'],
        [null, 'gap_analysis:current'],
    ])('shows a run waiting for credits before %s at that stage', (awaitingStep, expected) => {
        const result = statuses({ state: 'awaiting_credits', awaitingStep });

        expect(result).toContain(expected);
        expect(result.some((s) => s.endsWith(':blocked'))).toBe(false);
    });

    it('uses coverage labels for coverage runs', () => {
        const wrapper = mount(RunTimeline, { propsData: { state: 'draft', runType: 'coverage' } });

        expect(wrapper.text()).toContain('Codebase analysis');
        expect(wrapper.text()).toContain('Gate 1: Review test cases');
    });

    it('shows the two-step lifecycle for standalone actions', () => {
        expect(statuses({ state: 'standalone_complete', runType: 'build_skills' })).toEqual(['build_skills:done', 'report:current']);
    });

    it('falls back to the requirement steps for an unknown run type and the first step for an unknown state', () => {
        expect(statuses({ state: 'something_new', runType: 'mystery' })[0]).toBe('gap_analysis:current');
    });

    it('marks human gates with the person icon', () => {
        const wrapper = mount(RunTimeline, { propsData: { state: 'draft' } });
        const gateBadges = wrapper.findAll('li').filter((li) => li.text().includes('Gate'));

        expect(gateBadges.length).toBe(2);
        gateBadges.wrappers.forEach((li) => expect(li.text()).toContain('\u{1F464}'));
    });
});
