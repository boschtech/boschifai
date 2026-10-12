import { afterEach, describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import MultiSelectDropdown from '@/components/MultiSelectDropdown.vue';

const OPTIONS = [
    { value: 1, label: 'acme/backend' },
    { value: 2, label: 'acme/web' },
    { value: 3, label: 'acme/mobile' },
];

let wrapper;

function mountDropdown(propsData = {}) {
    wrapper = mount(MultiSelectDropdown, { propsData: { options: OPTIONS, ...propsData }, attachTo: document.body });

    return wrapper;
}

afterEach(() => {
    wrapper?.destroy();
});

describe('MultiSelectDropdown', () => {
    it('shows the placeholder when nothing is selected', () => {
        expect(mountDropdown({ placeholder: 'Select a repository…' }).text()).toContain('Select a repository…');
    });

    it('shows selected options as chips in selection order', () => {
        const chips = mountDropdown({ value: [3, 1] }).findAll('span.bg-primary\\/10');

        expect(chips.wrappers.map((c) => c.text())).toEqual(['acme/mobile', 'acme/backend']);
    });

    it('lists only unselected options when opened', async () => {
        mountDropdown({ value: [1] });
        await wrapper.find('[role="button"]').trigger('click');

        expect(wrapper.findAll('li').wrappers.map((li) => li.text())).toEqual(['acme/web', 'acme/mobile']);
    });

    it('emits the new selection when an option is picked and stays open', async () => {
        mountDropdown({ value: [1] });
        await wrapper.find('[role="button"]').trigger('click');
        await wrapper.findAll('li').at(0).trigger('click');

        expect(wrapper.emitted('input')).toEqual([[[1, 2]]]);
        expect(wrapper.find('ul').exists()).toBe(true);
    });

    it('removes a chip without opening the list', async () => {
        mountDropdown({ value: [1, 2] });
        await wrapper.find('[aria-label="Remove acme/backend"]').trigger('click');

        expect(wrapper.emitted('input')).toEqual([[[2]]]);
        expect(wrapper.find('ul').exists()).toBe(false);
    });

    it('opens from the keyboard', async () => {
        mountDropdown();
        await wrapper.find('[role="button"]').trigger('keydown.enter');

        expect(wrapper.find('ul').exists()).toBe(true);
    });

    it('closes on an outside click', async () => {
        mountDropdown();
        await wrapper.find('[role="button"]').trigger('click');
        document.body.click();
        await wrapper.vm.$nextTick();

        expect(wrapper.find('ul').exists()).toBe(false);
    });

    it('explains an empty or fully-selected list', async () => {
        mountDropdown({ options: [], emptyMessage: 'No repositories connected yet.' });
        await wrapper.find('[role="button"]').trigger('click');
        expect(wrapper.text()).toContain('No repositories connected yet.');
        wrapper.destroy();

        mountDropdown({ value: [1, 2, 3] });
        await wrapper.find('[role="button"]').trigger('click');
        expect(wrapper.text()).toContain('All options selected.');
    });
});
