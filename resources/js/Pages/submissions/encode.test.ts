import { mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
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
    pageProps: {
        errors: {} as Record<string, string>,
        flash: {} as Record<string, unknown>,
        // `formsCrumb()` (J2c) reads this to decide whether the leading crumb links to /forms.
        auth: { can: { manageForms: true } },
    },
    post: vi.fn(),
    patch: vi.fn(),
    visit: vi.fn(),
    // ⛔ M75 — `on` IS NOT OPTIONAL SCENERY. `Encode.vue` registers `router.on('before', …)` in
    // `onMounted`, so a mock without it throws a TypeError in EVERY mount in this file, not only in the
    // cases that exercise the guard. `beforeVisit` captures the handler because the `Link` stub below is
    // a bare `<a>` that never calls `router.visit()` — clicking Cancel cannot reach the guard, so the
    // registered callback is invoked directly. That is the pattern `useNotificationFeed.test.ts` uses
    // for `router.on('navigate', …)`, and for the same reason.
    on: vi.fn(),
    beforeVisit: null as null | ((event: Event) => boolean | void),
}));

vi.mock('@inertiajs/vue3', () => ({
    Head: { name: 'Head', render: () => null },
    Link: { name: 'Link', template: '<a><slot /></a>' },
    router: {
        post: mocks.post,
        patch: mocks.patch,
        visit: mocks.visit,
        on: (type: string, callback: (event: Event) => boolean | void) => {
            if (type === 'before') {
                mocks.beforeVisit = callback;
            }

            return mocks.on(type, callback);
        },
    },
    usePage: () => ({ props: mocks.pageProps }),
}));

// ⚠️ `#breadcrumbs` RENDERS TOO, AND IT DID NOT UNTIL J2c — the identical omission `submissions/show.test.ts`
// carried. J2c replaced this page's navigation with a five-crumb conditional trail and repointed two Cancel
// links, and a mock that drops the slot means not one line of that is covered. Fixing one instance and
// leaving the other is how a gap survives a review; both are fixed.
vi.mock('@/components/shell/PageHeader.vue', () => ({
    default: {
        name: 'PageHeader',
        template: '<header><slot name="breadcrumbs" /><slot name="actions" /></header>',
    },
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
    draft?: Record<string, unknown> | null;
    editing?: Record<string, unknown> | null;
    update_url?: string | null;
    draft_url?: string | null;
    cancel_url?: string | null;
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
        // The three mode props (I9b's `draft`, I9c's `editing`/`update_url`). Spelled out rather than left
        // absent, because the real payload always carries all of them and a fixture that omits a key cannot
        // catch a component that mishandles its null case. Overridable per test via `o`.
        draft: o.draft ?? null,
        editing: o.editing ?? null,
        update_url: o.update_url ?? null,
        // J2d — the trail and Cancel both come from the server now. `cancel_url` is `CrumbTrail::exitFrom()`,
        // the crumb BEFORE the tail: the submission in edit mode, the form otherwise. Spelled out here for
        // the reason the note below already gives about the mode props.
        crumbs: o.editing
            ? [
                  { label: 'Forms', href: '/forms' },
                  { label: 'Intake', href: '/forms/form-1' },
                  { label: 'Responses', href: '/forms/form-1/submissions' },
                  { label: 'Response', href: `/submissions/${o.editing.id}` },
                  { label: 'Edit answers' },
              ]
            : [
                  { label: 'Forms', href: '/forms' },
                  { label: 'Intake', href: '/forms/form-1' },
                  { label: o.draft ? 'Continue response' : 'New response' },
              ],
        cancel_url:
            o.cancel_url === undefined
                ? (o.editing ? `/submissions/${o.editing.id}` : '/forms/form-1')
                : o.cancel_url,
        draft_url: o.draft_url === undefined ? '/forms/form-1/submissions/draft' : o.draft_url,
    };
}

/**
 * ⛔ EVERY MOUNT IS TRACKED AND UNMOUNTED (M75), AND THAT IS A CORRECTNESS FIX RATHER THAN HYGIENE.
 * Nothing here unmounted before, so each case left a live component behind — and a live `Encode.vue`
 * keeps a `beforeunload` listener on the shared `window`: `useServerAutosave`'s, and since M75 the
 * edit-mode leave guard's as well. By the time a late case dispatched a `beforeunload` to ask "does
 * this page warn?", every earlier dirty create-mode mount was still answering yes, so the assertion
 * measured the file's history instead of the component. `useServerAutosave.test.ts` records the same
 * trap and the same remedy at its own head.
 */
const mountedWrappers: VueWrapper[] = [];

function mountEncode(props: Record<string, unknown>): VueWrapper {
    const wrapper = mount(Encode, { props: props as never });
    mountedWrappers.push(wrapper);

    return wrapper;
}

afterEach(() => {
    // ⛔ `fetch` IS STUBBED FOR THE TEARDOWN ITSELF, AND THE FIRST DRAFT OF THIS DID NOT DO IT.
    // Unmounting a dirty create-mode page runs `dispose()`, which fires the last-chance keepalive POST —
    // and by then the case's own `vi.unstubAllGlobals()` has already put the REAL `fetch` back, so the
    // request is still in flight when happy-dom tears the window down and prints
    // `DOMException [AbortError]` on a run that exits 0. A stack trace on a PASSING run is the thing
    // that teaches a reader to skip stack traces.
    // ⚠️ MEASURED, BECAUSE THE ATTRIBUTION IS NOT OBVIOUS: with the unmount loop disabled this file still
    // printed TWO of those traces, so two predate M75 and the unmount added two more. This stub removes
    // all four. ⚠️ `globalThis.fetch = …` does NOT work here — it was tried first and changed nothing;
    // `vi.stubGlobal` is what actually reaches the binding the composable calls.
    vi.stubGlobal(
        'fetch',
        vi.fn(() => Promise.resolve({ ok: true, status: 204, json: () => Promise.resolve({}) })),
    );

    try {
        while (mountedWrappers.length > 0) {
            mountedWrappers.pop()?.unmount();
        }
    } finally {
        vi.unstubAllGlobals();
    }
});

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

    // ⛔ `fetch` IS STUBBED FOR THE WHOLE CASE, NOT ONLY FOR THE TEARDOWN — WHICH IS WHERE M75 PUT IT AND
    // WHY THE TRACES SURVIVED IT. M75 read the escaping requests as an artefact of the window being torn
    // down while a `dispose()` keepalive was in flight, and stubbed `afterEach` accordingly. That is real
    // and it is not all of them: the autosave composable ALSO flushes on a step change, un-debounced and
    // mid-case, long before any teardown — so those requests met the real `fetch` and left the process.
    // ⚠️ MEASURED RATHER THAN INFERRED, AND THE ROW'S OWN NUMBER IS WRONG. The invariant is TEN escaped
    // requests, not the six the backlog row counted, and what they PRINT is not stable: run alone this
    // file emitted ten `AggregateError`/`ECONNREFUSED` traces and zero `AbortError`, because nothing was
    // tearing a window down in time to abort them first. In a full 134-file run the teardown wins some of
    // those races and the same requests surface as `DOMException [AbortError]`. Counting the SYMPTOM is
    // what made this look like six flaky traces instead of ten deterministic escapes.
    // ⚠️ `globalThis.fetch = …` does NOT reach the binding under happy-dom — it was tried first and changed
    // nothing. `vi.stubGlobal` is what works, and the `afterEach` stub is still needed as well, because
    // that hook's own `vi.unstubAllGlobals()` restores the real `fetch` before the unmount runs.
    vi.stubGlobal(
        'fetch',
        vi.fn(() => Promise.resolve({ ok: true, status: 204, json: () => Promise.resolve({}) })),
    );
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

/**
 * Increment I9c — EDIT mode.
 *
 * The page now serves three modes off one presenter payload, and the ways they can be confused are the point
 * of this block: an edit that autosaves down the draft channel writes with no policy check and no audit row;
 * an edit that POSTs the encode endpoint creates a SECOND submission instead of correcting the first; an
 * edit blocked by the schedule makes every historical response on a finished survey permanently
 * uncorrectable.
 */
describe('Encode.vue — edit mode (I9c)', () => {
    /** The create/resume payload above, re-pointed at a finalized submission. */
    function editPayload(o: { status?: string; demotes?: boolean; acceptance?: string } = {}): Record<string, unknown> {
        const base = payload({
            fields: [field({ key: 'comments', label: 'Comments', sequence: 1 })],
            blocks: [{ key: null, label: null, fields: [blockField({ key: 'comments', label: 'Comments' })] }],
            singlePage: true,
            editing: {
                id: 'sub-1',
                answers: { comments: 'Original' },
                status: o.status ?? 'submitted',
                baseline: 'checksum-baseline-1',
                demotes_on_save: o.demotes ?? false,
            },
            update_url: '/submissions/sub-1/answers',
            draft_url: null,
        });

        if (o.acceptance !== undefined) {
            (base.form as Record<string, unknown>).schedule = {
                opens_at: null,
                closes_at: '2020-01-01T00:00:00+00:00',
                timezone: 'UTC',
                max_responses: null,
                acceptance: o.acceptance,
                remaining: null,
            };
        }

        return base;
    }

    /** The same single-field form in CREATE mode — the control for every edit-only assertion below. */
    function createPayload(acceptance?: string): Record<string, unknown> {
        const base = payload({
            fields: [field({ key: 'comments', label: 'Comments', sequence: 1 })],
            blocks: [{ key: null, label: null, fields: [blockField({ key: 'comments', label: 'Comments' })] }],
            singlePage: true,
        });

        if (acceptance !== undefined) {
            (base.form as Record<string, unknown>).schedule = {
                opens_at: null,
                closes_at: '2020-01-01T00:00:00+00:00',
                timezone: 'UTC',
                max_responses: null,
                acceptance,
                remaining: null,
            };
        }

        return base;
    }

    beforeEach(() => {
        mocks.post.mockClear();
        mocks.patch.mockClear();
        mocks.visit.mockClear();
        mocks.beforeVisit = null;
    });

    /* ── The unsaved-corrections guard (M75) ──────────────────────────────────────────────────── */

    /** A cancelable `before` payload in the shape `Encode.vue` reads off the event. */
    function beforeEvent(visit: Record<string, unknown> = {}): CustomEvent {
        return new CustomEvent('inertia:before', {
            cancelable: true,
            detail: {
                visit: { url: new URL('http://localhost/submissions'), method: 'get', ...visit },
            },
        });
    }

    /** Drive the handler the page gave `router.on('before', …)`. True when it CANCELLED the visit. */
    function visitWasBlocked(visit: Record<string, unknown> = {}): boolean {
        expect(mocks.beforeVisit, 'the page registered no `before` handler').not.toBeNull();

        return mocks.beforeVisit!(beforeEvent(visit)) === false;
    }

    /** Dispatch a real cancelable `beforeunload` and report whether anything asked to stop the exit. */
    function unloadIsWarned(): boolean {
        const event = new Event('beforeunload', { cancelable: true });
        window.dispatchEvent(event);

        return event.defaultPrevented;
    }

    it('warns and cancels an Inertia navigation once corrections have been typed, and not before', async () => {
        // ⛔ THE ROW'S DEFECT, AT THE THREE ESCAPE ROUTES IT NAMES. `beforeunload` never fires for an
        // Inertia visit, so Cancel, a breadcrumb and every sidebar item discarded the whole correction
        // with no prompt and no trace — while M74's own "discard my changes" button was two-click
        // confirmed. The destructive path was guarded and the accidental one was free.
        const wrapper = mountEncode(editPayload());

        // ⚠️ THE NON-VACUITY PARTNER, AND IT IS NOT DECORATION: a guard that blocked EVERY visit would
        // satisfy the assertion below on its own.
        expect(visitWasBlocked()).toBe(false);
        expect(wrapper.text()).not.toContain('You have unsaved corrections');

        await typeInto(wrapper, 'Comments', 'Corrected text');

        expect(visitWasBlocked()).toBe(true);
        await nextTick();
        expect(wrapper.text()).toContain('You have unsaved corrections');
    });

    it('does NOT warn on a non-GET visit — the dark-mode toggle is on screen the whole time', async () => {
        // ⛔ MEASURED, NOT IMAGINED. `ThemeQuickToggle` renders unconditionally in `TopNav`, `TopNav` in
        // `AppLayout`, and `app.ts` gives this page `AppLayout` — so the theme switch is on screen during
        // every correction, and it persists through `router.patch('/settings/appearance', …)`, a visit
        // that never leaves the page. A guard without this clause pops "you have unsaved corrections"
        // when a keyer switches to dark mode, and declining it silently drops the preference, which has
        // no error path at all. `submitEdit()`'s own PATCH is excluded by the same clause.
        const wrapper = mountEncode(editPayload());
        await typeInto(wrapper, 'Comments', 'Corrected text');

        expect(visitWasBlocked({ method: 'patch' })).toBe(false);
        expect(visitWasBlocked({ method: 'PATCH' })).toBe(false);
        // The partner: the same page, the same dirty state, a GET — so this cannot be passing because
        // the guard is simply off.
        expect(visitWasBlocked()).toBe(true);
    });

    it('does NOT warn on a prefetch, which fires the same cancelable event as a navigation', async () => {
        // ⛔ A TRIPWIRE THAT WOULD HAVE SAT ARMED AND UNNOTICED. `Router.prefetch()` fires `before`
        // exactly as `Router.visit()` does, so without the `prefetch` clause the first `<Link prefetch>`
        // anywhere in the shell raises this dialog on HOVER. Nothing passes `prefetch` today — which is
        // precisely why nothing would have caught it.
        const wrapper = mountEncode(editPayload());
        await typeInto(wrapper, 'Comments', 'Corrected text');

        expect(visitWasBlocked({ prefetch: true })).toBe(false);
        expect(visitWasBlocked()).toBe(true);
    });

    it('re-issues the cancelled visit when the keyer chooses to leave, rather than asking for a second click', async () => {
        const wrapper = mountEncode(editPayload());
        await typeInto(wrapper, 'Comments', 'Corrected text');
        expect(visitWasBlocked()).toBe(true);
        await nextTick();

        const leave = wrapper.findAll('button').find((el) => el.text().includes('Leave and discard'));
        expect(leave, 'no "Leave and discard" control is rendered').toBeDefined();
        await leave!.trigger('click');

        expect(mocks.visit).toHaveBeenCalledTimes(1);
        expect(String(mocks.visit.mock.calls[0][0])).toBe('http://localhost/submissions');
        // ⛔ AND THE GUARD MUST STAND DOWN FOR THAT RE-ISSUE, or the page cancels its own navigation and
        // the keyer is trapped on it — the failure mode a leave guard is most likely to ship with.
        expect(visitWasBlocked()).toBe(false);
    });

    it('stays put on the safe choice, and leaves the cancelled visit cancelled', async () => {
        const wrapper = mountEncode(editPayload());
        await typeInto(wrapper, 'Comments', 'Corrected text');
        expect(visitWasBlocked()).toBe(true);
        await nextTick();

        const stay = wrapper.findAll('button').find((el) => el.text().includes('Stay on this page'));
        expect(stay, 'no "Stay on this page" control is rendered').toBeDefined();
        await stay!.trigger('click');

        expect(mocks.visit).not.toHaveBeenCalled();
        expect(wrapper.text()).not.toContain('You have unsaved corrections');
        // Still armed: staying is not a decision to stop being warned.
        expect(visitWasBlocked()).toBe(true);
    });

    it('warns on a real browser navigation too, and stops warning once the page is torn down', async () => {
        const wrapper = mountEncode(editPayload());

        expect(unloadIsWarned()).toBe(false);
        await typeInto(wrapper, 'Comments', 'Corrected text');
        expect(unloadIsWarned()).toBe(true);

        // ⛔ THE LEAK HALF, AND IT IS A MEASURED CLASS IN THIS CODEBASE — `useMemberStreak` records that
        // an un-unsubscribed `router.on` turned one navigation into forty requests. A leaked `before`
        // handler is worse than a leaked fetch, because a dead copy can still cancel a live visit.
        wrapper.unmount();
        mountedWrappers.pop();

        expect(unloadIsWarned()).toBe(false);
    });

    it('does not put a native prompt on top of the two-click discard confirm', async () => {
        // ⚠️ `confirmDiscard()` reloads the page, which IS a real browser navigation — so arming the
        // leave guard without disarming it here asks the keyer twice for one decision, the second time
        // in a browser dialog this page cannot word. `location` is replaced so the reload is observable
        // rather than fatal.
        const reloads: number[] = [];
        const original = window.location;
        Object.defineProperty(window, 'location', { configurable: true, value: { reload: () => reloads.push(1) } });

        try {
            mocks.pageProps.errors = { baseline: 'This response was changed somewhere else.' };
            const wrapper = mountEncode(editPayload());
            await typeInto(wrapper, 'Comments', 'Corrected text');
            expect(unloadIsWarned()).toBe(true);

            const arm = wrapper.findAll('button').find((el) => el.text().includes('Discard my changes and reload'));
            expect(arm, 'no discard control is rendered').toBeDefined();
            await arm!.trigger('click');
            const confirm = wrapper.findAll('button').find((el) => el.text().includes('Discard and reload'));
            expect(confirm, 'no discard confirm is rendered').toBeDefined();
            await confirm!.trigger('click');

            expect(reloads).toHaveLength(1);
            expect(unloadIsWarned()).toBe(false);
        } finally {
            Object.defineProperty(window, 'location', { configurable: true, value: original });
        }
    });

    it('never warns in CREATE mode, where leaving the page SAVES rather than discards', async () => {
        // ⛔ THE ASYMMETRY IS THE DESIGN. On the draft channel `dispose()` fires a last-chance keepalive
        // when the component unmounts, so clicking Cancel persists the work — a prompt there would be a
        // lie. This is also the control that stops the guard being "warn on everything".
        const wrapper = mountEncode(createPayload());
        await typeInto(wrapper, 'Comments', 'Something typed');

        expect(visitWasBlocked()).toBe(false);
        expect(wrapper.text()).not.toContain('You have unsaved corrections');
    });

    it('hydrates the answers from `editing`, not from `draft`', () => {
        const wrapper = mountEncode(editPayload());
        const id = wrapper.findAll('label')[0].attributes('for');

        expect((wrapper.find(`#${id}`).element as HTMLInputElement).value).toBe('Original');
    });

    it('PATCHes the submission instead of POSTing a new one', async () => {
        const wrapper = mountEncode(editPayload());
        await wrapper.find('form').trigger('submit');

        // The encode POST would CREATE a second submission rather than correct this one.
        expect(mocks.post).not.toHaveBeenCalled();
        expect(mocks.patch).toHaveBeenCalledTimes(1);
        expect(mocks.patch.mock.calls[0][0]).toBe('/submissions/sub-1/answers');
    });

    it('sends the answers and the baseline, and no client_submission_uuid', async () => {
        const wrapper = mountEncode(editPayload());
        await wrapper.find('form').trigger('submit');

        const body = mocks.patch.mock.calls[0][1] as Record<string, unknown>;
        // No `client_submission_uuid`: the URL already identifies the row, and a second caller-chosen
        // identifier is the two-independent-inputs shape that produced I9b's cross-form draft hole.
        expect(Object.keys(body).sort()).toEqual(['answers', 'baseline']);
        // The concurrency token has to actually travel, or the server's guard has nothing to compare.
        expect(body.baseline).toBe('checksum-baseline-1');
        expect((body.answers as Record<string, unknown>).comments).toBe('Original');
    });

    it('labels the primary action Save changes, not Submit response', () => {
        expect(mountEncode(editPayload()).find('button[type="submit"]').text()).toContain('Save changes');
    });

    it('warns BEFORE typing that saving an approved response withdraws the approval', () => {
        const banner = mountEncode(editPayload({ status: 'approved', demotes: true })).find('.encode__editing');

        expect(banner.exists()).toBe(true);
        expect(banner.classes()).toContain('encode__editing--warning');
        // ⚠️ `status`, NOT `alert`, even here. The banner is present at first paint and never changes, and
        // this page's own autosave note records why that matters: a live region inserted with its content
        // already in it is not reliably announced, and `alert` interrupts on the readers that do announce it.
        // The warning is carried by the heading text and the accent.
        expect(banner.attributes('role')).toBe('status');
        expect(banner.text()).toContain('withdraws the approval');
        // The copy must say `under review`, not "the review queue": the service sets `under_review`, so a
        // reviewer filtering the inbox on Submitted will NOT see it come back.
        expect(banner.text()).toContain('under review');
    });

    it('states the plainer message when there is no approval to withdraw', () => {
        // The anti-vacuity half: a banner that always screamed would teach an editor to ignore it.
        const banner = mountEncode(editPayload()).find('.encode__editing');

        expect(banner.attributes('role')).toBe('status');
        expect(banner.classes()).not.toContain('encode__editing--warning');
        expect(banner.text()).toContain('audit log');
    });

    it('keeps Save enabled on a CLOSED form, where a new submission would be blocked', () => {
        // An edit consumes no capacity and creates no response, so assertCanStart/assertCapacity never run on
        // this path. Blocking here would make every historical response on a finished survey permanently
        // uncorrectable — the population most likely to need a correction.
        expect(mountEncode(editPayload({ acceptance: 'closed' })).find('button[type="submit"]').attributes('disabled'))
            .toBeUndefined();

        // The control: the same acceptance in CREATE mode still disables Submit.
        expect(mountEncode(createPayload('closed')).find('button[type="submit"]').attributes('disabled'))
            .toBeDefined();
    });

    it('suppresses the schedule banner in edit mode, and only in edit mode', () => {
        // "This form is full" over a working Save button is how a banner teaches someone to distrust the
        // banners. The paired create-mode assertion is what proves the suppression is mode-specific rather
        // than the banner simply being broken.
        expect(mountEncode(editPayload({ acceptance: 'capacity_reached' })).find('.encode__schedule-banner').exists())
            .toBe(false);
        expect(mountEncode(createPayload('capacity_reached')).find('.encode__schedule-banner').exists())
            .toBe(true);
    });

    it('renders no edit banner at all in create mode', () => {
        const wrapper = mountEncode(createPayload());

        expect(wrapper.find('.encode__editing').exists()).toBe(false);
        expect(wrapper.find('button[type="submit"]').text()).toContain('Submit response');
    });

    it('renders a media answer read-only, with no file input', () => {
        const wrapper = mountEncode(
            payload({
                fields: [field({ key: 'photo', label: 'Photo', field_type: 'file_upload', sequence: 1 })],
                blocks: [
                    {
                        key: null,
                        label: null,
                        fields: [blockField({ key: 'photo', field_type: 'file_upload', label: 'Photo' })],
                    },
                ],
                singlePage: true,
                editing: {
                    id: 'sub-1',
                    answers: { photo: [{ id: 'att-1', name: 'scan.png' }] },
                    status: 'submitted',
                    baseline: 'checksum-baseline-1',
                    demotes_on_save: false,
                },
                update_url: '/submissions/sub-1/answers',
                draft_url: null,
            }),
        );

        const readOnly = wrapper.find('.encode-readonly-media');
        expect(readOnly.exists()).toBe(true);
        // Names the file rather than showing a bare count — an editor is checking it against paper.
        expect(readOnly.text()).toContain('scan.png');
        expect(wrapper.find('input[type="file"]').exists()).toBe(false);
    });

    /*
     * Increment M62 — a refused correction must keep the corrections, WITHOUT re-arming the guard.
     *
     * Two halves that only work together, and shipping either alone is worse than shipping neither.
     */

    it('keeps the page mounted when the refusal carries an errors bag, and re-keys it when it does not', async () => {
        // The predicate is unchanged; what changed is that the CONCURRENCY refusal now arrives with an errors
        // bag, where it used to arrive as a toast alone. With an empty bag Inertia re-keys the component and
        // the editor's typed corrections are replaced by the stored document — silently.
        const wrapper = mountEncode(editPayload());
        await wrapper.find('form').trigger('submit');

        const options = mocks.patch.mock.calls[0][2] as { preserveState: (page: unknown) => boolean };

        expect(options.preserveState({ props: { errors: { baseline: 'Changed somewhere else.' } } })).toBe(true);
        // The control. A success carries no errors and redirects away, so nothing needs preserving there —
        // without this the assertion above would pass against a predicate that simply returned true.
        expect(options.preserveState({ props: { errors: {} } })).toBe(false);
    });

    it('holds the RENDER-TIME baseline across a refusal, so a preserved page cannot overwrite the other editor', async () => {
        const wrapper = mountEncode(editPayload());
        await wrapper.find('form').trigger('submit');

        expect((mocks.patch.mock.calls[0][1] as Record<string, unknown>).baseline).toBe('checksum-baseline-1');

        // ⛔ THE REFUSAL, AS INERTIA REALLY DELIVERS IT. `back()` re-issues the GET, and `preserveState` gates
        // only the component re-key — `page.props` is replaced either way. `EncodeFormPresenter` recomputes
        // `editing.baseline` from the CURRENT stored row, so the preserved page is handed the checksum of the
        // very change it was just refused against.
        await wrapper.setProps({
            editing: {
                id: 'sub-1',
                answers: { comments: 'Original' },
                status: 'submitted',
                baseline: 'checksum-baseline-2',
                demotes_on_save: false,
            },
        });

        await wrapper.find('form').trigger('submit');

        // Reading the prop here would send `checksum-baseline-2`, the server would accept it, and the other
        // editor's correction would be destroyed with no error anywhere — a visible refusal turned into a
        // silent lost update. The snapshot means the second Save is refused too, which is the point.
        expect((mocks.patch.mock.calls[1][1] as Record<string, unknown>).baseline).toBe('checksum-baseline-1');
    });

    /*
     * Increment M74 — a refused correction now has a way OUT.
     *
     * M62 kept the corrections and kept the guard armed, which is right and is argued at `editBaseline`.
     * What it left was a page that can never be saved and no route forward but the browser's own reload.
     *
     * ⚠️ THE ROW FILED AGAINST IT SAYS THE TOAST FADES. IT DOES NOT — `useToast` auto-dismisses only
     * `type !== 'error'`. The real defect is that the notice is DISMISSIBLE and that the errors bag is
     * keyed `baseline`, a key no field renders. Both halves are asserted below.
     */

    it('renders a NON-dismissible conflict alert carrying the server sentence', async () => {
        mocks.pageProps.errors = { baseline: 'This response was changed by someone else.' };
        const wrapper = mountEncode(editPayload());
        await nextTick();

        // ⛔ THE BEHAVIOURAL HALF. Asserting only that an alert exists is satisfied by a hard-coded
        // string, and the whole point is that the page carries the SERVER's reason.
        expect(wrapper.text()).toContain('This response was changed by someone else.');

        // The structural half, and it is the row's actual complaint: a dismiss control on a condition
        // that is still true lets one click restore a page that looks normal and can never be saved.
        // MdsAlert renders its dismiss as a button with an aria-label, so its ABSENCE is checkable.
        expect(wrapper.find('[aria-label="Dismiss"]').exists()).toBe(false);
        expect(wrapper.find('[role="alert"]').exists()).toBe(true);
    });

    it('renders nothing of the sort when there is no baseline refusal', async () => {
        // ⛔ THE REFUSES-NOTHING PARTNER. Every assertion above is satisfied by a component that renders
        // the alert unconditionally; only this catches it.
        mocks.pageProps.errors = {};
        const wrapper = mountEncode(editPayload());
        await nextTick();

        expect(wrapper.text()).not.toContain('Discard my changes and reload');
    });

    it('requires TWO clicks to discard, and moves focus to the safe control in between', async () => {
        mocks.pageProps.errors = { baseline: 'Changed somewhere else.' };
        // ⚠️ ATTACHED, AND THAT IS PART OF THE ASSERTION RATHER THAN A DETAIL. `mount()` renders
        // detached by default, and `focus()` on an off-document element does not move
        // `document.activeElement` — so the focus case fails for a reason that has nothing to do with
        // the component. Measured: it failed exactly that way on first run.
        const wrapper = mount(Encode, { props: editPayload() as never, attachTo: document.body });
        await nextTick();

        const original = window.location;
        let reloads = 0;
        // Replace ONLY `window.location`, never the whole window: stubbing the global tears out the DOM
        // event constructors test-utils needs to dispatch a click (the precedent is audit/index.test.ts).
        Object.defineProperty(window, 'location', {
            configurable: true,
            value: { reload: () => { reloads += 1; } },
        });

        try {
            const arm = wrapper.findAll('button').find((b) => b.text().includes('Discard my changes'));
            await arm!.trigger('click');

            // One click arms; it does not act. A single-step destructive control on a page holding a
            // page of typed corrections is the failure this two-step exists to prevent.
            expect(reloads).toBe(0);

            // ⚠️ ASSERTED ON `document.activeElement`, NEVER ON AN EMITTED EVENT. SubmissionOutbox.vue
            // records that the template-ref version of this focus move was DEAD CODE and its suite could
            // not tell, because the assertion was on the event rather than on where focus actually went.
            await nextTick();
            expect(document.activeElement?.getAttribute('data-conflict-keep')).not.toBeNull();

            const confirm = wrapper.findAll('button').find((b) => b.text().includes('Discard and reload'));
            await confirm!.trigger('click');

            expect(reloads).toBe(1);

            // ⛔ AND THE ROW'S ⛔ CONSTRAINT, AS FAR AS A GATE CAN CARRY IT. This must never become a
            // one-click adopt-the-new-baseline, which means it must be a real browser navigation and not
            // an Inertia visit that re-keys the component and re-seeds `editBaseline` from fresh props.
            // happy-dom cannot distinguish a destroyed JS context from a re-key, so no case can prove the
            // constraint directly; asserting that the router was NOT used is the enforceable proxy, and
            // it is a proxy rather than the thing itself.
            expect(mocks.patch).not.toHaveBeenCalled();
        } finally {
            Object.defineProperty(window, 'location', { configurable: true, value: original });
            wrapper.unmount();
        }
    });

    it('cancels the armed discard on Escape, and on the Keep control', async () => {
        mocks.pageProps.errors = { baseline: 'Changed somewhere else.' };
        const wrapper = mountEncode(editPayload());
        await nextTick();

        const arm = () => wrapper.findAll('button').find((b) => b.text().includes('Discard my changes'));

        await arm()!.trigger('click');
        expect(wrapper.text()).toContain('Discard and reload');

        await wrapper.find('[data-conflict-keep]').trigger('keydown', { key: 'Escape' });
        expect(wrapper.text()).not.toContain('Discard and reload');
        // Re-armable afterwards: a cancel that also destroyed the escape route would be its own trap.
        expect(arm()).toBeTruthy();

        await arm()!.trigger('click');
        await wrapper.find('[data-conflict-keep]').trigger('click');
        expect(wrapper.text()).not.toContain('Discard and reload');
    });
});

/*
 * Increment M68 — Submit must not race the draft channel it is about to supersede.
 *
 * `submit()` called `autosave.dispose()`, which fires a last-chance keepalive carrying
 * `autosave.baseline.value`, and then posted the promote carrying the SAME value. Two writers, one
 * checksum: they serialize on `updateDraft()`'s `lockForUpdate`, the winner advances it, and the loser is
 * refused `draftConcurrentlyModified()`. When the loser is the Submit, the keyer is told their draft "was
 * changed somewhere else" on a page with no somewhere else.
 *
 * ⚠️ THESE TWO CASES ARE A PAIR AND NEITHER IS EVIDENCE ALONE. "No draft write happened" is equally
 * consistent with the fix working and with this page never having armed its autosave — so the second case
 * leaves the page in the identical state and unmounts instead, where the write is still owed and must
 * still happen. The composable-level proof lives in `useServerAutosave.test.ts`; this pins that
 * `Encode.vue` is actually wired to it.
 */
describe('encode page — Submit does not race its own draft channel (M68)', () => {
    /** The keepalive goes out through global `fetch`, not through Inertia — so that is what to watch. */
    function watchKeepalive() {
        const fetchSpy = vi.fn(async () => new Response('{"data":{}}', { status: 200 }));
        vi.stubGlobal('fetch', fetchSpy);

        return {
            fetchSpy,
            draftWrites: () =>
                fetchSpy.mock.calls.filter(([url]) => String(url).includes('/submissions/draft')),
        };
    }

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('sends the promote and NO draft write', async () => {
        const { draftWrites } = watchKeepalive();
        const wrapper = mountEncode(routerPayload({ singlePage: true }));

        await typeInto(wrapper, 'Role', 'staff');
        await wrapper.find('form').trigger('submit');

        expect(mocks.post).toHaveBeenCalledTimes(1);

        // ⛔ THE DEFECT, PINNED AT THE PAGE. Before M68 this was 1 — the keepalive — carrying the same
        // `base_content_checksum` as the promote beside it.
        expect(draftWrites()).toHaveLength(0);
    });

    it('still writes the draft when the page is merely LEFT — the discriminator', async () => {
        // Identical setup, and the write is still owed here: an Inertia navigation away from a dirty page
        // has nobody else to persist what was typed, which is the whole reason `dispose()` writes at all.
        // If this went to zero as well, the case above would be passing because nothing was ever armed.
        const { draftWrites } = watchKeepalive();
        const wrapper = mountEncode(routerPayload({ singlePage: true }));

        await typeInto(wrapper, 'Role', 'staff');
        wrapper.unmount();
        await nextTick();

        expect(draftWrites()).toHaveLength(1);
    });
});
