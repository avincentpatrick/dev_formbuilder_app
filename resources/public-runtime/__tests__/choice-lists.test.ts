import { describe, expect, it, vi } from 'vitest';
import { createApiClient } from '../lib/api-client';
import {
    CHOICE_LIST_UNAVAILABLE,
    choiceListsCacheKey,
    choiceListsUrl,
    hasChoiceLists,
    isListBacked,
    mergeChoiceLists,
    type ChoiceLists,
} from '../lib/choice-lists';
import { buildEngineSchema } from '../lib/schema-mapping';
import { field, schemaResponse } from './fixtures';

/**
 * M141 (`R-f69aab42`, `D95`) — a cascade whose levels take their choices from CSV files gets its list beside the schema,
 * as tuples, merged into a COPY of the schema before the engine is built, and never breaks the form when it cannot load.
 */

const LEVELS = [
    { key: 'region', label: 'Region', list: 'regions' },
    { key: 'province', label: 'Province', list: 'provinces' },
];

function listSchema() {
    return schemaResponse({
        versionId: 'ver-7',
        fields: [
            field({ key: 'address', field_type: 'cascading_select', config: { levels: LEVELS, options: [] }, hint: 'Where you live.' }),
            field({ key: 'typed', field_type: 'cascading_select', config: { levels: [{ key: 'a', label: 'A' }], options: [{ level: 'a', value: 'x', label: 'X', parent: null }] } }),
        ],
    });
}

const LISTS: ChoiceLists = {
    address: {
        levels: ['region', 'province'],
        options: [
            [0, '01', 'Ilocos Region', null],
            [1, '0128', 'Ilocos Norte', '01'],
        ],
    },
};

function res(status: number, body: unknown): Response {
    return { ok: status >= 200 && status < 300, status, headers: new Headers(), json: async () => body, clone() { return this; } } as unknown as Response;
}

describe('choice lists — the read and its cache key', () => {
    it('reads by token and version, and caches by version alone', () => {
        expect(choiceListsUrl('t/1', 'ver-7')).toBe('/api/v1/public/choice-lists/t%2F1/ver-7');
        expect(choiceListsCacheKey('https://acme.test/api/v1/public/choice-lists/tok-a/ver-7')).toBe('https://acme.test/api/v1/public/choice-lists/ver-7');
        expect(choiceListsCacheKey('https://acme.test/api/v1/public/choice-lists/tok-b/ver-7')).toBe('https://acme.test/api/v1/public/choice-lists/ver-7');
        // Not a choice-list read, or not its shape: unchanged, so the plugin never merges unrelated requests.
        expect(choiceListsCacheKey('https://acme.test/api/v1/public/linked-choices/tok-a/ver-7')).toBe('https://acme.test/api/v1/public/linked-choices/tok-a/ver-7');
        expect(choiceListsCacheKey('https://acme.test/api/v1/public/choice-lists/ver-7')).toBe('https://acme.test/api/v1/public/choice-lists/ver-7');
    });

    it('calls a cascade list-backed only when every level names a list', () => {
        expect(isListBacked({ levels: LEVELS })).toBe(true);
        expect(isListBacked({ levels: [LEVELS[0], { key: 'province', label: 'Province' }] })).toBe(false);
        expect(isListBacked({ levels: [] })).toBe(false);
        expect(isListBacked(null)).toBe(false);
        expect(hasChoiceLists(listSchema())).toBe(true);
        expect(hasChoiceLists(schemaResponse({ fields: [field({ key: 'a' })] }))).toBe(false);
    });
});

describe('choice lists — merged into a copy of the schema', () => {
    it('expands each tuple into an ordinary cascade option, and leaves every other field and the original alone', () => {
        const schema = listSchema();
        const merged = mergeChoiceLists(schema, LISTS);

        expect(merged.version.schema.fields[0].config?.options).toEqual([
            { level: 'region', value: '01', label: 'Ilocos Region', parent: null },
            { level: 'province', value: '0128', label: 'Ilocos Norte', parent: '01' },
        ]);
        expect(merged.version.schema.fields[1]).toBe(schema.version.schema.fields[1]);
        expect(schema.version.schema.fields[0].config?.options).toEqual([]);
        // The engine reads the merged list, so it checks an answer against it on the device too.
        expect(buildEngineSchema(merged).fields.find((f) => f.key === 'address')?.cascade?.options).toHaveLength(2);
    });

    it('says why, and offers no choices, when the list could not be read', () => {
        for (const lists of [null, {}]) {
            const merged = mergeChoiceLists(listSchema(), lists);

            expect(merged.version.schema.fields[0].config?.options).toEqual([]);
            expect(merged.version.schema.fields[0].hint).toBe(`Where you live. ${CHOICE_LIST_UNAVAILABLE}`);
        }
    });
});

describe('choice lists — fetchSchema', () => {
    it('reads the lists after the schema, under the same token, and returns the merged copy', async () => {
        const fetchImpl = vi.fn(async (url: string) => (url.startsWith('/api/v1/public/f/') ? res(200, { data: listSchema() }) : res(200, { data: { version_id: 'ver-7', lists: LISTS } })));
        const client = createApiClient({ token: 't1', slug: 's', fetch: fetchImpl as unknown as typeof fetch });

        const schema = await client.fetchSchema();

        expect(fetchImpl).toHaveBeenNthCalledWith(2, '/api/v1/public/choice-lists/t1/ver-7', { headers: { Accept: 'application/json' } });
        expect(schema.version.schema.fields[0].config?.options).toHaveLength(2);
    });

    it('never asks for a form with no CSV-backed cascade', async () => {
        const fetchImpl = vi.fn(async () => res(200, { data: schemaResponse({ fields: [field({ key: 'a' })] }) }));
        const client = createApiClient({ token: 't1', slug: 's', fetch: fetchImpl as unknown as typeof fetch });

        await client.fetchSchema();

        expect(fetchImpl).toHaveBeenCalledTimes(1);
    });

    it('still returns the form when the list read fails or throws', async () => {
        for (const failing of [async () => res(404, { message: 'nope' }), async () => { throw new TypeError('offline'); }]) {
            const fetchImpl = vi.fn(async (url: string) => (url.startsWith('/api/v1/public/f/') ? res(200, { data: listSchema() }) : failing()));
            const client = createApiClient({ token: 't1', slug: 's', fetch: fetchImpl as unknown as typeof fetch });

            const schema = await client.fetchSchema();

            expect(schema.version.schema.fields[0].hint).toContain(CHOICE_LIST_UNAVAILABLE);
        }
    });
});
