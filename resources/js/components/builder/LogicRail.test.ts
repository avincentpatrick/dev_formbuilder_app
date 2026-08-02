import { flushPromises, mount } from '@vue/test-utils';
import { computed, ref } from 'vue';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import LogicRail from './LogicRail.vue';
import type { BuilderStore } from './useBuilderStore';
import type { CanvasGroup, LocalField, LocalSection, Selection } from './types';

/*
 * Increment H21d1 — the Logic view through the rendered DOM.
 *
 * `logic-rail.test.ts` pins the projection; this pins the things a pure function cannot fail at: what the
 * author actually reads, whether selecting a node reaches the config panel, and — the half most likely to
 * rot — whether the server-derived notices are fetched at the right moment and, when the fetch fails, whether
 * the page says so instead of quietly looking clean.
 *
 * The store is a hand-built double rather than a real `useBuilderStore`: the real one owns fetch, undo/redo
 * and a debounced persist queue, none of which this component touches, and mounting it would make every test
 * here a test of that instead.
 */

let uid = 0;

function section(key: string, overrides: Partial<LocalSection> = {}): LocalSection {
    return {
        id: `sec-${key}`,
        uid: `s${++uid}`,
        key,
        label: key.charAt(0).toUpperCase() + key.slice(1),
        description: null,
        is_repeatable: false,
        min_instances: null,
        max_instances: null,
        relevant_expression: null,
        sequence: 0,
        version: null,
        ...overrides,
    };
}

function field(key: string, overrides: Partial<LocalField> = {}): LocalField {
    return {
        id: `fld-${key}`,
        uid: `f${++uid}`,
        form_section_id: null,
        key,
        field_type: 'short_text',
        label: key.charAt(0).toUpperCase() + key.slice(1),
        hint: null,
        placeholder: null,
        is_required: 'optional',
        relevant_expression: null,
        appearance: null,
        config: {},
        default_value: null,
        is_pii: false,
        is_sensitive: false,
        is_queryable: false,
        indexed_data_type: null,
        sequence: 0,
        section_sequence: null,
        version: null,
        validations: [],
        ...overrides,
    };
}

interface Double {
    store: BuilderStore;
    selected: () => Selection;
    idleCalls: () => number;
}

function makeStore(groups: CanvasGroup[]): Double {
    const selection = ref<Selection>(null);
    const sections = groups.map((g) => g.section).filter((s): s is LocalSection => s !== null);
    const fields = groups.flatMap((g) => g.fields);
    let idleCalls = 0;

    const store = {
        groups: computed(() => groups),
        sections: ref(sections),
        fields: ref(fields),
        selection,
        select: (next: Selection) => (selection.value = next),
        whenIdle: () => {
            idleCalls++;

            return Promise.resolve(null);
        },
    } as unknown as BuilderStore;

    return { store, selected: () => selection.value, idleCalls: () => idleCalls };
}

/** One section gated on a field in the previous one — the ordinary branching shape. */
function branchingGroups(): CanvasGroup[] {
    return [
        { section: null, fields: [] },
        { section: section('basics'), fields: [field('age', { label: 'Your age' })] },
        { section: section('adults', { relevant_expression: '${age} > 18' }), fields: [] },
    ];
}

function mountRail(groups: CanvasGroup[], double = makeStore(groups)) {
    return {
        double,
        wrapper: mount(LogicRail, { props: { store: double.store, formId: 'form-1', active: true } }),
    };
}

const NO_NOTICES = { ok: true, status: 200, json: () => Promise.resolve({ notices: [] }) };

beforeEach(() => {
    vi.useFakeTimers();
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(NO_NOTICES));
});

afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
});

/** Let the debounce elapse and every queued promise settle. */
async function settle(): Promise<void> {
    await vi.advanceTimersByTimeAsync(500);
    await flushPromises();
}

describe('what the author reads', () => {
    it('renders the rail in authored order, with the plain-English reading AND the raw expression', () => {
        const { wrapper } = mountRail(branchingGroups());

        const names = wrapper.findAll('.rail__name').map((n) => n.text());
        expect(names).toEqual(['Basics', 'Adults']);

        expect(wrapper.text()).toContain('Shown when Your age is more than 18.');
        // The raw text is never replaced by the reading — it is what the author edits and publishes, and
        // H21d2's editor is specified to leave a non-representable one alone rather than rewrite it.
        expect(wrapper.findAll('.rail__expression').map((c) => c.text())).toContain('${age} > 18');
    });

    it('says an unconditioned node is always shown, rather than leaving it blank', () => {
        const { wrapper } = mountRail(branchingGroups());

        expect(wrapper.text()).toContain('Always shown');
    });

    it('states the honest limit of the model, so nobody goes looking for an arrow to drag', () => {
        // §8: the canvas draws a rail with conditions, not a flow chart, and it "must be designed to say so
        // rather than to imply otherwise".
        const { wrapper } = mountRail(branchingGroups());

        expect(wrapper.find('.rail__explainer').text()).toContain('not');
        expect(wrapper.find('.rail__explainer').text()).toContain('condition each section');
    });

    it('keeps a form with no conditions honest instead of showing an empty rail', () => {
        const { wrapper } = mountRail([
            { section: null, fields: [] },
            { section: section('basics'), fields: [field('name')] },
        ]);

        expect(wrapper.find('.rail__none').exists()).toBe(true);
        expect(wrapper.findAll('.rail__node')).toHaveLength(1);
    });

    it('warns on the card when a condition does not parse', () => {
        const { wrapper } = mountRail([
            { section: null, fields: [] },
            { section: section('broken', { relevant_expression: '${age} = = 1' }), fields: [] },
        ]);

        expect(wrapper.text()).toContain('can’t be read');
        expect(wrapper.find('.rail__summary--invalid').exists()).toBe(true);
    });
});

describe('selection reaches the config panel', () => {
    it('selects a section when its heading is activated', async () => {
        const { wrapper, double } = mountRail(branchingGroups());

        await wrapper.findAll('button.rail__head')[1].trigger('click');

        expect(double.selected()).toEqual({ kind: 'section', uid: wrapper.vm.$props.store.sections.value[1].uid });
    });

    it('selects a conditioned FIELD, so a field-level condition is editable from here too', async () => {
        const groups: CanvasGroup[] = [
            { section: null, fields: [] },
            {
                section: section('basics'),
                fields: [field('age'), field('followup', { relevant_expression: '${age} > 18' })],
            },
        ];
        const { wrapper, double } = mountRail(groups);

        await wrapper.find('.rail__field-head').trigger('click');

        expect(double.selected()).toEqual({ kind: 'field', uid: groups[1].fields[1].uid });
    });

    it('gives the lead bucket no button, because there is no row to select', () => {
        const { wrapper } = mountRail([
            { section: null, fields: [field('stray')] },
            { section: section('basics'), fields: [] },
        ]);

        // A control that selects nothing is a keyboard stop that does nothing.
        expect(wrapper.find('.rail__head--static').exists()).toBe(true);
        expect(wrapper.findAll('button.rail__head')).toHaveLength(1);
    });
});

describe('the server-derived notices', () => {
    it('fetches the graph once the view is open and hangs each notice on the node it names', async () => {
        vi.mocked(fetch).mockResolvedValue({
            ok: true,
            status: 200,
            json: () =>
                Promise.resolve({
                    notices: [
                        {
                            kind: 'forward_reference',
                            nodes: ['adults', 'age'],
                            message: 'This depends on “age”, which comes later in the form.',
                        },
                    ],
                }),
        } as unknown as Response);

        const { wrapper } = mountRail(branchingGroups());
        await settle();

        expect(vi.mocked(fetch)).toHaveBeenCalledWith('/forms/form-1/graph', expect.objectContaining({ method: 'GET' }));
        expect(wrapper.findAll('.rail__node')[1].text()).toContain('which comes later in the form');
        expect(wrapper.findAll('.rail__node')[0].text()).not.toContain('which comes later in the form');
    });

    it('waits for the builder’s own writes to land before asking', async () => {
        // The endpoint reads the PERSISTED draft. A fetch that overtakes an in-flight PATCH analyses the
        // form as it was a keystroke ago and reports notices about text the author has already changed.
        const { double } = mountRail(branchingGroups());
        await settle();

        expect(double.idleCalls()).toBeGreaterThan(0);
    });

    it('re-checks when a condition changes, and NOT when a label does', async () => {
        const groups = branchingGroups();
        const double = makeStore(groups);
        mountRail(groups, double);
        await settle();
        const afterFirst = vi.mocked(fetch).mock.calls.length;

        double.store.sections.value[1].label = 'Grown-ups';
        await settle();
        expect(vi.mocked(fetch).mock.calls.length).toBe(afterFirst);

        // …but a changed condition can create or clear a forward reference or a cycle, so it must re-ask.
        double.store.sections.value[1].relevant_expression = '${age} > 21';
        await settle();
        expect(vi.mocked(fetch).mock.calls.length).toBeGreaterThan(afterFirst);
    });

    it('re-checks when sections are RE-ORDERED, because the forward-reference rule is positional', async () => {
        const groups = branchingGroups();
        const double = makeStore(groups);
        mountRail(groups, double);
        await settle();
        const afterFirst = vi.mocked(fetch).mock.calls.length;

        double.store.sections.value[1].sequence = 99;
        await settle();

        expect(vi.mocked(fetch).mock.calls.length).toBeGreaterThan(afterFirst);
    });

    it('says the check FAILED rather than showing a clean form', async () => {
        // The failure mode worth guarding: an author who sees no notices must be able to tell "nothing is
        // wrong" from "we could not look".
        vi.mocked(fetch).mockResolvedValue({
            ok: false,
            status: 500,
            json: () => Promise.resolve({ message: 'Server error.' }),
        } as unknown as Response);

        const { wrapper } = mountRail(branchingGroups());
        await settle();

        expect(wrapper.find('.rail__status-error').exists()).toBe(true);
        expect(wrapper.text()).toContain('Try again');
    });

    it('does not fetch at all while the view is closed', async () => {
        const double = makeStore(branchingGroups());
        mount(LogicRail, { props: { store: double.store, formId: 'form-1', active: false } });
        await settle();

        expect(vi.mocked(fetch)).not.toHaveBeenCalled();
    });
});
