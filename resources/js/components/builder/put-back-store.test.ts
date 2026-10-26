/**
 * M188 (`R-3894369d`, `D167`) — Put back through the real store: an earlier change saved as an ORDINARY edit, one PATCH
 * naming the entry it restores and one undo entry, so Undo reverses it. Driven with `fetch` stubbed, as the other store
 * tests are.
 *
 * ⛔ THE CASE THAT MATTERS IS THE PENDING EDIT. `commitFieldEdit` reads the question inside its queued task, so a Put back
 * pressed while an edit is still debouncing would ride that edit's PATCH and undo entry. Put back drains the queue first.
 */
import { flushPromises } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { fetchMock, jsonResponse, pageProps, requestLog, serverField } from './builder-store-fixtures';
import { useBuilderStore } from './useBuilderStore';
import type { ServerField } from './types';

/** Answers every PATCH with the row it was sent, under a fresh token. */
function echoServer(mock: ReturnType<typeof vi.fn>): void {
    let version = 1;
    mock.mockImplementation((_url: string, init?: RequestInit) => {
        const body = init?.body ? (JSON.parse(String(init.body)) as Partial<ServerField>) : {};
        version += 1;

        return Promise.resolve(jsonResponse(200, serverField({ ...body, version: `v${version}` } as Partial<ServerField>)));
    });
}

function patches(mock: ReturnType<typeof vi.fn>): Record<string, unknown>[] {
    return requestLog(mock).filter((r) => r.method === 'PATCH').map((r) => r.body as Record<string, unknown>);
}

afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
});

describe('Put back', () => {
    it('saves the earlier values as one PATCH naming the entry, and one undo entry that reverses it', async () => {
        const mock = fetchMock();
        echoServer(mock);
        const store = useBuilderStore(pageProps({ fields: [serverField({ label: 'Household size', hint: 'Everyone' })] }));
        const uid = store.fields.value[0].uid;

        const held = await store.putBack(uid, [
            { setting: 'label', before: 'Short text', after: 'Household size' },
            { setting: 'hint', before: null, after: 'Everyone' },
        ], 'entry-1');
        await flushPromises();

        expect(held).toBe(true);
        expect(patches(mock)).toHaveLength(1);
        expect(patches(mock)[0]).toMatchObject({ label: 'Short text', hint: null, put_back_of: 'entry-1' });
        expect(store.fields.value[0].label).toBe('Short text');

        await store.undo();
        await store.whenIdle();
        await flushPromises();

        expect(store.fields.value[0].label).toBe('Household size');
        expect(patches(mock)).toHaveLength(2);
        // The undo is an ordinary save: it names no entry.
        expect(patches(mock)[1]).not.toHaveProperty('put_back_of');
    });

    it('keeps an edit still waiting to be saved as its own save and its own undo entry', async () => {
        vi.useFakeTimers();
        const mock = fetchMock();
        echoServer(mock);
        const store = useBuilderStore(pageProps({ fields: [serverField({ label: 'Age', hint: null })] }));
        const field = store.fields.value[0];

        field.hint = 'In whole years';
        store.touch(field.uid, 'field'); // still debouncing when Put back is pressed
        const putting = store.putBack(field.uid, [{ setting: 'label', before: 'Short text', after: 'Age' }], 'entry-2');
        vi.useRealTimers();
        await putting;
        await flushPromises();

        expect(patches(mock).map((p) => [p.label, p.hint, p.put_back_of ?? null])).toEqual([
            ['Age', 'In whole years', null],
            ['Short text', 'In whole years', 'entry-2'],
        ]);

        await store.undo(); // the Put back only
        await store.whenIdle();
        await flushPromises();
        expect(store.fields.value[0].label).toBe('Age');
        expect(store.fields.value[0].hint).toBe('In whole years');
    });

    it('removes a config key the earlier question did not have', async () => {
        const mock = fetchMock();
        echoServer(mock);
        const store = useBuilderStore(pageProps({ fields: [serverField({ config: { max_length: 40 } })] }));
        const uid = store.fields.value[0].uid;

        await store.putBack(uid, [{ setting: 'config.max_length', before: null, after: 40 }], 'entry-3');
        await flushPromises();

        expect(patches(mock)[0].config).toEqual({});
    });
});
