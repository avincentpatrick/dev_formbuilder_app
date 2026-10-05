/**
 * Changing a question's type, as the builder store does it (Increment M123, `B5b`) — driven through the REAL store
 * with `fetch` mocked, asserting the exact ORDER and BODIES of what it sends, because every defect this guards is a
 * request in the wrong place: a POST racing a queued PATCH, a PATCH sent where a conversion should be, a 409 routed
 * into the wrong dialog, a history step that lies about having worked.
 */
import { flushPromises } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { conversionPlan, fetchMock, jsonResponse, pageProps, requestLog, serverField } from './builder-store-fixtures';
import { useBuilderStore } from './useBuilderStore';
import type { ServerField } from './types';

type Store = ReturnType<typeof useBuilderStore>;

const EMAIL_PATTERN = { rule_type: 'pattern', operator: null, rule_value: '[^@\\s]+@[^@\\s]+', expression: null, error_message: 'Enter a valid email address.', related_field_key: null, sequence: 0 };
const PHONE_PATTERN = { rule_type: 'pattern', operator: null, rule_value: '\\+?[0-9 ]{6,20}', expression: null, error_message: 'Enter a valid phone number.', related_field_key: null, sequence: 0 };

function storeWith(field: Partial<ServerField> = {}): Store {
    return useBuilderStore(pageProps({ fields: [serverField(field)] }));
}

/** What `ConfigPanel`'s setters do: change the local row and tell the store, without waiting. */
function typeLabel(store: Store, label: string): void {
    const field = store.fields.value[0];
    field.label = label;
    store.touch(field.uid, 'field');
}

async function settle(store: Store): Promise<void> {
    await store.whenIdle();
    await flushPromises();
}

const plansResponse = (...plans: ReturnType<typeof conversionPlan>[]) => jsonResponse(200, { plans });

afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
});

describe('loading the plans', () => {
    it('flushes a pending edit BEFORE it reads, so the plans describe what is on screen (CS1)', async () => {
        const mock = fetchMock()
            .mockResolvedValueOnce(jsonResponse(200, serverField({ label: 'Job', version: 'v2' })))
            .mockResolvedValueOnce(plansResponse(conversionPlan()));
        const store = storeWith();
        typeLabel(store, 'Job');

        const outcome = await store.loadConversionPlans(store.fields.value[0].uid);

        expect(requestLog(mock).map((r) => `${r.method} ${r.url}`)).toEqual([
            'PATCH /forms/form-1/fields/f1',
            'GET /forms/form-1/fields/f1/conversions',
        ]);
        expect(outcome).toEqual({ status: 'loaded', from: 'short_text', plans: [conversionPlan()] });
    });

    it('reports a failed read to the caller and never to the save verdict (CS2)', async () => {
        fetchMock().mockResolvedValueOnce(jsonResponse(500, { message: 'Server Error' }));
        const store = storeWith();

        const outcome = await store.loadConversionPlans(store.fields.value[0].uid);

        expect(outcome).toEqual({ status: 'failed', message: 'Server Error' });
        expect(store.saveError.value).toBeNull();
        expect(store.saveState.value).toBe('idle');
    });

    it('refuses to read while the last save failed, sending nothing (CS3)', async () => {
        const mock = fetchMock().mockResolvedValueOnce(jsonResponse(422, { message: 'The label is too long.' }));
        const store = storeWith();
        typeLabel(store, 'x'.repeat(300));
        await settle(store);

        const outcome = await store.loadConversionPlans(store.fields.value[0].uid);

        expect(outcome.status).toBe('failed');
        expect(requestLog(mock).map((r) => r.method)).toEqual(['PATCH']);
    });

    it('refuses plans for a type the screen does not show (CS4)', async () => {
        fetchMock().mockResolvedValueOnce(plansResponse(conversionPlan({ from: 'email', to: 'phone' })));
        const store = storeWith();

        const outcome = await store.loadConversionPlans(store.fields.value[0].uid);

        expect(outcome.status).toBe('failed');
        expect(outcome.status === 'failed' && outcome.message).toContain('changed somewhere else');
    });
});

describe('converting', () => {
    it('sends the convert AFTER a queued edit, with the token that edit returned (CS5)', async () => {
        const mock = fetchMock()
            .mockResolvedValueOnce(jsonResponse(200, serverField({ label: 'Job', version: 'v2' })))
            .mockResolvedValueOnce(jsonResponse(200, serverField({ label: 'Job', field_type: 'long_text', version: 'v3' })));
        const store = storeWith();
        typeLabel(store, 'Job');

        await store.convertField(store.fields.value[0].uid, conversionPlan());

        const log = requestLog(mock);
        expect(log.map((r) => `${r.method} ${r.url}`)).toEqual(['PATCH /forms/form-1/fields/f1', 'POST /forms/form-1/fields/f1/convert']);
        expect(log[1].body).toEqual({ to: 'long_text', version: 'v2', fingerprint: 'a'.repeat(64) });
    });

    it('adopts the converted row in place and records exactly one undo entry (CS6)', async () => {
        const mock = fetchMock().mockResolvedValueOnce(jsonResponse(200, serverField({ field_type: 'long_text', version: 'v2' })));
        const store = storeWith();
        const row = store.fields.value[0];
        store.select({ kind: 'field', uid: row.uid });

        const outcome = await store.convertField(row.uid, conversionPlan());

        expect(outcome).toEqual({ status: 'converted' });
        expect(store.fields.value[0]).toBe(row); // the same object: the uid, selection and history survive
        expect(row.field_type).toBe('long_text');
        expect(row.version).toBe('v2');
        expect(store.selectedField.value?.uid).toBe(row.uid);

        // The baseline moved with it, so a no-op flush records no edit and sends nothing.
        store.touch(row.uid, 'field');
        await settle(store);
        expect(requestLog(mock)).toHaveLength(1);

        expect(store.canUndo.value).toBe(true);
        expect(store.canRedo.value).toBe(false);
    });

    it('adopts the server row on a 409 and never opens the conflict dialog (CS7)', async () => {
        fetchMock().mockResolvedValueOnce(
            jsonResponse(409, { message: 'This item was changed elsewhere since you last loaded it.', current: serverField({ label: 'Theirs', version: 'v7' }) }),
        );
        const store = storeWith();
        const row = store.fields.value[0];

        const outcome = await store.convertField(row.uid, conversionPlan());

        expect(outcome).toEqual({ status: 'stale' });
        expect(store.conflict.value).toBeNull();
        expect(row.label).toBe('Theirs');
        expect(row.version).toBe('v7');
        expect(store.canUndo.value).toBe(false);
        expect(store.saveState.value).not.toBe('failed');
    });

    it('returns a refusal to the dialog and leaves the save verdict alone (CS8)', async () => {
        fetchMock().mockResolvedValueOnce(jsonResponse(422, { message: 'This question cannot be changed from Short text to Photo.' }));
        const store = storeWith();
        const row = store.fields.value[0];

        const outcome = await store.convertField(row.uid, conversionPlan({ to: 'image_capture' }));

        expect(outcome).toEqual({ status: 'failed', message: 'This question cannot be changed from Short text to Photo.' });
        expect(row.field_type).toBe('short_text');
        expect(store.saveError.value).toBeNull();
        expect(store.canUndo.value).toBe(false);
    });

    it('sends no convert while a conflict is open (CS9)', async () => {
        const mock = fetchMock().mockResolvedValueOnce(
            jsonResponse(409, { message: 'changed', current: serverField({ label: 'Theirs', version: 'v7' }) }),
        );
        const store = storeWith();
        typeLabel(store, 'Mine');
        await settle(store);
        expect(store.conflict.value).not.toBeNull();

        const outcome = await store.convertField(store.fields.value[0].uid, conversionPlan());

        expect(outcome.status).toBe('failed');
        expect(requestLog(mock).map((r) => r.method)).toEqual(['PATCH']);
    });
});

describe('undo and redo of a conversion', () => {
    async function convertedShortToLong(): Promise<{ store: Store; mock: ReturnType<typeof fetchMock> }> {
        const mock = fetchMock().mockResolvedValueOnce(jsonResponse(200, serverField({ field_type: 'long_text', version: 'v2' })));
        const store = storeWith();
        await store.convertField(store.fields.value[0].uid, conversionPlan());

        return { store, mock };
    }

    it('undoes an exact round trip by converting back alone, with the reverse plan’s fingerprint (CS10)', async () => {
        const { store, mock } = await convertedShortToLong();
        mock.mockResolvedValueOnce(plansResponse(conversionPlan({ from: 'long_text', to: 'short_text', fingerprint: 'b'.repeat(64) })))
            .mockResolvedValueOnce(jsonResponse(200, serverField({ version: 'v3' })));

        await store.undo();

        const log = requestLog(mock).slice(1);
        expect(log.map((r) => `${r.method} ${r.url}`)).toEqual(['GET /forms/form-1/fields/f1/conversions', 'POST /forms/form-1/fields/f1/convert']);
        expect(log[1].body).toEqual({ to: 'short_text', version: 'v2', fingerprint: 'b'.repeat(64) });
        expect(store.fields.value[0].field_type).toBe('short_text');
        expect(store.canRedo.value).toBe(true);
    });

    it('undoes a lossy conversion by converting back and THEN restoring the old rules (CS11)', async () => {
        const mock = fetchMock().mockResolvedValueOnce(
            jsonResponse(200, serverField({ field_type: 'phone', validations: [PHONE_PATTERN], version: 'v2' })),
        );
        const store = storeWith({ field_type: 'email', validations: [EMAIL_PATTERN] });
        await store.convertField(
            store.fields.value[0].uid,
            conversionPlan({ from: 'email', to: 'phone', lossless: false, requires_confirmation: true, dropped: [{ ...EMAIL_PATTERN, reason: 'type_default', reason_message: 'm' }], added: [{ ...PHONE_PATTERN }] }),
        );
        mock.mockResolvedValueOnce(plansResponse(conversionPlan({ from: 'phone', to: 'email', lossless: false, requires_confirmation: true, fingerprint: 'c'.repeat(64) })))
            .mockResolvedValueOnce(jsonResponse(200, serverField({ field_type: 'email', validations: [{ ...EMAIL_PATTERN, sequence: 1 }], version: 'v3' })))
            .mockResolvedValueOnce(jsonResponse(200, serverField({ field_type: 'email', validations: [EMAIL_PATTERN], version: 'v4' })));

        await store.undo();

        const log = requestLog(mock).slice(1);
        expect(log.map((r) => r.method)).toEqual(['GET', 'POST', 'PATCH']);
        const patch = log[2].body as { version: string; validations: { rule_type: string; rule_value: string }[] };
        expect(patch.version).toBe('v3'); // the token the reverse POST returned, read inside the step
        expect(patch.validations[0].rule_value).toBe(EMAIL_PATTERN.rule_value);
        expect(patch.validations).toHaveLength(1);
    });

    it('restores even after a LOSSLESS forward plan when the way back re-adds a check the author had deleted (CS12)', async () => {
        const mock = fetchMock().mockResolvedValueOnce(jsonResponse(200, serverField({ field_type: 'short_text', version: 'v2' })));
        const store = storeWith({ field_type: 'email' }); // its shipped pattern was deleted by the author
        await store.convertField(store.fields.value[0].uid, conversionPlan({ from: 'email', to: 'short_text' }));
        mock.mockResolvedValueOnce(
            plansResponse(conversionPlan({ from: 'short_text', to: 'email', requires_confirmation: true, added: [{ sequence: 0, rule_type: 'pattern', rule_value: EMAIL_PATTERN.rule_value, error_message: EMAIL_PATTERN.error_message }] })),
        )
            .mockResolvedValueOnce(jsonResponse(200, serverField({ field_type: 'email', validations: [EMAIL_PATTERN], version: 'v3' })))
            .mockResolvedValueOnce(jsonResponse(200, serverField({ field_type: 'email', version: 'v4' })));

        await store.undo();

        expect(requestLog(mock).slice(1).map((r) => r.method)).toEqual(['GET', 'POST', 'PATCH']);
        expect(store.fields.value[0].validations).toEqual([]);
    });

    it('redoes by converting forward again with a fresh plan (CS13)', async () => {
        const { store, mock } = await convertedShortToLong();
        mock.mockResolvedValueOnce(plansResponse(conversionPlan({ from: 'long_text', to: 'short_text', fingerprint: 'b'.repeat(64) })))
            .mockResolvedValueOnce(jsonResponse(200, serverField({ version: 'v3' })));
        await store.undo();
        mock.mockResolvedValueOnce(plansResponse(conversionPlan({ fingerprint: 'd'.repeat(64) })))
            .mockResolvedValueOnce(jsonResponse(200, serverField({ field_type: 'long_text', version: 'v4' })));

        await store.redo();

        const log = requestLog(mock).slice(3);
        expect(log.map((r) => r.method)).toEqual(['GET', 'POST']);
        expect(log[1].body).toEqual({ to: 'long_text', version: 'v3', fingerprint: 'd'.repeat(64) });
        expect(store.fields.value[0].field_type).toBe('long_text');
        expect(store.canUndo.value).toBe(true);
    });

    it('keeps a half-finished undo on its stack, shows the server’s row, and retries only what is left (CS14)', async () => {
        const mock = fetchMock().mockResolvedValueOnce(
            jsonResponse(200, serverField({ field_type: 'phone', validations: [PHONE_PATTERN], version: 'v2' })),
        );
        const store = storeWith({ field_type: 'email', validations: [EMAIL_PATTERN] });
        await store.convertField(store.fields.value[0].uid, conversionPlan({ from: 'email', to: 'phone', lossless: false, requires_confirmation: true }));
        mock.mockResolvedValueOnce(plansResponse(conversionPlan({ from: 'phone', to: 'email', lossless: false })))
            .mockResolvedValueOnce(jsonResponse(200, serverField({ field_type: 'email', validations: [], version: 'v3' })))
            .mockResolvedValueOnce(jsonResponse(500, { message: 'Server Error' }));

        await store.undo();

        expect(store.canUndo.value).toBe(true);
        expect(store.canRedo.value).toBe(false);
        expect(store.saveError.value).toContain('Couldn’t undo the type change.');
        expect(store.fields.value[0].field_type).toBe('email');
        expect(store.fields.value[0].validations).toEqual([]); // what the server holds, not the unsaved restore

        mock.mockResolvedValueOnce(jsonResponse(200, serverField({ field_type: 'email', validations: [EMAIL_PATTERN], version: 'v4' })));
        await store.undo();

        expect(requestLog(mock).slice(4).map((r) => r.method)).toEqual(['PATCH']);
        expect(store.canRedo.value).toBe(true);
    });
});

describe('older history across a type that moved elsewhere', () => {
    it('still moves an ordinary edit to the redo stack (CS15)', async () => {
        fetchMock()
            .mockResolvedValueOnce(jsonResponse(200, serverField({ label: 'Job', version: 'v2' })))
            .mockResolvedValueOnce(jsonResponse(200, serverField({ version: 'v3' })));
        const store = storeWith();
        typeLabel(store, 'Job');
        await settle(store);

        await store.undo();

        expect(store.canRedo.value).toBe(true);
        expect(store.fields.value[0].label).toBe('Occupation');
    });

    it('drops an edit recorded under a type that has since changed, sending nothing (CS16)', async () => {
        const mock = fetchMock()
            .mockResolvedValueOnce(jsonResponse(200, serverField({ label: 'A', version: 'v2' })))
            .mockResolvedValueOnce(jsonResponse(409, { message: 'changed', current: serverField({ field_type: 'long_text', label: 'Theirs', version: 'v9' }) }));
        const store = storeWith();
        typeLabel(store, 'A');
        await settle(store);
        typeLabel(store, 'B');
        await settle(store);
        await store.resolveConflict('theirs');

        await store.undo();

        expect(requestLog(mock)).toHaveLength(2);
        expect(store.canUndo.value).toBe(false);
        expect(store.canRedo.value).toBe(false);
        expect(store.fields.value[0].field_type).toBe('long_text');
        expect(store.saveError.value).toContain('type has changed since');
    });

    it('refuses "Keep mine" across types and keeps theirs, but still re-saves mine within one type (CS17)', async () => {
        const across = fetchMock().mockResolvedValueOnce(
            jsonResponse(409, { message: 'changed', current: serverField({ field_type: 'long_text', label: 'Theirs', version: 'v9' }) }),
        );
        const store = storeWith();
        typeLabel(store, 'Mine');
        await settle(store);

        await store.resolveConflict('mine');

        expect(requestLog(across)).toHaveLength(1);
        expect(store.fields.value[0].field_type).toBe('long_text');
        expect(store.fields.value[0].label).toBe('Theirs');
        expect(store.saveError.value).toContain('changed to another type somewhere else');

        vi.unstubAllGlobals();
        const same = fetchMock()
            .mockResolvedValueOnce(jsonResponse(409, { message: 'changed', current: serverField({ label: 'Theirs', version: 'v9' }) }))
            .mockResolvedValueOnce(jsonResponse(200, serverField({ label: 'Mine', version: 'v10' })));
        const control = storeWith();
        typeLabel(control, 'Mine');
        await settle(control);

        await control.resolveConflict('mine');

        expect(requestLog(same).map((r) => r.method)).toEqual(['PATCH', 'PATCH']);
        expect(control.fields.value[0].label).toBe('Mine');
    });

    it('drops a conversion entry whose question is now neither type, sending nothing (CS18)', async () => {
        const mock = fetchMock()
            .mockResolvedValueOnce(jsonResponse(200, serverField({ field_type: 'long_text', version: 'v2' })))
            .mockResolvedValueOnce(jsonResponse(409, { message: 'changed', current: serverField({ field_type: 'email', version: 'v9' }) }));
        const store = storeWith();
        await store.convertField(store.fields.value[0].uid, conversionPlan());
        typeLabel(store, 'B');
        await settle(store);
        await store.resolveConflict('theirs');

        await store.undo();

        expect(requestLog(mock)).toHaveLength(2);
        expect(store.canUndo.value).toBe(false);
        expect(store.fields.value[0].field_type).toBe('email');
        expect(store.saveError.value).toContain('can’t be undone');
    });
});

describe('a formula default survives the undo of a conversion to a note (M134, R-6d7b9ff7)', () => {
    it('PATCHes the default back WITH its formula flag, so today() does not return as text (CS15)', async () => {
        const mock = fetchMock().mockResolvedValueOnce(
            jsonResponse(200, serverField({ field_type: 'note', default_value: null, default_value_is_expression: false, version: 'v2' })),
        );
        const store = storeWith({ field_type: 'short_text', default_value: 'today()', default_value_is_expression: true });
        await store.convertField(
            store.fields.value[0].uid,
            conversionPlan({
                from: 'short_text',
                to: 'note',
                lossless: false,
                requires_confirmation: true,
                changes: [
                    { column: 'default_value', from: 'today()', to: null },
                    { column: 'default_value_is_expression', from: true, to: false },
                ],
            }),
        );
        expect(store.fields.value[0].default_value_is_expression).toBe(false);

        mock.mockResolvedValueOnce(plansResponse(conversionPlan({ from: 'note', to: 'short_text', lossless: true, requires_confirmation: false })))
            .mockResolvedValueOnce(jsonResponse(200, serverField({ field_type: 'short_text', default_value: null, default_value_is_expression: false, version: 'v3' })))
            .mockResolvedValueOnce(jsonResponse(200, serverField({ field_type: 'short_text', default_value: 'today()', default_value_is_expression: true, version: 'v4' })));

        await store.undo();

        const log = requestLog(mock).slice(1);
        expect(log.map((r) => r.method)).toEqual(['GET', 'POST', 'PATCH']);
        const patch = log[2].body as { default_value: string | null; default_value_is_expression: boolean };
        expect(patch.default_value).toBe('today()');
        expect(patch.default_value_is_expression).toBe(true);
    });

    it('sends the flag on every field save, so a save never clears it by omission', async () => {
        const mock = fetchMock().mockResolvedValueOnce(jsonResponse(200, serverField({ label: 'Visit', default_value: 'today()', default_value_is_expression: true, version: 'v2' })));
        const store = storeWith({ default_value: 'today()', default_value_is_expression: true });
        typeLabel(store, 'Visit');
        await settle(store);

        expect((requestLog(mock)[0].body as { default_value_is_expression: boolean }).default_value_is_expression).toBe(true);
    });
});
