import { mount, type VueWrapper } from '@vue/test-utils';
import { reactive } from 'vue';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { NotificationPreferenceRow } from '@/components/notifications/types';

/**
 * The /settings notification-preferences card (Increment I4, data-dictionary §23).
 *
 * Two invariants, both invisible on screen when broken:
 *  · EVERY WRITE CARRIES BOTH BOOLEANS. The columns default true/true, which disagrees with
 *    `submission_received`'s email default of FALSE, so a write that sent only the changed field would
 *    silently opt the user INTO the one type the PRD says to keep quiet.
 *  · A LOCKED EMAIL CONTROL IS CHECKED, DISABLED AND EXPLAINED. `export_ready` and `webhook_failed` are
 *    emailed by their emitting job regardless of anything stored, so a live-looking switch there is a
 *    control that appears to turn something off and does not.
 */
const mocks = vi.hoisted(() => ({
    patch: vi.fn(),
    form: null as unknown as Record<string, unknown>,
}));

vi.mock('@inertiajs/vue3', () => ({
    // `reactive`, not a plain object: useForm's return is reactive in production, and a frozen stand-in
    // makes `form.recentlySuccessful` unable to ever change — the ShareModal.test.ts lesson.
    useForm: (initial: Record<string, unknown>) => {
        mocks.form = reactive({ ...initial, errors: {}, processing: false, recentlySuccessful: false, patch: mocks.patch });

        return mocks.form;
    },
}));

const NotificationPreferencesCard = (await import('./NotificationPreferencesCard.vue')).default;

function pref(overrides: Partial<NotificationPreferenceRow> = {}): NotificationPreferenceRow {
    return {
        type: 'submission_received',
        label: 'New submission',
        in_app: true,
        email: false,
        email_locked: false,
        ...overrides,
    };
}

const LOCKED = pref({ type: 'export_ready', label: 'Export ready', email: true, email_locked: true });

function render(preferences: NotificationPreferenceRow[] = [pref()]): VueWrapper {
    return mount(NotificationPreferencesCard, { props: { preferences } });
}

/** The card renders two checkboxes per type: [in app, email]. */
function checkboxes(wrapper: VueWrapper, index = 0) {
    return wrapper.findAll('fieldset')[index]!.findAll('input[type="checkbox"]');
}

beforeEach(() => {
    mocks.patch.mockClear();
});

describe('rendering', () => {
    it('renders one labelled group per type, using the SERVER’s label', () => {
        const wrapper = render([pref(), LOCKED]);

        const legends = wrapper.findAll('legend').map((node) => node.text());
        expect(legends).toEqual(['New submission', 'Export ready']);
        wrapper.unmount();
    });

    it('groups each pair in a fieldset, so “Email” is not announced seven times with no context', () => {
        const wrapper = render([pref(), LOCKED]);

        expect(wrapper.findAll('fieldset')).toHaveLength(2);
        expect(checkboxes(wrapper)).toHaveLength(2);
        wrapper.unmount();
    });

    it('renders a locked email control CHECKED and DISABLED, with the reason described', () => {
        const wrapper = render([LOCKED]);
        const email = checkboxes(wrapper)[1]!;

        expect((email.element as HTMLInputElement).checked).toBe(true);
        expect(email.attributes('disabled')).toBeDefined();

        const describedBy = email.attributes('aria-describedby');
        expect(describedBy).toBeDefined();
        expect(wrapper.get(`#${describedBy}`).text()).toContain('always email');
        wrapper.unmount();
    });

    it('leaves an unlocked email control live', () => {
        const wrapper = render();

        expect(checkboxes(wrapper)[1]!.attributes('disabled')).toBeUndefined();
        wrapper.unmount();
    });
});

describe('writing', () => {
    it('writes BOTH booleans on any single toggle', async () => {
        const wrapper = render();

        await checkboxes(wrapper)[0]!.setValue(false);

        expect(mocks.patch).toHaveBeenCalledTimes(1);
        expect(mocks.form).toMatchObject({
            notification_type: 'submission_received',
            in_app_enabled: false,
            // ← the one that matters. Left out, the column default would flip this to true and start
            // emailing a lead-gen owner once per response.
            email_enabled: false,
        });
        wrapper.unmount();
    });

    it('sends preserveScroll and preserveState, so the page neither jumps nor remounts', async () => {
        const wrapper = render();

        await checkboxes(wrapper)[1]!.setValue(true);

        const [url, options] = mocks.patch.mock.calls[0] as [string, Record<string, unknown>];
        expect(url).toBe('/settings/notifications');
        expect(options.preserveScroll).toBe(true);
        expect(options.preserveState).toBe(true);
        wrapper.unmount();
    });

    it('re-syncs from server props after the redirect', async () => {
        const wrapper = render([pref({ in_app: true })]);

        await wrapper.setProps({ preferences: [pref({ in_app: false })] });

        expect((checkboxes(wrapper)[0]!.element as HTMLInputElement).checked).toBe(false);
        wrapper.unmount();
    });

    it('reverts the local checkbox when the write is rejected', async () => {
        const wrapper = render([pref({ in_app: true })]);

        await checkboxes(wrapper)[0]!.setValue(false);
        expect((checkboxes(wrapper)[0]!.element as HTMLInputElement).checked).toBe(false);

        // A rejected write must not leave a control showing a state the server does not hold.
        const options = (mocks.patch.mock.calls[0] as [string, { onError: () => void }])[1];
        options.onError();
        await wrapper.vm.$nextTick();

        expect((checkboxes(wrapper)[0]!.element as HTMLInputElement).checked).toBe(true);
        wrapper.unmount();
    });
});
