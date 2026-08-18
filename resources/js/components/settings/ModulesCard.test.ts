import { mount, type VueWrapper } from '@vue/test-utils';
import { reactive } from 'vue';
import { beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * The Settings → Modules card (Increment I5, PRD Feature #10).
 *
 * THE INVARIANT WORTH A TEST is the one that is invisible on screen when broken: `plan_granted` and
 * `enabled` are TWO facts, and the card has to answer two different questions with them — "your plan
 * doesn't include this" (upgrade) versus "your workspace switched this off" (switch it back on). Collapse
 * them into one boolean and the card still renders; it just stops being able to explain an absence.
 *
 * The second invariant is that a plan-denied row is NOT writable. The server ANDs the toggle with the plan
 * flag, so a write there could never escalate — but a live-looking switch that silently does nothing is
 * exactly the "checkbox wired to nothing" the notification card's locked-email treatment exists to avoid.
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

const ModulesCard = (await import('./ModulesCard.vue')).default;

type ModuleRow = {
    key: string;
    label: string;
    hint: string;
    plan_granted: boolean;
    enabled: boolean;
};

function moduleRow(overrides: Partial<ModuleRow> = {}): ModuleRow {
    return {
        key: 'webhooks',
        label: 'Webhooks',
        hint: 'Existing endpoints stop receiving deliveries and the page is hidden.',
        plan_granted: true,
        enabled: true,
        ...overrides,
    };
}

function render(modules: ModuleRow[] = [moduleRow()]): VueWrapper {
    return mount(ModulesCard, { props: { modules } });
}

/** The card renders one control per module — `MdsSwitch` is a real native checkbox underneath. */
function switches(wrapper: VueWrapper) {
    return wrapper.findAll('input[type="checkbox"]');
}

beforeEach(() => {
    mocks.patch.mockClear();
});

describe('rendering', () => {
    it('renders one row per module with its label and consequence hint', () => {
        const wrapper = render([moduleRow(), moduleRow({ key: 'api_access', label: 'REST API' })]);

        expect(switches(wrapper)).toHaveLength(2);
        expect(wrapper.text()).toContain('Webhooks');
        expect(wrapper.text()).toContain('Existing endpoints stop receiving deliveries');
        wrapper.unmount();
    });

    it('shows a plan-denied module as OFF, disabled, and explained', () => {
        const wrapper = render([moduleRow({ plan_granted: false, enabled: true })]);

        const control = switches(wrapper)[0]!;
        // Off even though `enabled` is true — the plan decides, and rendering it on would promise a
        // capability the server will refuse.
        expect((control.element as HTMLInputElement).checked).toBe(false);
        expect(control.attributes('disabled')).toBeDefined();
        expect(control.attributes('aria-describedby')).toBe('module-webhooks-unavailable');
        expect(wrapper.text()).toContain('Not included in your current plan');
        wrapper.unmount();
    });
});

describe('writing', () => {
    it('PATCHes one module per request, not the whole map', () => {
        const wrapper = render([moduleRow(), moduleRow({ key: 'api_access', label: 'REST API' })]);

        switches(wrapper)[1]!.setValue(false);

        expect(mocks.patch).toHaveBeenCalledTimes(1);
        expect(mocks.patch.mock.calls[0]![0]).toBe('/settings/modules');
        // A whole-map write would re-send eleven values to change one, and the audit row would then claim
        // eleven things changed.
        expect(mocks.form.module).toBe('api_access');
        expect(mocks.form.enabled).toBe(false);
        wrapper.unmount();
    });

    it('never writes for a plan-denied module', () => {
        const wrapper = render([moduleRow({ plan_granted: false })]);

        // The control is already disabled, so this cannot normally be reached — the guard is defence in
        // depth against a future refactor that re-enables the control without re-reading why it was off.
        switches(wrapper)[0]!.setValue(true);

        expect(mocks.patch).not.toHaveBeenCalled();
        wrapper.unmount();
    });
});
