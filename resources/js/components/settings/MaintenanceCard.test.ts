import { mount, type VueWrapper } from '@vue/test-utils';
import { reactive } from 'vue';
import { beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * The Settings → Maintenance card (Increment I5, PRD Feature #10).
 *
 * TWO invariants, both of which look like styling choices until they are broken:
 *  · THE WRITE CARRIES BOTH FIELDS. The flag and the notice are one decision — switching maintenance on
 *    while leaving the message at whatever was typed months ago takes a tenant's forms offline behind a
 *    sentence about something else. `UpdateMaintenanceSettingsRequest` makes both `required`; this pins
 *    the client half.
 *  · IT HAS A SAVE BUTTON, NOT AUTOSAVE, unlike the Access and Modules cards beside it. Stopping every
 *    respondent on every form is not a change to make on `@change`, half-way through typing the notice.
 */
const mocks = vi.hoisted(() => ({
    patch: vi.fn(),
    form: null as unknown as Record<string, unknown>,
}));

vi.mock('@inertiajs/vue3', () => ({
    useForm: (initial: Record<string, unknown>) => {
        mocks.form = reactive({ ...initial, errors: {}, processing: false, recentlySuccessful: false, patch: mocks.patch });

        return mocks.form;
    },
}));

const MaintenanceCard = (await import('./MaintenanceCard.vue')).default;

function render(maintenance: { enabled: boolean; message: string | null } = { enabled: false, message: null }): VueWrapper {
    return mount(MaintenanceCard, { props: { maintenance } });
}

beforeEach(() => {
    mocks.patch.mockClear();
});

describe('rendering', () => {
    it('shows a standing notice only while the tenant is actually paused', () => {
        const off = render();
        expect(off.text()).not.toContain('Your public forms are paused');
        off.unmount();

        const on = render({ enabled: true, message: 'Back Monday.' });
        expect(on.text()).toContain('Your public forms are paused');
        // role=status, not alert: this is a state the admin is already looking at the switch for, not an
        // event that has just interrupted them.
        expect(on.find('[role="status"]').exists()).toBe(true);
        on.unmount();
    });

    it('seeds the editor from the stored message, and from empty when there is none', () => {
        const wrapper = render({ enabled: true, message: 'Back Monday.' });
        expect((wrapper.find('textarea').element as HTMLTextAreaElement).value).toBe('Back Monday.');
        wrapper.unmount();

        const blank = render();
        expect((blank.find('textarea').element as HTMLTextAreaElement).value).toBe('');
        blank.unmount();
    });
});

describe('writing', () => {
    it('does NOT autosave when the switch is flipped', () => {
        const wrapper = render();

        wrapper.find('input[type="checkbox"]').setValue(true);

        expect(mocks.patch).not.toHaveBeenCalled();
        wrapper.unmount();
    });

    it('sends both fields together on submit', async () => {
        const wrapper = render();

        await wrapper.find('input[type="checkbox"]').setValue(true);
        await wrapper.find('textarea').setValue('Back at 09:00.');
        await wrapper.find('form').trigger('submit');

        expect(mocks.patch).toHaveBeenCalledTimes(1);
        expect(mocks.patch.mock.calls[0]![0]).toBe('/settings/maintenance');
        expect(mocks.form.maintenance_mode).toBe(true);
        expect(mocks.form.maintenance_message).toBe('Back at 09:00.');
        wrapper.unmount();
    });
});
