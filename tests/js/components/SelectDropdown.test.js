import { afterEach, describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import SelectDropdown from '@/components/SelectDropdown.vue';

const OPTIONS = [{ value: 'member', label: 'Member' }, { value: 'admin', label: 'Admin' }];

let wrapper;

function mountDropdown(propsData = {}) {
    wrapper = mount(SelectDropdown, { propsData: { options: OPTIONS, value: 'member', ...propsData }, attachTo: document.body });
    return wrapper;
}

afterEach(() => {
    wrapper?.destroy();
    wrapper = undefined;
});

describe('SelectDropdown', () => {
    it('shows the chosen option, or the placeholder when nothing matches', async () => {
        mountDropdown();
        expect(wrapper.find('button').text()).toBe('Member');
        expect(wrapper.find('[role="listbox"]').exists()).toBe(false);

        await wrapper.setProps({ value: null, placeholder: 'Pick a role' });
        expect(wrapper.find('button').text()).toBe('Pick a role');
    });

    it('opens a list that marks the chosen option', async () => {
        mountDropdown();
        await wrapper.find('button').trigger('click');

        const options = wrapper.findAll('[role="option"]');
        expect(options.wrappers.map((o) => o.text())).toEqual(['Member', 'Admin']);
        expect(options.at(0).attributes('aria-selected')).toBe('true');
        expect(options.at(0).find('svg').exists()).toBe(true);
        expect(options.at(1).find('svg').exists()).toBe(false);
        expect(wrapper.find('button').attributes('aria-expanded')).toBe('true');
    });

    it('emits a new choice and closes', async () => {
        mountDropdown();
        await wrapper.find('button').trigger('click');
        await wrapper.findAll('[role="option"]').at(1).trigger('click');

        expect(wrapper.emitted('input')).toEqual([['admin']]);
        expect(wrapper.find('[role="listbox"]').exists()).toBe(false);
    });

    it('closes without emitting when the current option is picked again', async () => {
        mountDropdown();
        await wrapper.find('button').trigger('click');
        await wrapper.findAll('[role="option"]').at(0).trigger('click');

        expect(wrapper.emitted('input')).toBeUndefined();
        expect(wrapper.find('[role="listbox"]').exists()).toBe(false);
    });

    it('closes on Escape and on a click elsewhere, but not on a click inside', async () => {
        mountDropdown();
        await wrapper.find('button').trigger('click');
        await wrapper.trigger('keydown.esc');
        expect(wrapper.find('[role="listbox"]').exists()).toBe(false);

        await wrapper.find('button').trigger('click');
        wrapper.find('[role="listbox"]').element.dispatchEvent(new MouseEvent('click', { bubbles: true }));
        await wrapper.vm.$nextTick();
        expect(wrapper.find('[role="listbox"]').exists()).toBe(true);

        document.body.dispatchEvent(new MouseEvent('click', { bubbles: true }));
        await wrapper.vm.$nextTick();
        expect(wrapper.find('[role="listbox"]').exists()).toBe(false);
    });

    it('passes id, label and disabled through to the button', () => {
        mountDropdown({ id: 'invite-role', ariaLabel: 'Role', disabled: true });
        const button = wrapper.find('button');

        expect(button.attributes('id')).toBe('invite-role');
        expect(button.attributes('aria-label')).toBe('Role');
        expect(button.attributes('disabled')).toBeDefined();
    });
});
