import { mount, type VueWrapper } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { nextTick, reactive } from 'vue';

import { field, section } from '../../../public-runtime/__tests__/fixtures';
import type { RawField, RawSection } from '../../../public-runtime/lib/types';

/**
 * Increment H21c — the encode page's client half (Doc #27 §7 and §9).
 *
 * §9 owes two of these by name: "a test that the encode path applies client relevance (it applies none
 * today)" and "that `single_page_mode` is now read on this path". The rest pin the decisions this increment
 * locked: the keyer-only reference block, the terminal state reached on this channel, and a Next that never
 * blocks.
 *
 * WHY THIS IS NOT A FOURTH DRIVER OF `tests/fixtures/step-projection.json`. The page mounts
 * `createFormRuntime()` — the same function `steps.test.ts` already drives through all sixteen cases — so
 * re-running the fixture here would re-test one function through a thicker wrapper and call it parity. What
 * is genuinely new on this side is the JOIN: the store's step list against `blocks`, this channel's own
 * render source, and against H7's keyable-hidden rows, which the step list deliberately does not contain.
 * That join is what these cases exercise.
 *
 * The anti-vacuity caution the H21b suites earned applies here too: every absence assertion is paired with a
 * positive one, because a component that rendered nothing at all would otherwise satisfy the file.
 */

const mocks = vi.hoisted(() => ({
    pageProps: { errors: {} as Record<string, string>, flash: {} as Record<string, unknown> },
    post: vi.fn(),
}));

vi.mock('@inertiajs/vue3', () => ({
    Head: { name: 'Head', render: () => null },
    Link: { name: 'Link', template: '<a><slot /></a>' },
    router: { post: mocks.post },
    usePage: () => ({ props: mocks.pageProps }),
}));

vi.mock('@/components/shell/PageHeader.vue', () => ({
    default: { name: 'PageHeader', template: '<header><slot name="actions" /></header>' },
}));

// Imported AFTER the mocks so the component resolves them.
const Encode = (await import('./Encode.vue')).default;

interface BlockFieldOverrides {
    key: string;
    field_type?: string;
    prefill?: string | null;
    label?: string;
}

/** One `blocks[].fields[]` row — the `EncodeField` shape `EncodeFormPresenter::field()` emits. */
function blockField(o: BlockFieldOverrides): Record<string, unknown> {
    return {
        key: o.key,
        field_type: o.field_type ?? 'short_text',
        label: o.label ?? o.key,
        hint: null,
        placeholder: null,
        required: false,
        options: [],
        cascade: null,
        matrix: null,
        geo: null,
        media: null,
        upload: null,
        prefill: o.prefill ?? null,
        prefill_value: null,
        supported: true,
    };
}

interface PayloadOptions {
    sections?: RawSection[];
    fields: RawField[];
    blocks: Array<{
        key: string | null;
        label: string | null;
        repeatable?: boolean;
        min_instances?: number | null;
        max_instances?: number | null;
        fields: Array<Record<string, unknown>>;
    }>;
    singlePage?: boolean;
    now?: string;
}

/** The whole `EncodeFormPresenter::present()` payload, as the page receives it. */
function payload(o: PayloadOptions): Record<string, unknown> {
    return {
        form: {
            id: 'form-1',
            title: 'Test form',
            description: null,
            default_locale: 'en',
            supported_locales: ['en'],
            single_page_mode: o.singlePage ?? false,
            schedule: {
                opens_at: null,
                closes_at: null,
                timezone: 'UTC',
                max_responses: null,
                acceptance: 'open',
                remaining: null,
            },
        },
        version: {
            id: 'ver-1',
            version_number: 1,
            checksum: 'checksum-abc',
            schema: { sections: o.sections ?? [], fields: o.fields },
            now: o.now ?? '2026-07-11T09:30:00+00:00',
        },
        blocks: o.blocks.map((b) => ({
            id: b.key,
            key: b.key,
            label: b.label,
            description: null,
            repeatable: b.repeatable ?? false,
            min_instances: b.min_instances ?? null,
            max_instances: b.max_instances ?? null,
            fields: b.fields,
        })),
        // The server's first-paint projection. The store recomputes immediately, so these tests assert what
        // the STORE produced — this is present because the real payload carries it.
        steps: [],
    };
}

function mountEncode(props: Record<string, unknown>): VueWrapper {
    return mount(Encode, { props: props as never });
}

/** Every field label currently rendered, in DOM order — the page's answer to "what can the keyer see?". */
function visibleLabels(wrapper: VueWrapper): string[] {
    return wrapper.findAll('label').map((el) => el.text().trim());
}

async function typeInto(wrapper: VueWrapper, label: string, value: string): Promise<void> {
    const target = wrapper
        .findAll('label')
        .find((el) => el.text().trim().startsWith(label));
    expect(target, `no input labelled "${label}" is rendered`).toBeDefined();
    const id = target!.attributes('for');
    const input = wrapper.find(`#${id}`);
    await input.setValue(value);
    await nextTick();
}

/**
 * A `role` gate, an ungated opener and a gated staff branch AFTER it — Doc #27 §4.1's router, in fixture
 * form.
 *
 * The gated section sits LAST on purpose. A branch that appears BEFORE the cursor does not move it (§3.1 —
 * "a step can appear behind the respondent", and the rescue only fires when the CURRENT step vanishes), so
 * ordering it first would put every stepped assertion below on step 2 for a reason that has nothing to do
 * with what it is testing. That behaviour is the store's and is pinned by `runtime.test.ts`; this file should
 * not re-pin it by accident.
 */
function routerPayload(overrides: Partial<PayloadOptions> = {}): Record<string, unknown> {
    return payload({
        sections: [
            section({ key: 'routing', label: 'Routing', sequence: 1 }),
            section({ key: 'closing', label: 'Anything else', sequence: 2 }),
            section({ key: 'staff', label: 'Staff details', sequence: 3, relevant_expression: "${role} = 'staff'" }),
        ],
        fields: [
            field({ key: 'role', section_key: 'routing', field_type: 'hidden', sequence: 1, config: { prefill_source: 'url' } }),
            field({ key: 'comments', section_key: 'closing', label: 'Comments', sequence: 2 }),
            field({ key: 'staff_number', section_key: 'staff', label: 'Staff number', sequence: 3 }),
        ],
        blocks: [
            { key: 'routing', label: 'Routing', fields: [blockField({ key: 'role', field_type: 'hidden', prefill: 'url', label: 'Role' })] },
            { key: 'closing', label: 'Anything else', fields: [blockField({ key: 'comments', label: 'Comments' })] },
            { key: 'staff', label: 'Staff details', fields: [blockField({ key: 'staff_number', label: 'Staff number' })] },
        ],
        ...overrides,
    });
}

/**
 * The same router with NOTHING ungated — every section is either the hidden gate or a branch off it, so the
 * whole graph is empty until `role` is answered. This is the only shape that reaches §4.1's terminal state,
 * and it is the shape `E2eSeeder`'s `Branching Router` has.
 */
function terminalPayload(overrides: Partial<PayloadOptions> = {}): Record<string, unknown> {
    return payload({
        sections: [
            section({ key: 'routing', label: 'Routing', sequence: 1 }),
            section({ key: 'staff', label: 'Staff details', sequence: 2, relevant_expression: "${role} = 'staff'" }),
        ],
        fields: [
            field({ key: 'role', section_key: 'routing', field_type: 'hidden', sequence: 1, config: { prefill_source: 'url' } }),
            field({ key: 'staff_number', section_key: 'staff', label: 'Staff number', sequence: 2 }),
        ],
        blocks: [
            { key: 'routing', label: 'Routing', fields: [blockField({ key: 'role', field_type: 'hidden', prefill: 'url', label: 'Role' })] },
            { key: 'staff', label: 'Staff details', fields: [blockField({ key: 'staff_number', label: 'Staff number' })] },
        ],
        ...overrides,
    });
}

beforeEach(() => {
    mocks.pageProps.errors = {};
    mocks.pageProps.flash = {};
    mocks.post.mockReset();
});

describe('encode page — client relevance', () => {
    it('hides an irrelevant branch and reveals it when the gate is answered', async () => {
        const wrapper = mountEncode(routerPayload({ singlePage: true }));

        // The whole point of §7: before H21c the keyer saw EVERY branch. `staff` is gated off, `closing` is
        // not — the pairing is what stops a component that renders nothing from passing.
        expect(wrapper.text()).toContain('Anything else');
        expect(wrapper.text()).not.toContain('Staff details');

        await typeInto(wrapper, 'Role', 'staff');

        expect(wrapper.text()).toContain('Staff details');
        expect(visibleLabels(wrapper)).toContain('Staff number');
    });

    it('retains an answer whose branch closes behind it, and restores it intact', async () => {
        const wrapper = mountEncode(routerPayload({ singlePage: true }));

        await typeInto(wrapper, 'Role', 'staff');
        await typeInto(wrapper, 'Staff number', 'S-1234');
        expect(visibleLabels(wrapper)).toContain('Staff number');

        // Retain-and-restore (UX §4.1): the answer is NEVER deleted on hide, it is pruned out of the submit
        // set while irrelevant. A page that cleared it would lose a keyer's transcription on a typo.
        await typeInto(wrapper, 'Role', 'visitor');
        expect(visibleLabels(wrapper)).not.toContain('Staff number');

        await typeInto(wrapper, 'Role', 'staff');
        const target = wrapper.findAll('label').find((el) => el.text().trim().startsWith('Staff number'))!;
        expect((wrapper.find(`#${target.attributes('for')}`).element as HTMLInputElement).value).toBe('S-1234');
    });

    it('hides an irrelevant FIELD inside a step that stays visible', async () => {
        // The section-level case above would pass against a page that only filtered STEPS. Gating a single
        // field inside a section that never moves is the only shape that reaches `rowsOf()`'s own filter —
        // and field-level gating is the more natural authoring style, per Doc #27 §2.2's predicate 3.
        const wrapper = mountEncode(
            payload({
                singlePage: true,
                sections: [section({ key: 'intake', label: 'Intake', sequence: 1 })],
                fields: [
                    field({ key: 'employed', section_key: 'intake', label: 'Employed?', sequence: 1 }),
                    field({
                        key: 'employer',
                        section_key: 'intake',
                        label: 'Employer',
                        sequence: 2,
                        relevant_expression: "${employed} = 'yes'",
                    }),
                ],
                blocks: [
                    {
                        key: 'intake',
                        label: 'Intake',
                        fields: [blockField({ key: 'employed', label: 'Employed?' }), blockField({ key: 'employer', label: 'Employer' })],
                    },
                ],
            }),
        );
        await nextTick();

        expect(wrapper.text()).toContain('Intake');
        expect(visibleLabels(wrapper)).toEqual(['Employed?']);

        await typeInto(wrapper, 'Employed?', 'yes');
        expect(visibleLabels(wrapper)).toEqual(['Employed?', 'Employer']);
    });

    it('posts the FULL answer map, not the pruned set', async () => {
        const wrapper = mountEncode(routerPayload({ singlePage: true }));

        await typeInto(wrapper, 'Role', 'staff');
        await typeInto(wrapper, 'Staff number', 'S-1234');
        await typeInto(wrapper, 'Role', 'visitor');

        await wrapper.find('form').trigger('submit');

        // Deliberately unpruned — it is what keeps the server's own prune report meaningful as a divergence
        // alarm. The guest channel posts `effectiveAnswers`; this one does not, and the difference is a
        // decision rather than an oversight.
        const [, body] = mocks.post.mock.calls[0];
        expect(body.answers).toMatchObject({ role: 'visitor', staff_number: 'S-1234' });
    });
});

describe('encode page — single_page_mode', () => {
    it('stacks every visible step with no Next when the form is single-page', async () => {
        const wrapper = mountEncode(routerPayload({ singlePage: true }));
        await typeInto(wrapper, 'Role', 'staff');

        expect(wrapper.text()).toContain('Staff details');
        expect(wrapper.text()).toContain('Anything else');
        expect(wrapper.text()).not.toContain('Step 1 of');
        expect(wrapper.text()).not.toContain('Next');
        expect(wrapper.text()).toContain('Submit response');
    });

    it('renders one step at a time with a counter when it is not', async () => {
        const wrapper = mountEncode(routerPayload({ singlePage: false }));
        await typeInto(wrapper, 'Role', 'staff');

        // Two visible steps now (`closing`, then `staff`); `routing` never becomes one.
        expect(wrapper.text()).toContain('Step 1 of 2');
        expect(wrapper.text()).toContain('Anything else');
        expect(wrapper.text()).not.toContain('Staff details');
        expect(wrapper.text()).toContain('Next');
    });

    it('walks forward and back without blocking on an unanswered step', async () => {
        const wrapper = mountEncode(routerPayload({ singlePage: false }));
        await typeInto(wrapper, 'Role', 'staff');

        // NEXT NEVER BLOCKS on this channel — a keyer transcribing paper may not have the answer, and the
        // pipeline stays the sole validation authority (the F4b contract). `Comments` is left empty.
        await wrapper.findAll('button').find((b) => b.text().includes('Next'))!.trigger('click');
        expect(wrapper.text()).toContain('Step 2 of 2');
        expect(wrapper.text()).toContain('Staff details');
        expect(wrapper.text()).toContain('Submit response');
        expect(wrapper.text()).not.toContain('Next');

        await wrapper.findAll('button').find((b) => b.text().includes('Back'))!.trigger('click');
        expect(wrapper.text()).toContain('Step 1 of 2');
        expect(wrapper.text()).toContain('Anything else');
    });
});

describe('encode page — the keyer-only reference block', () => {
    it('renders a url-prefilled hidden field that belongs to no visible step', () => {
        const wrapper = mountEncode(routerPayload({ singlePage: true }));

        // `routing` holds nothing but a hidden field, so it can NEVER be a step — the emptiness predicate is
        // a static type test over `RENDERS_NOTHING`. Without this block the keyer could not reach the gate at
        // all, and every branch below it would stay dark forever.
        expect(wrapper.text()).toContain('Reference fields');
        expect(wrapper.text()).toContain('Not shown to the respondent');
        expect(visibleLabels(wrapper)).toContain('Role');
    });

    it('renders a hidden field INLINE when its section is a visible step', async () => {
        // The other half of H7's asymmetry: a `url` hidden field alongside ordinary questions stays where its
        // author put it. Only a field whose whole section is absent from the step list moves.
        const wrapper = mountEncode(
            payload({
                singlePage: true,
                sections: [section({ key: 'intake', label: 'Intake', sequence: 1 })],
                fields: [
                    field({ key: 'batch', section_key: 'intake', field_type: 'hidden', sequence: 1, config: { prefill_source: 'url' } }),
                    field({ key: 'full_name', section_key: 'intake', label: 'Full name', sequence: 2 }),
                ],
                blocks: [
                    {
                        key: 'intake',
                        label: 'Intake',
                        fields: [
                            blockField({ key: 'batch', field_type: 'hidden', prefill: 'url', label: 'Batch id' }),
                            blockField({ key: 'full_name', label: 'Full name' }),
                        ],
                    },
                ],
            }),
        );
        await nextTick();

        expect(wrapper.text()).not.toContain('Reference fields');
        expect(visibleLabels(wrapper)).toEqual(['Batch id', 'Full name']);
    });

    it('leaves a fixed-source hidden field out of the reference block', () => {
        // `fixed` is server-authoritative — the keyer is not its source and cannot contribute to it, so
        // offering an input would invite typing that the pipeline throws away (H7).
        const wrapper = mountEncode(
            payload({
                singlePage: true,
                sections: [section({ key: 'meta', label: 'Meta', sequence: 1 })],
                fields: [field({ key: 'origin', section_key: 'meta', field_type: 'hidden', sequence: 1, config: { prefill_source: 'fixed' } })],
                blocks: [
                    { key: 'meta', label: 'Meta', fields: [blockField({ key: 'origin', field_type: 'hidden', prefill: 'fixed', label: 'Origin' })] },
                ],
            }),
        );

        expect(wrapper.text()).not.toContain('Reference fields');
        // Paired with the positive: the page did mount and reached its terminal state, rather than crashing.
        expect(wrapper.text()).toContain('No questions to answer');
    });
});

describe('encode page — the terminal state', () => {
    it('suppresses the counter, explains itself and offers ONE labelled Submit', () => {
        const wrapper = mountEncode(terminalPayload({ singlePage: false }));

        // Bare, this router's whole graph is empty: §4.1's specified TERMINAL state, not an error. It must
        // never read "Step 1 of 0", and `isLastStep` is correctly FALSE here — which is exactly why the
        // template hangs on `isTerminal` instead.
        expect(wrapper.text()).not.toContain('Step 1 of');
        expect(wrapper.text()).not.toContain('Next');
        expect(wrapper.text()).toContain('No questions to answer');

        const submits = wrapper.findAll('button').filter((b) => b.text().includes('Submit response'));
        expect(submits).toHaveLength(1);
    });

    it('leaves the terminal state the moment the gate makes a branch relevant', async () => {
        const wrapper = mountEncode(terminalPayload({ singlePage: false }));
        expect(wrapper.text()).toContain('No questions to answer');

        await typeInto(wrapper, 'Role', 'staff');

        expect(wrapper.text()).not.toContain('No questions to answer');
        expect(wrapper.text()).toContain('Step 1 of 1');
        expect(visibleLabels(wrapper)).toContain('Staff number');
    });
});

describe('encode page — the server error summary', () => {
    it('reports form-wide and jumps to the step holding the field', async () => {
        mocks.pageProps.errors = { 'answers.staff_number': 'Staff number is required.' };
        const wrapper = mountEncode(routerPayload({ singlePage: false }));
        await typeInto(wrapper, 'Role', 'staff');

        // Doc #27 §5.5: a step-scoped banner on a multi-step form announces "0 fields need your attention"
        // while the submit is refused elsewhere. The failure is on step 2 and the reader is on step 1, so a
        // step-scoped summary would say nothing at all here.
        expect(wrapper.text()).toContain('Step 1 of 2');
        expect(wrapper.text()).toContain('1 answer needs attention');
        expect(visibleLabels(wrapper)).not.toContain('Staff number');

        await wrapper.findAll('button').find((b) => b.text().trim() === 'Staff number')!.trigger('click');
        await nextTick();

        // The jump changed step BEFORE it reached for the anchor — `getElementById` cannot resolve a field on
        // an unmounted step, which is the trap H21b's `beforeJump` prop was added for.
        expect(wrapper.text()).toContain('Step 2 of 2');
        expect(visibleLabels(wrapper)).toContain('Staff number');
    });

    it('actually focuses the field it jumped to', async () => {
        // THE REGRESSION. The first cut looked up `field-<address>` — the id the GUEST runtime's `FieldRow`
        // stamps — but this page renders `FieldInput` directly and `MdsFormField` derives its input id from
        // Vue's `useId()`, a `v-0` token with nothing to do with the field key. `getElementById` returned
        // null every time, so the link changed step and then did nothing at all; in single-page mode, where
        // there is no step to change, it was completely inert. The step-swap assertion above passed happily
        // with the anchor lookup permanently dead, which is why THIS test asserts the focus.
        //
        // `attachTo` is required: `document.getElementById` cannot see a wrapper that was never in the
        // document, so a detached mount would make this pass for the wrong reason too.
        mocks.pageProps.errors = { 'answers.comments': 'Comments is required.' };
        const wrapper = mount(Encode, {
            props: routerPayload({ singlePage: true }) as never,
            attachTo: document.body,
        });

        expect(wrapper.text()).toContain('1 answer needs attention');

        const before = document.activeElement;
        await wrapper.findAll('button').find((b) => b.text().trim() === 'Comments')!.trigger('click');
        await nextTick();

        const anchor = document.getElementById('encode-field-comments');
        expect(anchor, 'the page must stamp its own anchor — FieldInput emits no key-derived id').not.toBeNull();

        const focused = document.activeElement as HTMLElement | null;
        expect(focused).not.toBe(before);
        // Focus lands on the CONTROL, not the wrapper: the wrapper is a plain div with no tabindex, so
        // calling .focus() on it is a silent no-op.
        expect(focused?.tagName).toBe('INPUT');
        expect(anchor!.contains(focused)).toBe(true);

        wrapper.unmount();
    });

    it('says nothing when the pipeline raised nothing', async () => {
        const wrapper = mountEncode(routerPayload({ singlePage: false }));
        await typeInto(wrapper, 'Role', 'staff');

        expect(wrapper.text()).not.toContain('needs attention');
        expect(wrapper.text()).not.toContain('need attention');
        // Paired positive, per the caution: the page IS rendering a step, so the absence means something.
        expect(wrapper.text()).toContain('Anything else');
    });
});

describe('encode page — the pruned-answer report', () => {
    it('names what the server dropped after a submit that succeeded', () => {
        mocks.pageProps.flash = { prunedAnswers: ['Staff number'] };
        const wrapper = mountEncode(routerPayload({ singlePage: true }));

        expect(wrapper.text()).toContain('1 answer was not saved');
        expect(wrapper.text()).toContain('Staff number');
    });
});

describe('encode page — repeat groups', () => {
    it('seeds min_instances starter rows, unlike the guest channel', async () => {
        const wrapper = mountEncode(
            payload({
                singlePage: true,
                sections: [section({ key: 'hh', label: 'Household members', sequence: 1, is_repeatable: true, min_instances: 1, max_instances: 2 })],
                fields: [field({ key: 'member_name', section_key: 'hh', label: 'Member name', sequence: 1 })],
                blocks: [
                    {
                        key: 'hh',
                        label: 'Household members',
                        repeatable: true,
                        min_instances: 1,
                        max_instances: 2,
                        fields: [blockField({ key: 'member_name', label: 'Member name' })],
                    },
                ],
            }),
        );
        await nextTick();

        // The guest opens a repeat group EMPTY so an empty guest load is axe-clean; this channel has always
        // opened with the required rows ready to fill, and E2eSeeder records the difference by name.
        expect(wrapper.text()).toContain('Household members 1');
        expect(wrapper.text()).not.toContain('Nothing added yet.');

        await wrapper.findAll('button').find((b) => b.text().includes('Add Household members'))!.trigger('click');
        await nextTick();
        expect(wrapper.text()).toContain('Household members 2');

        // `max_instances` still bites — it is the abuse guard, and H21a deliberately left it un-narrowed.
        expect(wrapper.text()).toContain('Maximum of 2 reached.');
    });
});
