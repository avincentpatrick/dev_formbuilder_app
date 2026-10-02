import { flushPromises } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { fetchMock, jsonResponse, pageProps, requestLog, serverField } from './builder-store-fixtures';
import { useBuilderStore } from './useBuilderStore';

/**
 * The label a new field arrives with (Increment M125, `R-a367bf9e`). The palette adds "Text" and "Number" — one entry
 * per variant group — so the question must say that, not the server's per-type "Short text". The label is seeded in
 * the STORE, inside the add's own queued task: a server seed would rename the e2e seeder's fixtures, which a hub spec
 * locates by name.
 */
async function settle(): Promise<void> {
    for (let i = 0; i < 6; i += 1) {
        await flushPromises();
    }
}

afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
});

describe('a field added from the palette', () => {
    it('arrives named after its group, as ONE undo entry whose redo replays the name', async () => {
        const mock = fetchMock()
            .mockResolvedValueOnce(jsonResponse(201, serverField({ id: 'f2', key: 'field_2', label: 'Short text', sequence: 2 })))
            .mockResolvedValueOnce(jsonResponse(200, serverField({ id: 'f2', key: 'field_2', label: 'Text', sequence: 2, version: 'v2' })))
            .mockResolvedValue(jsonResponse(200, serverField({ id: 'f3', key: 'field_2', label: 'Text', sequence: 2, version: 'v3' })));
        const store = useBuilderStore(pageProps());

        await store.addField('short_text', 's1');
        await settle();

        const added = store.fields.value.find((field) => field.id === 'f2');
        expect(added?.label).toBe('Text');
        expect(requestLog(mock).map((r) => r.method)).toEqual(['POST', 'PATCH']);
        expect((requestLog(mock)[1].body as { label: string }).label).toBe('Text');

        // One entry: a single undo removes the field and empties the stack.
        await store.undo();
        await settle();
        expect(store.fields.value.some((field) => field.uid === added?.uid)).toBe(false);
        expect(store.canUndo.value).toBe(false);

        await store.redo();
        await settle();
        const patches = requestLog(mock).filter((r) => r.method === 'PATCH');
        expect((patches.at(-1)?.body as { label: string }).label).toBe('Text');
        expect(store.fields.value.find((field) => field.uid === added?.uid)?.label).toBe('Text');
    });

    it('keeps the server’s label for a type the palette lists by itself, and sends nothing more', async () => {
        const mock = fetchMock().mockResolvedValueOnce(
            jsonResponse(201, serverField({ id: 'f2', key: 'field_2', field_type: 'email', label: 'Email', sequence: 2 })),
        );
        const store = useBuilderStore(pageProps());

        await store.addField('email', 's1');
        await settle();

        expect(store.fields.value.find((field) => field.id === 'f2')?.label).toBe('Email');
        expect(requestLog(mock).map((r) => r.method)).toEqual(['POST']);
    });
});
