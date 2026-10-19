/**
 * M177 (`R-71509975`) — "Other, please specify" as the builder store does it, through the REAL store with `fetch` mocked.
 * One server call makes the pair; the store places the new question right after the choice question, saves the order
 * once, and records ONE undo entry, whose undo turns the pair off again. A key renamed on either side keeps the pair
 * linked: the condition names the choice question's new key, and the link names the new question's.
 */
import { flushPromises } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { fetchMock, jsonResponse, pageProps, requestLog, serverField } from './builder-store-fixtures';
import type { ServerField } from './types';
import { useBuilderStore } from './useBuilderStore';

const OPTIONS = [{ value: 'farmer', label: 'Farmer' }];
const PARENT = serverField({ id: 'f-job', key: 'occupation', field_type: 'single_select', label: 'Occupation', sequence: 0, config: { options: OPTIONS } });
const AFTER = serverField({ id: 'f-age', key: 'age', field_type: 'integer', label: 'Age', sequence: 1 });
const CHILD = serverField({
    id: 'f-other',
    key: 'occupation_other',
    field_type: 'short_text',
    label: 'Please specify',
    is_required: 'required',
    relevant_expression: "selected(${occupation}, 'other')",
    sequence: 2,
});
const PAIRED_PARENT: ServerField = {
    ...PARENT,
    version: 'v2',
    config: { options: [...OPTIONS, { value: 'other', label: 'Other' }], other_specify_key: 'occupation_other' },
};

afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
});

const order = (store: ReturnType<typeof useBuilderStore>): string[] =>
    [...store.fields.value].sort((a, b) => a.sequence - b.sequence).map((field) => field.key);

describe('turning "Other, please specify" on', () => {
    it('makes the pair in one call, places the new question right after the choice question, and saves the order once', async () => {
        const mock = fetchMock().mockImplementation((url: string, init?: RequestInit) => {
            if (url.endsWith('/other-specify') && init?.method === 'POST') return Promise.resolve(jsonResponse(200, { parent: PAIRED_PARENT, child: CHILD }));

            return Promise.resolve(jsonResponse(200, {}));
        });
        const store = useBuilderStore(pageProps({ fields: [PARENT, AFTER] }));
        const parent = store.fields.value[0];

        await store.addOtherSpecify(parent.uid);
        await flushPromises();

        expect(requestLog(mock).map((r) => `${r.method} ${r.url}`)).toEqual([
            'POST /forms/form-1/fields/f-job/other-specify',
            'POST /forms/form-1/reorder',
        ]);
        expect(order(store)).toEqual(['occupation', 'occupation_other', 'age']);
        expect(parent.version).toBe('v2'); // the parent's new token: its next edit is not a conflict
        expect(parent.config.other_specify_key).toBe('occupation_other');
        expect(store.canUndo.value).toBe(true);
    });

    it('turns the pair off on undo, and on again on redo', async () => {
        const mock = fetchMock().mockImplementation((url: string, init?: RequestInit) => {
            if (url.endsWith('/other-specify') && init?.method === 'POST') return Promise.resolve(jsonResponse(200, { parent: PAIRED_PARENT, child: CHILD }));
            if (url.endsWith('/other-specify') && init?.method === 'DELETE') return Promise.resolve(jsonResponse(200, { parent: { ...PARENT, version: 'v3' } }));

            return Promise.resolve(jsonResponse(200, {}));
        });
        const store = useBuilderStore(pageProps({ fields: [PARENT, AFTER] }));
        await store.addOtherSpecify(store.fields.value[0].uid);

        await store.undo();
        await flushPromises();

        expect(order(store)).toEqual(['occupation', 'age']);
        expect(store.fields.value[0].config.other_specify_key).toBeUndefined();

        await store.redo();
        await flushPromises();

        const calls = requestLog(mock).map((r) => `${r.method} ${r.url}`);
        expect(calls.filter((c) => c.endsWith('/other-specify'))).toEqual([
            'POST /forms/form-1/fields/f-job/other-specify',
            'DELETE /forms/form-1/fields/f-job/other-specify',
            'POST /forms/form-1/fields/f-job/other-specify',
        ]);
        expect(order(store)).toEqual(['occupation', 'occupation_other', 'age']);
    });
});

describe('turning "Other, please specify" off', () => {
    it('removes the new question and the choice in one call, and the undo brings them back', async () => {
        const mock = fetchMock().mockImplementation((url: string, init?: RequestInit) => {
            if (url.endsWith('/other-specify') && init?.method === 'DELETE') return Promise.resolve(jsonResponse(200, { parent: { ...PARENT, version: 'v3' } }));
            if (url.endsWith('/other-specify') && init?.method === 'POST') return Promise.resolve(jsonResponse(200, { parent: PAIRED_PARENT, child: CHILD }));

            return Promise.resolve(jsonResponse(200, {}));
        });
        const store = useBuilderStore(pageProps({ fields: [PAIRED_PARENT, AFTER, { ...CHILD, sequence: 1 }] }));

        await store.removeOtherSpecify(store.fields.value[0].uid);
        await flushPromises();

        expect(order(store)).toEqual(['occupation', 'age']);
        expect(store.fields.value[0].config.options).toEqual(OPTIONS);

        await store.undo();
        await flushPromises();

        expect(order(store)).toEqual(['occupation', 'occupation_other', 'age']);
        expect(requestLog(mock).map((r) => r.method).filter((m) => m !== 'GET')).toEqual(['DELETE', 'POST', 'POST']);
    });
});

describe('a renamed key keeps the pair linked', () => {
    function echoing() {
        return fetchMock().mockImplementation((url: string, init?: RequestInit) => {
            const body = typeof init?.body === 'string' ? (JSON.parse(init.body) as Record<string, unknown>) : {};
            const row = [PAIRED_PARENT, CHILD].find((r) => url.endsWith(`/fields/${r.id}`));

            return Promise.resolve(jsonResponse(200, { ...(row ?? {}), ...body, version: 'v9' }));
        });
    }

    it('rewrites the Please specify condition when the choice question’s key changes', async () => {
        const mock = echoing();
        const store = useBuilderStore(pageProps({ fields: [PAIRED_PARENT, { ...CHILD, sequence: 1 }] }));
        const parent = store.fields.value[0];

        parent.key = 'job';
        store.touch(parent.uid, 'field');
        await store.whenIdle();
        await flushPromises();
        await store.whenIdle();

        const child = store.fields.value.find((field) => field.id === 'f-other')!;
        expect(child.relevant_expression).toBe("selected(${job}, 'other')");
        const childSave = requestLog(mock).find((r) => r.method === 'PATCH' && r.url.endsWith('/f-other'));
        expect(childSave?.body).toMatchObject({ relevant_expression: "selected(${job}, 'other')" });
        expect(store.canUndo.value).toBe(true);
    });

    it('follows the Please specify question’s new key from the choice question', async () => {
        const mock = echoing();
        const store = useBuilderStore(pageProps({ fields: [PAIRED_PARENT, { ...CHILD, sequence: 1 }] }));
        const child = store.fields.value[1];

        child.key = 'occupation_detail';
        store.touch(child.uid, 'field');
        await store.whenIdle();
        await flushPromises();
        await store.whenIdle();

        expect(store.fields.value[0].config.other_specify_key).toBe('occupation_detail');
        const parentSave = requestLog(mock).find((r) => r.method === 'PATCH' && r.url.endsWith('/f-job'));
        expect((parentSave?.body as { config?: Record<string, unknown> } | undefined)?.config?.other_specify_key).toBe('occupation_detail');
    });
});
