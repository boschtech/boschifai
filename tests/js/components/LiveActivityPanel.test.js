import { afterEach, describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import LiveActivityPanel from '@/components/LiveActivityPanel.vue';
import { flushPromises } from '../helpers';

const ACTIVITY = {
    step_key: 'test_plan',
    phase: 'running',
    message: 'Claude is writing the test plan',
    elapsed_seconds: 75,
    log_lines: ['Reading requirement', 'Writing plan'],
    cost_so_far_usd: '0.12',
};

let wrapper;

async function mountPanel(propsData = {}, activity = ACTIVITY) {
    window.axios.get.mockResolvedValue({ data: activity });
    wrapper = mount(LiveActivityPanel, { propsData: { runId: 'run-1', ...propsData }, attachTo: document.body });
    await flushPromises();

    return wrapper;
}

function toggle() {
    return wrapper.findAll('button').filter((b) => /output/.test(b.text())).at(0);
}

afterEach(() => {
    wrapper?.destroy(); // clears the poll timer
    wrapper = undefined;
});

describe('LiveActivityPanel', () => {
    it('polls the activity endpoint and shows step, elapsed time, message, log and cost', async () => {
        await mountPanel();

        expect(window.axios.get).toHaveBeenCalledWith('/api/runs/run-1/activity');
        expect(wrapper.text()).toContain('Test plan');
        expect(wrapper.text()).toContain('1m 15s');
        expect(wrapper.text()).toContain('Claude is writing the test plan');
        expect(wrapper.find('#live-activity-output').text()).toContain('Reading requirement');
        expect(wrapper.text()).toContain('Cost so far: $0.12');
    });

    it('shows seconds only under a minute, and a generic label for an unknown step', async () => {
        await mountPanel({}, { ...ACTIVITY, step_key: 'something_new', elapsed_seconds: 42 });

        expect(wrapper.text()).toContain('Working');
        expect(wrapper.text()).toContain('42s');
    });

    it('highlights a queued run', async () => {
        await mountPanel({}, { phase: 'queued', message: 'Waiting for a worker' });

        expect(wrapper.find('.bg-warning\\/10').text()).toBe('Waiting for a worker');
    });

    it('shows Claude output by default', async () => {
        await mountPanel();

        expect(toggle().text()).toBe('Hide output');
        expect(toggle().attributes('aria-expanded')).toBe('true');
        expect(wrapper.find('#live-activity-output').exists()).toBe(true);
    });

    it('hides only the log, keeping status, cost and Stop visible', async () => {
        await mountPanel();
        await toggle().trigger('click');

        expect(wrapper.find('#live-activity-output').exists()).toBe(false);
        expect(toggle().text()).toBe('Show output');
        expect(toggle().attributes('aria-expanded')).toBe('false');
        expect(wrapper.text()).toContain('Claude is writing the test plan');
        expect(wrapper.text()).toContain('Cost so far');
        expect(wrapper.text()).toContain('Stop');
    });

    it('remembers the choice across runs (per browser)', async () => {
        await mountPanel();
        await toggle().trigger('click');
        expect(localStorage.getItem('boschifai-live-output')).toBe('hidden');
        wrapper.destroy();

        await mountPanel({ runId: 'run-2' });

        expect(wrapper.find('#live-activity-output').exists()).toBe(false);
        await toggle().trigger('click');
        expect(localStorage.getItem('boschifai-live-output')).toBe('shown');
        expect(wrapper.find('#live-activity-output').exists()).toBe(true);
    });

    it('still toggles when browser storage is blocked', async () => {
        vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
            throw new Error('SecurityError');
        });
        vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
            throw new Error('SecurityError');
        });

        await mountPanel();
        expect(wrapper.find('#live-activity-output').exists()).toBe(true);

        await toggle().trigger('click');
        expect(wrapper.find('#live-activity-output').exists()).toBe(false);
    });

    it('asks for confirmation before stopping, and emits stop on confirm', async () => {
        await mountPanel();
        await wrapper.findAll('button').filter((b) => b.text() === 'Stop').at(0).trigger('click');

        expect(wrapper.text()).toContain('Stop this run?');

        await wrapper.findAll('button').filter((b) => b.text() === 'Stop run').at(0).trigger('click');

        expect(wrapper.emitted('stop')).toHaveLength(1);
    });

    it('disables Stop while a cancellation is pending', async () => {
        await mountPanel({ cancelRequested: true });
        const stop = wrapper.findAll('button').filter((b) => b.text() === 'Stopping…').at(0);

        expect(stop.attributes('disabled')).toBeDefined();
        expect(wrapper.text()).toContain('the current step will end shortly');
    });

    it('keeps polling after a failed request', async () => {
        vi.useFakeTimers();
        window.axios.get.mockRejectedValueOnce(new Error('Network Error')).mockResolvedValue({ data: ACTIVITY });
        wrapper = mount(LiveActivityPanel, { propsData: { runId: 'run-1' } });

        await vi.advanceTimersByTimeAsync(3000);

        expect(window.axios.get).toHaveBeenCalledTimes(2);
        expect(wrapper.text()).toContain('Claude is writing the test plan');
    });
});
