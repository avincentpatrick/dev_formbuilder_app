import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import Switch from './Switch.vue';

/**
 * Increment I5. Most of what these assert is PARITY with `Checkbox`, and that is the point rather than
 * ceremony: I5 swaps fifteen live controls from one component to the other in a single commit, and every
 * existing locator (`input[type="checkbox"]`), every state read (`.checked`, `disabled`) and every a11y
 * wiring (`aria-describedby` on the real input) has to survive the swap untouched. If one of these ever
 * fails, the component has drifted from the contract the call sites were swapped against — fix the
 * component, not the test.
 */
describe('MdsSwitch', () => {
    it('renders a real native checkbox carrying the switch role, with the label as its accessible name', () => {
        const wrapper = mount(Switch, { props: { label: 'Maintenance mode' } });

        const input = wrapper.find('input[type="checkbox"]');
        expect(input.exists()).toBe(true);
        // role=switch reports what the control IS; the element stays a checkbox so state/locators hold.
        expect(input.attributes('role')).toBe('switch');
        expect(wrapper.find('label').text()).toContain('Maintenance mode');
        wrapper.unmount();
    });

    it('reflects modelValue and emits the new value on change', async () => {
        const wrapper = mount(Switch, { props: { modelValue: false, label: 'Webhooks' } });

        const input = wrapper.find('input');
        expect((input.element as HTMLInputElement).checked).toBe(false);

        await input.setValue(true);
        expect(wrapper.emitted('update:modelValue')).toEqual([[true]]);
        wrapper.unmount();
    });

    it('renders the checked state from the prop alone (no internal state to drift)', () => {
        const wrapper = mount(Switch, { props: { modelValue: true, label: 'Webhooks' } });

        expect((wrapper.find('input').element as HTMLInputElement).checked).toBe(true);
        wrapper.unmount();
    });

    it('supports the locked shape: checked AND disabled AND described by adjacent text', () => {
        // This is exactly how NotificationPreferencesCard renders an email channel it cannot turn off.
        const wrapper = mount(Switch, {
            props: {
                modelValue: true,
                disabled: true,
                label: 'Email',
                describedby: 'notification-pref-export_ready-locked',
            },
        });

        const input = wrapper.find('input');
        expect((input.element as HTMLInputElement).checked).toBe(true);
        expect(input.attributes('disabled')).toBeDefined();
        expect(input.attributes('aria-describedby')).toBe('notification-pref-export_ready-locked');
        expect(wrapper.find('label').classes()).toContain('mds-switch--disabled');
        wrapper.unmount();
    });

    it('passes id/name/required through to the native input', () => {
        const wrapper = mount(Switch, {
            props: { label: 'Invite only', id: 'invite-only', name: 'invite_only', required: true },
        });

        const input = wrapper.find('input');
        expect(input.attributes('id')).toBe('invite-only');
        expect(input.attributes('name')).toBe('invite_only');
        expect(input.attributes('required')).toBeDefined();
        wrapper.unmount();
    });

    it('marks the input aria-invalid only when invalid, and never emits the attribute as "false"', () => {
        const clean = mount(Switch, { props: { label: 'Invite only' } });
        expect(clean.find('input').attributes('aria-invalid')).toBeUndefined();
        clean.unmount();

        const invalid = mount(Switch, { props: { label: 'Invite only', invalid: true } });
        expect(invalid.find('input').attributes('aria-invalid')).toBe('true');
        expect(invalid.find('.mds-switch__track').classes()).toContain('mds-switch__track--invalid');
        invalid.unmount();
    });

    it('hides the track from the a11y tree — the native input carries the state', () => {
        const wrapper = mount(Switch, { props: { modelValue: true, label: 'Webhooks' } });

        expect(wrapper.find('.mds-switch__track').attributes('aria-hidden')).toBe('true');
        // Thumb position is the non-colour signifier (WCAG 1.4.1); the glyph is the redundant second cue.
        expect(wrapper.find('.mds-switch__thumb .mds-switch__check').exists()).toBe(true);
        wrapper.unmount();
    });
});
