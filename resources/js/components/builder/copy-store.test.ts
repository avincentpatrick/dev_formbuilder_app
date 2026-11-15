/**
 * M240 (`builder-copy-section`, `D224`, `D225`) — copies as the builder store makes them, through the REAL store with `fetch`
 * mocked. A copy is ONE request: the server re-points the links, places the copies and answers with the whole order, which
 * the store applies as it stands (it sends no reorder, which would log "Moved" for questions nobody moved). One undo entry;
 * its undo is one request; its redo makes the copy again under the same identities, so later history entries still resolve.
 */
import { flushPromises } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { fetchMock, jsonResponse, pageProps, requestLog, serverField, serverSection } from './builder-store-fixtures';
import type { ServerSection } from './types';
import { useBuilderStore } from './useBuilderStore';

const SECTION = serverSection({ id: 's-1', key: 's1', label: 'Indicator', sequence: 0 });
const A = serverField({ id: 'f-a', key: 'q1_keep', label: 'Keep it?', form_section_id: 's-1', sequence: 0 });
const B = serverField({ id: 'f-b', key: 'q1_note', label: 'Why?', form_section_id: 's-1', sequence: 1 });
const C = serverField({ id: 'f-c', key: 'after', label: 'After', form_section_id: 's-1', sequence: 2 });

afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
});

const keysInOrder = (store: ReturnType<typeof useBuilderStore>): string[] =>
    store.groups.value.flatMap((group) => group.fields.map((field) => field.key));

/**
 * A server that copies like the real one: fresh ids on every call (so a redo is a NEW row), keys from `keys`, the copies
 * placed right after the sources, and the whole order in the reply.
 */
function copyingServer() {
    let made = 0;

    return fetchMock().mockImplementation((url: string, init?: RequestInit) => {
        const body = typeof init?.body === 'string' ? (JSON.parse(init.body) as { field_ids?: string[] }) : {};
        if (init?.method === 'POST' && (url.endsWith('/fields/duplicate') || url.endsWith('/sections/s-1/duplicate'))) {
            made++;
            const sectionCopy = url.includes('/sections/');
            const sources = sectionCopy ? [A, B, C] : [A, B].filter((f) => body.field_ids?.includes(f.id));
            const section: ServerSection | null = sectionCopy
                ? serverSection({ id: `s-copy-${made}`, key: 's2', label: 'Indicator (copy)', sequence: 1 })
                : null;
            const copies = sources.map((source, i) => ({
                source_id: source.id,
                field: serverField({
                    ...source,
                    id: `${source.id}-copy-${made}`,
                    key: source.key.replace('q1', 'q2'),
                    form_section_id: section?.id ?? source.form_section_id,
                    sequence: sectionCopy ? 3 + i : 2 + i,
                    version: `copy-${made}`,
                }),
            }));
            const fields = sectionCopy
                ? [A, B, C, ...copies.map((c) => c.field)]
                : [A, B, ...copies.map((c) => c.field), { ...C, sequence: 2 + copies.length }];

            return Promise.resolve(
                jsonResponse(200, {
                    section,
                    copies,
                    order: {
                        sections: [{ id: 's-1', sequence: 0 }, ...(section ? [{ id: section.id, sequence: 1 }] : [])],
                        fields: fields.map((f, i) => ({ id: f.id, form_section_id: f.form_section_id, sequence: sectionCopy ? f.sequence : i, section_sequence: null })),
                    },
                }),
            );
        }

        return Promise.resolve(jsonResponse(200, { deleted: true, ok: true }));
    });
}

describe('copying ticked questions', () => {
    it('sends one request in the order on screen, places the copies from the reply, sends no reorder, and moves the ticks', async () => {
        const mock = copyingServer();
        const store = useBuilderStore(pageProps({ sections: [SECTION], fields: [A, B, C] }));
        const [a, b] = store.fields.value;

        await store.duplicateFields([b.uid, a.uid]); // ticked out of order
        await flushPromises();

        expect(requestLog(mock).map((r) => `${r.method} ${r.url}`)).toEqual(['POST /forms/form-1/fields/duplicate']);
        expect(requestLog(mock)[0].body).toEqual({ field_ids: ['f-a', 'f-b'] });
        expect(keysInOrder(store)).toEqual(['q1_keep', 'q1_note', 'q2_keep', 'q2_note', 'after']);
        const copies = store.fields.value.filter((f) => f.key.startsWith('q2'));
        expect([...store.checkedFields.value]).toEqual(copies.map((f) => f.uid));
        expect(store.canUndo.value).toBe(true);
    });

    it('undoes in one request, and redoes under the same identities', async () => {
        const mock = copyingServer();
        const store = useBuilderStore(pageProps({ sections: [SECTION], fields: [A, B, C] }));
        const [a, b] = store.fields.value;
        await store.duplicateFields([a.uid, b.uid]);
        const copyUids = store.fields.value.filter((f) => f.key.startsWith('q2')).map((f) => f.uid);

        await store.undo();
        await flushPromises();

        expect(requestLog(mock).at(-1)).toMatchObject({ method: 'POST', url: '/forms/form-1/fields/delete-many', body: { field_ids: ['f-a-copy-1', 'f-b-copy-1'] } });
        expect(keysInOrder(store)).toEqual(['q1_keep', 'q1_note', 'after']);
        expect(store.checkedFields.value.size).toBe(0); // a deleted question is no longer ticked

        await store.redo();
        await flushPromises();

        const again = store.fields.value.filter((f) => f.key.startsWith('q2'));
        expect(again.map((f) => f.uid)).toEqual(copyUids);
        expect(again.map((f) => f.id)).toEqual(['f-a-copy-2', 'f-b-copy-2']);
    });

    it('sends an edit still waiting out its debounce before the copy, so the copy has it', async () => {
        const mock = copyingServer();
        const store = useBuilderStore(pageProps({ sections: [SECTION], fields: [A, B, C] }));
        const a = store.fields.value[0];
        a.label = 'Keep it, really?';
        store.touch(a.uid, 'field');

        await store.duplicateField(a.uid);
        await flushPromises();

        expect(requestLog(mock).map((r) => `${r.method} ${r.url}`)).toEqual([
            'PATCH /forms/form-1/fields/f-a',
            'POST /forms/form-1/fields/duplicate',
        ]);
        expect(store.selection.value).toEqual({ kind: 'field', uid: store.fields.value.find((f) => f.key === 'q2_keep')?.uid });
    });
});

describe('copying a section', () => {
    it('makes the section and its questions in one request, right after the source, and selects the copy', async () => {
        const mock = copyingServer();
        const store = useBuilderStore(pageProps({ sections: [SECTION], fields: [A, B, C] }));

        await store.duplicateSection(store.sections.value[0].uid);
        await flushPromises();

        expect(requestLog(mock).map((r) => `${r.method} ${r.url}`)).toEqual(['POST /forms/form-1/sections/s-1/duplicate']);
        const copy = store.sections.value.find((s) => s.id === 's-copy-1');
        expect(copy?.label).toBe('Indicator (copy)');
        expect(store.selection.value).toEqual({ kind: 'section', uid: copy?.uid });
        expect(store.groups.value.map((g) => g.section?.id ?? null)).toEqual([null, 's-1', 's-copy-1']);
    });

    it('undoes with one delete naming the copies, so they go with the section rather than to the top level', async () => {
        const mock = copyingServer();
        const store = useBuilderStore(pageProps({ sections: [SECTION], fields: [A, B, C] }));
        await store.duplicateSection(store.sections.value[0].uid);

        await store.undo();
        await flushPromises();

        expect(requestLog(mock).at(-1)).toMatchObject({
            method: 'DELETE',
            url: '/forms/form-1/sections/s-copy-1',
            body: { field_ids: ['f-a-copy-1', 'f-b-copy-1', 'f-c-copy-1'] },
        });
        expect(store.sections.value.map((s) => s.id)).toEqual(['s-1']);
        expect(keysInOrder(store)).toEqual(['q1_keep', 'q1_note', 'after']);
    });

    it('keeps a later Move pointing at the section a redo put back under a new id', async () => {
        const mock = copyingServer();
        const store = useBuilderStore(pageProps({ sections: [SECTION], fields: [A, B, C] }));
        await store.duplicateSection(store.sections.value[0].uid);
        const copied = store.fields.value.find((f) => f.id === 'f-a-copy-1') as (typeof store.fields.value)[number];

        await store.moveFieldsToSection([copied.uid], null); // Move: its "before" names s-copy-1
        await store.undo(); // the Move
        await store.undo(); // the copy
        await store.redo(); // the copy again: the section is now s-copy-2
        await store.redo(); // the Move
        await store.undo(); // the Move, whose "before" still names s-copy-1
        await flushPromises();

        const last = requestLog(mock).at(-1);
        expect(last?.url).toBe('/forms/form-1/reorder');
        const sent = (last?.body as { fields: { id: string; form_section_id: string | null }[] }).fields;
        const now = store.fields.value.find((f) => f.uid === copied.uid);
        expect(now?.id).toBe('f-a-copy-2'); // the same question on screen, a new row on the server
        expect(sent.find((row) => row.id === now?.id)?.form_section_id).toBe('s-copy-2');
    });
});

describe('a section put back by Undo', () => {
    it('keeps a Move made before its delete pointing at it under its new id (the same alias, older path)', async () => {
        const mock = fetchMock().mockImplementation((url: string, init?: RequestInit) => {
            if (url.endsWith('/sections') && init?.method === 'POST') {
                return Promise.resolve(jsonResponse(200, serverSection({ id: 's-back', key: 's1', label: 'Indicator', sequence: 0 })));
            }
            if (url.endsWith('/sections/s-back') && init?.method === 'PATCH') {
                return Promise.resolve(jsonResponse(200, serverSection({ id: 's-back', key: 's1', label: 'Indicator', sequence: 0, version: 'v2' })));
            }

            return Promise.resolve(jsonResponse(200, { deleted: true, ok: true }));
        });
        const loose = serverField({ id: 'f-loose', key: 'loose', label: 'Loose', form_section_id: null, sequence: 3 });
        const store = useBuilderStore(pageProps({ sections: [SECTION], fields: [A, B, C, loose] }));
        const moved = store.fields.value.find((f) => f.key === 'loose') as (typeof store.fields.value)[number];

        await store.moveFieldsToSection([moved.uid], 's-1'); // Move: its "after" names s-1
        await store.deleteSection(store.sections.value[0].uid);
        await store.undo(); // the delete: the section is back as s-back
        await store.undo(); // the Move
        await store.redo(); // the Move again, whose "after" still names s-1
        await flushPromises();

        const last = requestLog(mock).at(-1);
        expect(last?.url).toBe('/forms/form-1/reorder');
        const sent = (last?.body as { fields: { id: string; form_section_id: string | null }[] }).fields;
        expect(sent.find((row) => row.id === 'f-loose')?.form_section_id).toBe('s-back');
    });
});
