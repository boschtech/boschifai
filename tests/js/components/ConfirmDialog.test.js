import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import ConfirmDialog from '@/components/ConfirmDialog.vue';

describe('ConfirmDialog', () => {
    it('renders nothing while closed', () => {
        expect(mount(ConfirmDialog, { propsData: { open: false } }).text()).toBe('');
    });

    it('shows the title, message and custom labels', () => {
        const wrapper = mount(ConfirmDialog, {
            propsData: { open: true, title: 'Delete run?', message: 'Gone for good.', confirmLabel: 'Delete', cancelLabel: 'Keep' },
        });

        expect(wrapper.text()).toContain('Delete run?');
        expect(wrapper.text()).toContain('Gone for good.');
        expect(wrapper.findAll('button').at(0).text()).toBe('Keep');
        expect(wrapper.findAll('button').at(1).text()).toBe('Delete');
    });

    it('uses the danger style by default and the primary style otherwise', () => {
        expect(mount(ConfirmDialog, { propsData: { open: true } }).findAll('button').at(1).classes()).toContain('bg-danger');
        expect(mount(ConfirmDialog, { propsData: { open: true, danger: false } }).findAll('button').at(1).classes()).toContain('bg-primary');
    });

    it('emits confirm and cancel', async () => {
        const wrapper = mount(ConfirmDialog, { propsData: { open: true } });

        await wrapper.findAll('button').at(1).trigger('click');
        await wrapper.findAll('button').at(0).trigger('click');

        expect(wrapper.emitted('confirm')).toHaveLength(1);
        expect(wrapper.emitted('cancel')).toHaveLength(1);
    });

    it('cancels when the backdrop is clicked, but not the dialog itself', async () => {
        const wrapper = mount(ConfirmDialog, { propsData: { open: true } });

        await wrapper.find('h2').trigger('click');
        expect(wrapper.emitted('cancel')).toBeUndefined();

        await wrapper.find('.fixed').trigger('click');
        expect(wrapper.emitted('cancel')).toHaveLength(1);
    });
});
