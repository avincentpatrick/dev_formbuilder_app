/**
 * The builder store's explicit save verdict (Increment J7) — the first unit coverage `useBuilderStore` has.
 *
 * ⚠️ THE DEFECT THIS PINS SHIPPED FOR MONTHS BECAUSE NOTHING ASSERTED THE STRING. `saving` was an in-flight
 * counter decremented in a `.finally()`, and `guard()` catches the throw, so a FAILED write returned that
 * counter to zero exactly as a successful one did. The toolbar's polite live region therefore announced "All
 * changes saved" at the instant a write failed, beside an assertive alert saying the opposite — at every
 * width, including 1440px. (WCAG 4.1.3; exceptions-log #13 §3.)
 *
 * The cases below fail on the pre-J7 store. Two of them fail on an INDICATOR-ONLY fix as well, which is the
 * point of driving the real store rather than the label module: the same lie lived one level deeper, in two
 * `saveError` clears that sat on the success path and let a later write erase an earlier row's failure.
 *
 * Fixtures are a local copy of `builderClient.test.ts`'s, deliberately. That file is the other copy; the
 * third one promotes them to a shared module rather than this one editing a green file for one caller.
 */

import { flushPromises } from '@vue/test-utils';
import { afterEach, describe as group, expect, it, vi } from 'vitest';

import { useBuilderStore } from './useBuilderStore';
import type { BuilderPageProps, ServerField, ServerSection } from './types';

function jsonResponse(status: number, body: unknown): Response {
    return {
        status,
        ok: status >= 200 && status < 300,
        json: () => Promise.resolve(body),
    } as unknown as Response;
}

function fetchMock(): ReturnType<typeof vi.fn> {
    const mock = vi.fn();
    vi.stubGlobal('fetch', mock);

    return mock;
}

function serverField(overrides: Partial<ServerField> = {}): ServerField {
    return {
        id: 'f1',
        form_section_id: 's1',
        key: 'occupation',
        field_type: 'short_text',
        label: 'Occupation',
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
        sequence: 1,
        section_sequence: 1,
        version: 'v1',
        validations: [],
        ...overrides,
    };
}

function serverSection(overrides: Partial<ServerSection> = {}): ServerSection {
    return {
        id: 's1',
        key: 'adults',
        label: 'Adults only',
        description: null,
        is_repeatable: false,
        min_instances: null,
        max_instances: null,
        relevant_expression: null,
        sequence: 1,
        version: 'v1',
        ...overrides,
    };
}

function pageProps(): BuilderPageProps {
    return {
        form: {
            id: 'form-1',
            title: 'Branching Router',
            description: null,
            status: 'draft',
            save_and_resume: false,
            opens_at: null,
            closes_at: null,
            timezone: 'UTC',
            max_responses: null,
            confirmation_message: null,
            confirmation_message_translations: {},
            redirect_kind: 'none',
            redirect_form_id: null,
            redirect_url: null,
            redirect_targets: [],
            redirect_delay_seconds: 20,
            theme_preset: null,
            theme_presets: [],
            default_locale: 'en',
            supported_locales: ['en'],
        },
        draft: { id: 'v-1', version_number: 1 },
        sections: [serverSection()],
        fields: [serverField()],
        palette: [],
        enums: { required_modes: [], indexed_data_types: [], validation_rule_types: [], comparison_operators: [] },
        library: [],
        timezones: ['UTC'],
        crumbs: [],
    };
}

type Store = ReturnType<typeof useBuilderStore>;

/** What `ConfigPanel`'s setters do: mutate the local row, then tell the store. */
async function editField(store: Store, label: string): Promise<void> {
    const field = store.fields.value[0];
    field.label = label;
    store.touch(field.uid, 'field');

    await store.whenIdle();
    await flushPromises();
}

afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
    document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT';
});

group('the save verdict is explicit, never inferred from an idle queue', () => {
    it('starts idle, having written nothing', async () => {
        fetchMock();
        const store = useBuilderStore(pageProps());

        expect(store.saveState.value).toBe('idle');
        expect(store.saveError.value).toBeNull();
        expect(store.saving.value).toBe(false);
    });

    it('reaches saved when the write lands', async () => {
        fetchMock().mockResolvedValue(jsonResponse(200, serverField({ version: 'v2' })));
        const store = useBuilderStore(pageProps());

        await editField(store, 'Job title');

        expect(store.saveState.value).toBe('saved');
        expect(store.saveError.value).toBeNull();
    });

    it('does NOT reach saved when the write fails', async () => {
        // ⭐ THE DEFECT. Red on the pre-J7 store, where the in-flight counter returned to zero on this path
        // and the toolbar announced success. Both halves asserted: the positive verdict AND the explicit
        // negative, because a fix that merely stopped saying 'saving' would satisfy only one of them.
        fetchMock().mockResolvedValue(jsonResponse(422, { message: 'The label field is required.' }));
        const store = useBuilderStore(pageProps());

        await editField(store, '');

        expect(store.saveState.value).toBe('failed');
        expect(store.saveState.value).not.toBe('saved');
        expect(store.saving.value).toBe(false);
        expect(store.saveError.value).toBe('The label field is required.');
    });

    it('holds the invariant the two channels depend on, in both directions', async () => {
        // `saveState === 'saved'` implies no alert, and an alert implies not-saved. The toolbar's polite
        // region and ConfigPanel's assertive one read one source, so they cannot contradict each other —
        // which is the whole property, stated as a property.
        const mock = fetchMock().mockResolvedValue(jsonResponse(200, serverField({ version: 'v2' })));
        const store = useBuilderStore(pageProps());

        await editField(store, 'Job title');
        expect(store.saveState.value === 'saved' && store.saveError.value === null).toBe(true);
        expect(store.conflict.value).toBeNull();

        mock.mockResolvedValue(jsonResponse(500, { message: 'Server error.' }));
        await editField(store, 'Another');
        expect(store.saveError.value).not.toBeNull();
        expect(store.saveState.value).not.toBe('saved');
    });

    it('recovers to saved once a later burst lands cleanly', async () => {
        const mock = fetchMock().mockResolvedValue(jsonResponse(500, { message: 'Server error.' }));
        const store = useBuilderStore(pageProps());

        await editField(store, 'First try');
        expect(store.saveState.value).toBe('failed');

        mock.mockResolvedValue(jsonResponse(200, serverField({ version: 'v2' })));
        await editField(store, 'Second try');

        expect(store.saveState.value).toBe('saved');
        expect(store.saveError.value).toBeNull();
    });

    it('does not let a later success in the SAME burst erase an earlier failure', async () => {
        // ⭐ THE SECOND DEFECT, ONE LEVEL DEEPER, AND THE CASE AN INDICATOR-ONLY FIX STILL FAILS. Two
        // `saveError` clears used to sit on the success path of persistField/persistSection, so a write that
        // succeeded wiped the error belonging to a DIFFERENT row that had just failed. The queue serializes,
        // so both land in one burst and the verdict must cover the burst rather than the last request.
        const mock = fetchMock()
            .mockResolvedValueOnce(jsonResponse(500, { message: 'Deleting that field failed.' }))
            .mockResolvedValue(jsonResponse(200, serverSection({ id: 's2', key: 'added' })));
        const store = useBuilderStore(pageProps());

        // BOTH enqueue synchronously, so they genuinely share one burst. `touch()` would NOT: it schedules a
        // debounce that only flushes on `whenIdle()`, by which time the first burst has already drained and
        // reached its verdict -- which is a different property (see the recovery case above).
        const failing = store.deleteField(store.fields.value[0].uid);
        const succeeding = store.addSection();

        await Promise.all([failing, succeeding]);
        await flushPromises();

        expect(mock.mock.calls.length).toBe(2);
        expect(store.saveState.value).toBe('failed');
        expect(store.saveError.value).toBe('Deleting that field failed.');
    });

    it('reports saving while work is still outstanding', async () => {
        let release: (value: Response) => void = () => {};
        fetchMock().mockReturnValue(new Promise<Response>((resolve) => (release = resolve)));
        const store = useBuilderStore(pageProps());

        // NOT awaited, and deliberately not via `whenIdle()` -- that awaits the queue, so it cannot return
        // while this request is held open. `enqueue` increments synchronously, so the verdict is observable
        // the instant the action is called.
        const inFlight = store.addSection();

        expect(store.saving.value).toBe(true);
        expect(store.saveState.value).toBe('saving');

        release(jsonResponse(200, serverSection({ id: 's2', key: 'added' })));
        await inFlight;
        await flushPromises();

        expect(store.saveState.value).toBe('saved');
    });

    it('keeps the alert up while a retry is in flight rather than hiding it optimistically', async () => {
        // The alert must clear on POSITIVE evidence only. Hiding it the moment a retry starts would tell the
        // author the problem is gone while the outcome is still unknown — the same class of lie as the one
        // this increment closes, moved earlier in time.
        const mock = fetchMock().mockResolvedValue(jsonResponse(500, { message: 'Server error.' }));
        const store = useBuilderStore(pageProps());

        await editField(store, 'First try');
        expect(store.saveError.value).toBe('Server error.');

        let release: (value: Response) => void = () => {};
        mock.mockReturnValue(new Promise<Response>((resolve) => (release = resolve)));

        const retry = store.addSection();

        expect(store.saveState.value).toBe('saving');
        expect(store.saveError.value).toBe('Server error.');

        release(jsonResponse(200, serverSection({ id: 's2', key: 'added' })));
        await retry;
        await flushPromises();

        // ...and only NOW, on positive evidence, does it clear.
        expect(store.saveState.value).toBe('saved');
        expect(store.saveError.value).toBeNull();
    });

    it('reaches no verdict at all when the burst wrote nothing', async () => {
        // ⚠️ WHY THE ATTEMPT FLAG EXISTS. A no-op edit still enqueues, so without it, re-selecting a row or
        // dragging a field back where it started would count as a clean burst and clear a real failure.
        const mock = fetchMock().mockResolvedValue(jsonResponse(500, { message: 'Server error.' }));
        const store = useBuilderStore({
            ...pageProps(),
            fields: [serverField({ id: 'f1', key: 'first' }), serverField({ id: 'f2', key: 'second', sequence: 2 })],
        });

        await editField(store, 'Broken');
        expect(store.saveState.value).toBe('failed');
        const callsAfterFailure = mock.mock.calls.length;

        // Touch ANOTHER question without changing anything: the store's own equality guard means no request is made.
        // ⚠️ M155 (`R-0aa94dce`) moved this off the refused question on purpose: a refused edit's baseline now stays
        // where the server is, so touching THAT question re-sends it — which is the fix, not a burst that wrote nothing.
        const field = store.fields.value[1];
        store.touch(field.uid, 'field');
        await store.whenIdle();
        await flushPromises();

        expect(mock.mock.calls.length).toBe(callsAfterFailure);
        expect(store.saveState.value).toBe('failed');
        expect(store.saveError.value).toBe('Server error.');
    });

    it('does not read a 409 conflict as saved', async () => {
        // The burst drains without an error, so the pre-J7 store announced "All changes saved" while the
        // ConflictDialog was open saying the row had changed underneath. The server does not hold what is on
        // screen, whatever channel is carrying the copy.
        fetchMock().mockResolvedValue(
            jsonResponse(409, { message: 'This field changed somewhere else.', current: serverField({ version: 'v9' }) }),
        );
        const store = useBuilderStore(pageProps());

        await editField(store, 'Mine');

        expect(store.conflict.value).not.toBeNull();
        expect(store.saveState.value).toBe('failed');
        // The dialog carries the copy; the polite region must not recite it.
        expect(store.saveError.value).toBeNull();
    });

    it('lands on one verdict for a debounced burst of edits', async () => {
        // Regression guard on the commit/flush path: several touches collapse to one PATCH, and the verdict
        // must describe the burst rather than firing once per keystroke.
        const mock = fetchMock().mockResolvedValue(jsonResponse(200, serverField({ version: 'v2' })));
        const store = useBuilderStore(pageProps());

        const field = store.fields.value[0];
        field.label = 'One';
        store.touch(field.uid, 'field');
        field.label = 'Two';
        store.touch(field.uid, 'field');

        await store.whenIdle();
        await flushPromises();

        expect(mock).toHaveBeenCalledTimes(1);
        expect(store.saveState.value).toBe('saved');
    });
});

group('a refused field keeps its marks until THAT field saves (M128, R-d001de0c)', () => {
    /** Two questions, so a refusal on one can be followed by a success on the other. */
    function twoFieldProps(): BuilderPageProps {
        return {
            ...pageProps(),
            fields: [serverField({ id: 'f1', key: 'first' }), serverField({ id: 'f2', key: 'second', sequence: 2 })],
        };
    }

    async function editAt(store: Store, index: number, label: string): Promise<void> {
        const field = store.fields.value[index];
        field.label = label;
        store.touch(field.uid, 'field');

        await store.whenIdle();
        await flushPromises();
    }

    const refused = {
        message: 'The label field is required.',
        errors: { label: ['The label field is required.'], 'validations.0.rule_value': ['The rule value must be a number.'] },
    };

    it('keeps the server\'s map under the refused field\'s uid, and the verdict failed', async () => {
        fetchMock().mockResolvedValue(jsonResponse(422, refused));
        const store = useBuilderStore(twoFieldProps());

        await editAt(store, 0, '');

        expect(store.saveFieldErrors.value).toEqual({ [store.fields.value[0].uid]: refused.errors });
        expect(store.saveState.value).toBe('failed');
    });

    it('does not let a later success on ANOTHER question erase the marks, or claim everything saved', async () => {
        // ⭐ `select()` flushes and then switches, so this is the ordinary path: the refusal lands, the author
        // moves on, and the next question saves. The refused value is still on screen and nothing re-sends it.
        const mock = fetchMock().mockResolvedValueOnce(jsonResponse(422, refused));
        const store = useBuilderStore(twoFieldProps());
        await editAt(store, 0, '');

        mock.mockResolvedValue(jsonResponse(200, serverField({ id: 'f2', key: 'second', version: 'v2' })));
        await editAt(store, 1, 'Second question');

        expect(store.saveFieldErrors.value[store.fields.value[0].uid]).toEqual(refused.errors);
        expect(store.saveError.value).toBeNull();
        expect(store.saveState.value).toBe('failed');
    });

    it('clears a field\'s marks when that field next saves, and only then reaches saved', async () => {
        const mock = fetchMock().mockResolvedValueOnce(jsonResponse(422, refused));
        const store = useBuilderStore(twoFieldProps());
        await editAt(store, 0, '');

        mock.mockResolvedValue(jsonResponse(200, serverField({ id: 'f1', key: 'first', version: 'v2' })));
        await editAt(store, 0, 'Fixed');

        expect(store.saveFieldErrors.value).toEqual({});
        expect(store.saveState.value).toBe('saved');
    });

    it('replaces a field\'s marks with none when its next refusal carries no map', async () => {
        const mock = fetchMock().mockResolvedValueOnce(jsonResponse(422, refused));
        const store = useBuilderStore(twoFieldProps());
        await editAt(store, 0, '');

        mock.mockResolvedValue(jsonResponse(500, { message: 'Server error.' }));
        await editAt(store, 0, 'Again');

        expect(store.saveFieldErrors.value).toEqual({});
        expect(store.saveError.value).toBe('Server error.');
    });

    it('marks no field for a refusal of a request that is not that field\'s own save', async () => {
        fetchMock().mockResolvedValue(jsonResponse(422, { message: 'Bad section.', errors: { label: ['Bad section.'] } }));
        const store = useBuilderStore(twoFieldProps());

        await store.addSection();
        await flushPromises();

        expect(store.saveError.value).toBe('Bad section.');
        expect(store.saveFieldErrors.value).toEqual({});
    });

    it('clears the marks of a question that is deleted', async () => {
        const mock = fetchMock().mockResolvedValueOnce(jsonResponse(422, refused));
        const store = useBuilderStore(twoFieldProps());
        await editAt(store, 0, '');

        mock.mockResolvedValue(jsonResponse(204, null));
        await store.deleteField(store.fields.value[0].uid);
        await flushPromises();

        expect(store.saveFieldErrors.value).toEqual({});
    });
});

group('a refused edit is not a saved one: no baseline, no undo entry (M155, R-0aa94dce)', () => {
    /*
     * The store used to advance a field's baseline and push its "Edit …" undo entry BEFORE it learned whether the
     * PATCH landed, stopping only on a 409. After a refusal the screen kept the value, the baseline said the server
     * held it, so nothing re-sent it until the author changed that field AGAIN, and undo replayed a step the server
     * never took. Every case here is red on that store.
     */
    const refused = { message: 'The label field is required.', errors: { label: ['The label field is required.'] } };

    function patchBodies(mock: ReturnType<typeof vi.fn>): Array<Record<string, unknown>> {
        return mock.mock.calls
            .filter(([, init]) => (init as RequestInit | undefined)?.method === 'PATCH')
            .map(([, init]) => JSON.parse(String((init as RequestInit).body)) as Record<string, unknown>);
    }

    /** Touch a field without changing it — what a blur, a re-selection or a typed-and-reverted edit does. */
    async function touchAgain(store: Store): Promise<void> {
        store.touch(store.fields.value[0].uid, 'field');
        await store.whenIdle();
        await flushPromises();
    }

    it('pushes no undo entry for a field edit the server refuses', async () => {
        fetchMock().mockResolvedValue(jsonResponse(422, refused));
        const store = useBuilderStore(pageProps());

        await editField(store, '');

        expect(store.canUndo.value).toBe(false);
    });

    it('sends a refused field again on its next commit, even when nothing on screen changed since', async () => {
        const mock = fetchMock().mockResolvedValueOnce(jsonResponse(500, { message: 'Server error.' }));
        const store = useBuilderStore(pageProps());
        await editField(store, 'Job title');
        expect(patchBodies(mock)).toHaveLength(1);

        mock.mockResolvedValue(jsonResponse(200, serverField({ label: 'Job title', version: 'v2' })));
        await touchAgain(store);

        const sent = patchBodies(mock);
        expect(sent).toHaveLength(2);
        expect(sent[1].label).toBe('Job title');
        expect(store.saveState.value).toBe('saved');
        // The edit the server now holds is the one undo entry, labelled for it.
        expect(store.canUndo.value).toBe(true);
    });

    it('keeps an undo the server refuses on the undo stack, with the screen on what the server holds', async () => {
        const mock = fetchMock().mockResolvedValueOnce(jsonResponse(200, serverField({ label: 'Job title', version: 'v2' })));
        const store = useBuilderStore(pageProps());
        await editField(store, 'Job title');
        expect(store.canUndo.value).toBe(true);

        mock.mockResolvedValue(jsonResponse(422, refused));
        await store.undo();
        await flushPromises();

        expect(store.canUndo.value).toBe(true);
        expect(store.canRedo.value).toBe(false);
        expect(store.fields.value[0].label).toBe('Job title');
        expect(store.saveState.value).toBe('failed');
        // The screen is back on what the server holds, so no control on it is marked as refused.
        expect(store.saveFieldErrors.value).toEqual({});
    });

    it('keeps a redo the server refuses on the redo stack, with the screen on what the server holds', async () => {
        const mock = fetchMock()
            .mockResolvedValueOnce(jsonResponse(200, serverField({ label: 'Job title', version: 'v2' })))
            .mockResolvedValueOnce(jsonResponse(200, serverField({ label: 'Occupation', version: 'v3' })));
        const store = useBuilderStore(pageProps());
        await editField(store, 'Job title');
        await store.undo();
        await flushPromises();
        expect(store.canRedo.value).toBe(true);

        mock.mockResolvedValue(jsonResponse(422, refused));
        await store.redo();
        await flushPromises();

        expect(store.canRedo.value).toBe(true);
        expect(store.canUndo.value).toBe(false);
        expect(store.fields.value[0].label).toBe('Occupation');
    });

    it('pushes no undo entry for a section edit the server refuses, and sends it again on its next commit', async () => {
        const mock = fetchMock().mockResolvedValueOnce(jsonResponse(500, { message: 'Server error.' }));
        const store = useBuilderStore(pageProps());
        const section = store.sections.value[0];
        section.label = 'Grown-ups';
        store.touch(section.uid, 'section');
        await store.whenIdle();
        await flushPromises();

        expect(store.canUndo.value).toBe(false);

        mock.mockResolvedValue(jsonResponse(200, serverSection({ label: 'Grown-ups', version: 'v2' })));
        store.touch(section.uid, 'section');
        await store.whenIdle();
        await flushPromises();

        expect(patchBodies(mock).map((body) => body.label)).toEqual(['Grown-ups', 'Grown-ups']);
        expect(store.canUndo.value).toBe(true);
    });

    it('sends "Keep mine" again on the next commit when the server refuses it', async () => {
        const mock = fetchMock().mockResolvedValueOnce(
            jsonResponse(409, { message: 'This field changed somewhere else.', current: serverField({ label: 'Theirs', version: 'v9' }) }),
        );
        const store = useBuilderStore(pageProps());
        await editField(store, 'Mine');
        expect(store.conflict.value).not.toBeNull();

        mock.mockResolvedValueOnce(jsonResponse(500, { message: 'Server error.' }));
        await store.resolveConflict('mine');
        await flushPromises();

        mock.mockResolvedValue(jsonResponse(200, serverField({ label: 'Mine', version: 'v10' })));
        await touchAgain(store);

        expect(patchBodies(mock).map((body) => body.label)).toEqual(['Mine', 'Mine', 'Mine']);
        expect(store.saveState.value).toBe('saved');
    });

    /** Edit the first section's title and let the commit drain. */
    async function editSection(store: Store, label: string): Promise<void> {
        const section = store.sections.value[0];
        section.label = label;
        store.touch(section.uid, 'section');
        await store.whenIdle();
        await flushPromises();
    }

    it('keeps a section undo the server refuses on the undo stack, with the title the server holds', async () => {
        const mock = fetchMock().mockResolvedValueOnce(jsonResponse(200, serverSection({ label: 'Grown-ups', version: 'v2' })));
        const store = useBuilderStore(pageProps());
        await editSection(store, 'Grown-ups');
        expect(store.canUndo.value).toBe(true);

        mock.mockResolvedValue(jsonResponse(500, { message: 'Server error.' }));
        await store.undo();
        await flushPromises();

        expect(store.canUndo.value).toBe(true);
        expect(store.canRedo.value).toBe(false);
        expect(store.sections.value[0].label).toBe('Grown-ups');
    });

    it('sends "Keep mine" on a section again on the next commit when the server refuses it', async () => {
        const mock = fetchMock().mockResolvedValueOnce(
            jsonResponse(409, { message: 'This section changed somewhere else.', current: serverSection({ label: 'Theirs', version: 'v9' }) }),
        );
        const store = useBuilderStore(pageProps());
        await editSection(store, 'Mine');
        expect(store.conflict.value).not.toBeNull();

        mock.mockResolvedValueOnce(jsonResponse(500, { message: 'Server error.' }));
        await store.resolveConflict('mine');
        await flushPromises();

        mock.mockResolvedValue(jsonResponse(200, serverSection({ label: 'Mine', version: 'v10' })));
        store.touch(store.sections.value[0].uid, 'section');
        await store.whenIdle();
        await flushPromises();

        expect(patchBodies(mock).map((body) => body.label)).toEqual(['Mine', 'Mine', 'Mine']);
        expect(store.saveState.value).toBe('saved');
    });
});
