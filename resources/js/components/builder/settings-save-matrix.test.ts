/**
 * M170 — every builder setting reaches the server, and a refresh straight after a change keeps it (`R-bfa3eb93`,
 * `R-fdc743ab`, `R-76474e31`, `R-7ebfb08e`, `R-4d50e3c8`).
 *
 * The user's report (2026-10-14): "For a Whole Number question, the settings on the Basic, Validation and Advanced
 * tabs don't save. Please check every field type and every setting on every tab. For each one, confirm that changing
 * it starts a save, that the server keeps the value, and that it's still there after a plain page refresh without
 * clicking anything else first."
 *
 * ── WHAT THIS FILE PROVES, AND WHAT IT LEAVES TO THE OTHER TWO ────────────────────────────────────────────────────
 * Here: the REAL `ConfigPanel` over the REAL store, so a control that changed the screen without starting a save, or a
 * save that left the value out of its body, fails by name. `BuilderSettingsRoundTripTest.php` proves the server keeps
 * every setting of every type and sends it back on the builder page; `tests/e2e/builder-settings-refresh.spec.ts`
 * proves a real browser refresh, straight after the change, keeps it.
 *
 * Basics, Validation and Advanced are one set of controls for every type, so they are driven on a Whole Number — the
 * reported case — through the DOM. Each type's own tab is driven by its editor's event, the way `ConfigPanel.test.ts`
 * drives the note's content editor, because each editor's own inputs are its own suite's subject.
 *
 * `useEntitlements` is mocked as in `ConfigPanel.test.ts`: it calls Inertia's `usePage()`, which throws outside an app.
 */
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import ConfigPanel from './ConfigPanel.vue';
import { ENUMS, PALETTE, fetchMock, jsonResponse, pageProps, requestLog, serverField } from './builder-store-fixtures';
import { useBuilderStore, type BuilderStore } from './useBuilderStore';
import type { BuilderEnums, PaletteGroup, ServerField } from './types';

vi.mock('@/composables/useEntitlements', () => ({
    useEntitlements: () => ({ feature: () => true }),
}));

/** The fixture's enums, with the two rules a number takes — the shared fixture has none for the `number` shape. */
const NUMBER_ENUMS: BuilderEnums = {
    ...ENUMS,
    validation_rule_types: [
        ...ENUMS.validation_rule_types,
        { value: 'min_value', label: 'Minimum value', shapes: ['number'], takes_operator: false, takes_related_field: false, operator_may_be_empty: false, governs_relevance: false, governs_requiredness: false, related_comparison: null },
        { value: 'max_value', label: 'Maximum value', shapes: ['number'], takes_operator: false, takes_related_field: false, operator_may_be_empty: false, governs_relevance: false, governs_requiredness: false, related_comparison: null },
    ],
};

/** One type per config editor, so every own-tab editor the panel can mount is mounted here. */
const EDITOR_PALETTE: PaletteGroup[] = [
    ...PALETTE,
    {
        category: 'choice',
        label: 'Choice',
        icon: 'list',
        types: [
            { value: 'single_select', label: 'Single choice', advanced: false, has_options: true, config_editor: 'choices', value_shape: 'choice', operand_kind: 'value' },
            { value: 'cascading_select', label: 'Cascading select', advanced: true, has_options: false, config_editor: 'cascading', value_shape: 'hierarchy', operand_kind: 'value' },
            { value: 'matrix', label: 'Matrix (grid)', advanced: true, has_options: false, config_editor: 'matrix', value_shape: 'no_answer', operand_kind: 'none' },
            { value: 'likert_matrix', label: 'Likert matrix', advanced: true, has_options: false, config_editor: 'likert_matrix', value_shape: 'scale', operand_kind: 'value' },
            { value: 'geopoint', label: 'GPS point', advanced: true, has_options: false, config_editor: 'geo', value_shape: 'geo', operand_kind: 'none' },
            { value: 'file_upload', label: 'File upload', advanced: false, has_options: false, config_editor: 'media', value_shape: 'attachment', operand_kind: 'none' },
        ],
    },
];

const WHOLE_NUMBER = serverField({ id: 'f1', field_type: 'integer', key: 'household_size', label: 'Household size' });

let mock: ReturnType<typeof vi.fn>;
let version = 1;

/** The server keeps whatever it is sent and answers with the next token — the store reads only `version`. */
function serverKeepsEverything(): void {
    mock.mockImplementation((_url: string, init?: RequestInit) => {
        const body = typeof init?.body === 'string' ? (JSON.parse(init.body) as Record<string, unknown>) : {};
        version += 1;

        return Promise.resolve(jsonResponse(200, { ...body, id: 'f1', version: `v${version}` }));
    });
}

function patches(): Record<string, unknown>[] {
    return requestLog(mock)
        .filter((request) => request.method === 'PATCH')
        .map((request) => request.body as Record<string, unknown>);
}

function makeStore(field: ServerField = WHOLE_NUMBER, palette: PaletteGroup[] = EDITOR_PALETTE): BuilderStore {
    return useBuilderStore(pageProps({ fields: [field], palette, enums: NUMBER_ENUMS }));
}

async function mountOn(store: BuilderStore): Promise<VueWrapper> {
    store.select({ kind: 'field', uid: store.fields.value[0].uid });
    const wrapper = mount(ConfigPanel, { props: { store }, attachTo: document.body });
    await flushPromises();

    return wrapper;
}

async function openTab(wrapper: VueWrapper, label: string): Promise<void> {
    const tab = wrapper.findAll('[role="tab"]').find((candidate) => candidate.text() === label);
    expect(tab, `the ${label} tab`).toBeDefined();
    await tab!.trigger('click');
}

/** The input a visible `<label>` names — the way a person, and Playwright's `getByLabel`, finds it. */
function labelled(wrapper: VueWrapper, text: string) {
    const label = wrapper.findAll('label').find((candidate) => candidate.text().trim() === text);
    expect(label, `a label reading "${text}"`).toBeDefined();

    return wrapper.find(`#${label!.attributes('for')}`);
}

/** The debounce runs out, and whatever it queued settles. */
async function debounceElapses(store: BuilderStore): Promise<void> {
    await vi.advanceTimersByTimeAsync(600);
    await store.whenIdle();
    await flushPromises();
}

beforeEach(() => {
    vi.useFakeTimers();
    sessionStorage.clear();
    version = 1;
    mock = fetchMock();
    serverKeepsEverything();
});

afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
    document.body.innerHTML = '';
});

describe('a Whole Number — every control on Basics, Validation and Advanced starts a save that carries its value', () => {
    const cases: { tab: string; name: string; change: (wrapper: VueWrapper) => Promise<unknown>; sent: (body: Record<string, unknown>) => unknown; expected: unknown }[] = [
        { tab: 'Basics', name: 'Label', change: (w) => labelled(w, 'Label').setValue('People here'), sent: (b) => b.label, expected: 'People here' },
        { tab: 'Basics', name: 'Help text', change: (w) => labelled(w, 'Help text').setValue('Everyone who sleeps here.'), sent: (b) => b.hint, expected: 'Everyone who sleeps here.' },
        { tab: 'Basics', name: 'Placeholder', change: (w) => labelled(w, 'Placeholder').setValue('e.g. 4'), sent: (b) => b.placeholder, expected: 'e.g. 4' },
        {
            tab: 'Basics',
            name: 'Requiredness',
            change: (w) => w.findAll('input[type="radio"]').find((radio) => radio.element.getAttribute('value') === 'required')!.setValue(true),
            sent: (b) => b.is_required,
            expected: 'required',
        },
        {
            tab: 'Basics',
            name: 'Allow negative numbers',
            change: (w) => w.find('[data-negative-toggle] input[type="checkbox"]').setValue(false),
            sent: (b) => (b.validations as { rule_type: string; rule_value: string }[]).map((row) => `${row.rule_type} ${row.rule_value}`),
            expected: ['min_value 0'],
        },
        {
            tab: 'Validation',
            name: 'a rule and its value',
            change: async (w) => {
                await w.findAll('button').find((button) => button.text() === 'Add rule')!.trigger('click');
                await w.find('[aria-label="Rule 1 check"]').setValue('max_value');
                await w.find('[aria-label="Rule 1 value"]').setValue('20');
            },
            sent: (b) => (b.validations as { rule_type: string; rule_value: string }[]).map((row) => `${row.rule_type} ${row.rule_value}`),
            expected: ['max_value 20'],
        },
        {
            tab: 'Validation',
            name: "a rule's error message",
            change: async (w) => {
                await w.findAll('button').find((button) => button.text() === 'Add rule')!.trigger('click');
                await w.find('[aria-label="Rule 1 error message"]').setValue('Too many.');
            },
            sent: (b) => (b.validations as { error_message: string }[])[0]?.error_message,
            expected: 'Too many.',
        },
        {
            tab: 'Advanced',
            name: 'Field key',
            change: (w) => labelled(w, 'Field key').setValue('people_here'),
            sent: (b) => b.key,
            expected: 'people_here',
        },
        {
            tab: 'Advanced',
            name: 'Show this question only when…',
            change: async (w) => w.findComponent({ name: 'ConditionEditor' }).vm.$emit('update:expression', '${age} > 17'),
            sent: (b) => b.relevant_expression,
            expected: '${age} > 17',
        },
        { tab: 'Advanced', name: 'Default value', change: (w) => labelled(w, 'Default value').setValue('4'), sent: (b) => b.default_value, expected: '4' },
        {
            tab: 'Advanced',
            name: 'Contains personal data (PII)',
            change: (w) => w.findAll('.config__checks input[type="checkbox"]')[0].setValue(true),
            sent: (b) => b.is_pii,
            expected: true,
        },
        {
            tab: 'Advanced',
            name: 'Sensitive',
            change: (w) => w.findAll('.config__checks input[type="checkbox"]')[1].setValue(true),
            sent: (b) => b.is_sensitive,
            expected: true,
        },
        {
            tab: 'Advanced',
            name: 'Indexed for reporting, with its type',
            change: async (w) => {
                await w.findAll('.config__checks input[type="checkbox"]')[2].setValue(true);
                await labelled(w, 'Indexed data type').setValue('number');
            },
            sent: (b) => [b.is_queryable, b.indexed_data_type],
            expected: [true, 'number'],
        },
    ];

    it.each(cases)('$tab → $name', async ({ tab, change, sent, expected }) => {
        const store = makeStore();
        const wrapper = await mountOn(store);
        await openTab(wrapper, tab);

        await change(wrapper);
        await debounceElapses(store);

        const sentBodies = patches();
        expect(sentBodies.length, 'a save was started').toBeGreaterThan(0);
        expect(sent(sentBodies[sentBodies.length - 1])).toEqual(expected);
        expect(store.saveState.value).toBe('saved');
    });
});

describe("every type's own tab starts a save that carries what its editor changed", () => {
    const cases: { type: string; tab: string; editor: string; event: string; value: unknown; key: string }[] = [
        { type: 'single_select', tab: 'Options', editor: 'ChoicesEditor', event: 'update:options', value: [{ value: 'a', label: 'Alpha' }], key: 'options' },
        { type: 'cascading_select', tab: 'Levels', editor: 'CascadingEditor', event: 'update:levels', value: [{ key: 'region', label: 'Region' }], key: 'levels' },
        { type: 'matrix', tab: 'Grid', editor: 'MatrixEditor', event: 'update:rows', value: [{ value: 'r1', label: 'Morning' }], key: 'rows' },
        { type: 'likert_matrix', tab: 'Grid', editor: 'LikertMatrixEditor', event: 'update:columns', value: [{ value: '1', label: 'Agree' }], key: 'columns' },
        { type: 'geopoint', tab: 'Map', editor: 'GeoEditor', event: 'update:accuracyThreshold', value: 15, key: 'accuracy_threshold' },
        { type: 'file_upload', tab: 'Media', editor: 'MediaEditor', event: 'update:maxCount', value: 3, key: 'max_count' },
        { type: 'hidden', tab: 'Prefill', editor: 'PrefillEditor', event: 'update:urlParam', value: 'ref', key: 'url_param' },
        { type: 'note', tab: 'Content', editor: 'ContentBlocksEditor', event: 'update:blocks', value: [{ type: 'divider' }], key: 'content' },
    ];

    it.each(cases)('$type → $tab', async ({ type, tab, editor, event, value, key }) => {
        const store = makeStore(serverField({ id: 'f1', field_type: type, key: `a_${type}`, label: type }));
        const wrapper = await mountOn(store);
        await openTab(wrapper, tab);

        wrapper.findComponent({ name: editor }).vm.$emit(event, value);
        await debounceElapses(store);

        const sentBodies = patches();
        expect(sentBodies.length, 'a save was started').toBeGreaterThan(0);
        expect((sentBodies[sentBodies.length - 1].config as Record<string, unknown>)[key]).toEqual(value);
    });

    it('a Calculated question → its formula', async () => {
        const store = makeStore(serverField({ id: 'f1', field_type: 'calculated', key: 'total', label: 'Total' }));
        const wrapper = await mountOn(store);

        await labelled(wrapper, 'Calculation formula').setValue('${a} * 2');
        await debounceElapses(store);

        expect((patches().at(-1)?.config as Record<string, unknown>).calculated_formula).toBe('${a} * 2');
    });
});

describe('a refresh straight after a change keeps it (R-bfa3eb93)', () => {
    it('says "Saving…", never "All changes saved", while a change waits for its save', async () => {
        const store = makeStore();
        const wrapper = await mountOn(store);

        await labelled(wrapper, 'Label').setValue('People here');

        expect(store.saveState.value).toBe('saving');
        await debounceElapses(store);
        expect(store.saveState.value).toBe('saved');
    });

    it('sends a waiting change at once when the page is left, and asks the browser to keep the request alive', async () => {
        const store = makeStore();
        const wrapper = await mountOn(store);
        await labelled(wrapper, 'Label').setValue('People here');

        store.flushForLeave();
        await Promise.resolve();
        await Promise.resolve();

        const sent = mock.mock.calls.map((call) => call[1] as RequestInit);
        expect(sent).toHaveLength(1);
        expect(sent[0].keepalive).toBe(true);
        expect(JSON.parse(sent[0].body as string).label).toBe('People here');
    });

    it('puts an edit the server never got back on the reloaded page, and saves it', async () => {
        const before = makeStore();
        const wrapper = await mountOn(before);
        await labelled(wrapper, 'Label').setValue('People here');
        wrapper.unmount(); // the page goes before the debounce runs out — nothing was sent

        expect(mock).not.toHaveBeenCalled();

        // The reload: the server still holds the old question, at the token the edit was made against.
        const after = makeStore();
        expect(after.fields.value[0].label).toBe('People here');
        await after.whenIdle();
        await flushPromises();

        expect(patches().map((body) => body.label)).toEqual(['People here']);
        expect(after.saveState.value).toBe('saved');

        // And once the server holds it, a second reload has nothing to put back.
        const again = makeStore(serverField({ ...WHOLE_NUMBER, label: 'People here', version: `v${version}` }));
        await again.whenIdle();
        expect(patches()).toHaveLength(1);
    });

    it('puts back an edit made while an earlier save of the same question was still on its way', async () => {
        // Measured in a real browser before this case existed: Placeholder typed while the previous setting's save was in
        // flight, then a refresh. That save landed AFTER the page went, so the reload saw the question's token move and
        // threw the newer edit away as "changed elsewhere" — the move was this tab's own.
        const before = makeStore();
        const wrapper = await mountOn(before);
        mock.mockImplementationOnce(() => new Promise<Response>(() => undefined)); // on its way when the page goes

        await labelled(wrapper, 'Label').setValue('People here');
        await vi.advanceTimersByTimeAsync(600);
        await labelled(wrapper, 'Placeholder').setValue('e.g. 4');
        wrapper.unmount();

        // The reload: the first save landed after all, at a token the second edit was not made against.
        const after = makeStore(serverField({ ...WHOLE_NUMBER, label: 'People here', version: 'v7' }));
        expect(after.fields.value[0].placeholder).toBe('e.g. 4');
        await after.whenIdle();
        await flushPromises();

        const last = patches().at(-1)!;
        expect(last).toMatchObject({ label: 'People here', placeholder: 'e.g. 4', version: 'v7' });
        expect(after.saveState.value).toBe('saved');
    });

    it('drops a kept edit when the question moved on the server since, rather than writing over it', async () => {
        const before = makeStore();
        const wrapper = await mountOn(before);
        await labelled(wrapper, 'Label').setValue('People here');
        wrapper.unmount();

        const after = makeStore(serverField({ ...WHOLE_NUMBER, label: 'Renamed elsewhere', version: 'v7' }));
        await after.whenIdle();

        expect(after.fields.value[0].label).toBe('Renamed elsewhere');
        expect(patches()).toEqual([]);
    });

    it('takes a 409 whose question already holds this very edit as saved, with no conflict to resolve', async () => {
        const store = makeStore();
        const wrapper = await mountOn(store);
        mock.mockImplementationOnce(() =>
            Promise.resolve(jsonResponse(409, { current: { ...WHOLE_NUMBER, label: 'People here', version: 'v9' }, message: 'Changed elsewhere.' })),
        );

        await labelled(wrapper, 'Label').setValue('People here');
        await debounceElapses(store);

        expect(store.conflict.value).toBeNull();
        expect(store.fields.value[0].version).toBe('v9');
        expect(store.saveState.value).toBe('saved');
    });
});

describe('a save that failed is never reported as saved (R-fdc743ab, R-76474e31)', () => {
    it('still reads "Not saved" after a later save of another question lands', async () => {
        const store = useBuilderStore(
            pageProps({
                fields: [WHOLE_NUMBER, serverField({ id: 'f2', key: 'age', label: 'Age', sequence: 2 })],
                palette: EDITOR_PALETTE,
                enums: NUMBER_ENUMS,
            }),
        );
        mock.mockImplementationOnce(() => Promise.reject(new TypeError('Failed to fetch')));

        const [first, second] = store.fields.value;
        first.label = 'People here';
        store.touch(first.uid, 'field');
        await debounceElapses(store);
        second.label = 'Age in years';
        store.touch(second.uid, 'field');
        await debounceElapses(store);

        expect(patches().map((body) => body.label)).toEqual(['People here', 'Age in years']);
        expect(store.saveState.value).toBe('failed');
        expect(store.saveError.value).not.toBeNull();
    });

    it('says in plain words that the session ended, and Retry sends the edit again after signing back in', async () => {
        const store = makeStore();
        mock.mockImplementationOnce(() => Promise.resolve({ status: 419, ok: false, json: () => Promise.reject(new SyntaxError('html')) } as unknown as Response));

        const [field] = store.fields.value;
        field.label = 'People here';
        store.touch(field.uid, 'field');
        await debounceElapses(store);

        expect(store.saveState.value).toBe('failed');
        expect(store.saveError.value).toMatch(/signed out/i);
        expect(store.saveError.value).not.toMatch(/419/);

        await store.retryUnsaved();
        await flushPromises();

        expect(patches().map((body) => body.label)).toEqual(['People here', 'People here']);
        expect(store.saveState.value).toBe('saved');
        expect(store.saveError.value).toBeNull();
    });
});

describe('an Expression rule never blocks the other settings (R-7ebfb08e)', () => {
    it('shows the expression box at once, and a save leaves out a rule with neither a check nor an expression', async () => {
        const store = makeStore();
        const wrapper = await mountOn(store);
        await openTab(wrapper, 'Validation');
        await wrapper.findAll('button').find((button) => button.text() === 'Add rule')!.trigger('click');

        await wrapper.find('[aria-label="Rule 1 type"]').setValue('expression');
        expect(wrapper.find('[aria-label="Rule 1 expression"]').exists()).toBe(true);

        // Another setting on the same question, while the expression is still empty.
        await openTab(wrapper, 'Basics');
        await labelled(wrapper, 'Help text').setValue('Everyone who sleeps here.');
        await debounceElapses(store);

        const last = patches().at(-1)!;
        expect(last.hint).toBe('Everyone who sleeps here.');
        expect(last.validations).toEqual([]);
        expect(store.saveState.value).toBe('saved');
    });
});

describe('"Save to library" copies the question with its latest edit (R-4d50e3c8)', () => {
    it('sends the waiting edit before asking the server to copy the question', async () => {
        const store = makeStore();
        const wrapper = await mountOn(store);
        await labelled(wrapper, 'Label').setValue('People here');

        await openTab(wrapper, 'Advanced');
        await wrapper.findAll('button').find((button) => button.text() === 'Save to library')!.trigger('click');
        await store.whenIdle();
        await flushPromises();

        const order = requestLog(mock).map((request) => `${request.method} ${request.url.split('/').slice(-1)[0]}`);
        expect(order).toEqual(['PATCH f1', 'POST save-to-library']);
    });
});
