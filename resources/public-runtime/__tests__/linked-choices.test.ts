import { describe, expect, it, vi } from 'vitest';
import { createApiClient } from '../lib/api-client';
import {
    LINKED_CHOICES_EMPTY,
    LINKED_CHOICES_UNAVAILABLE,
    hasLinkedChoices,
    linkedChoicesCacheKey,
    linkedChoicesUrl,
    mergeLinkedChoices,
    type LinkedChoiceLists,
} from '../lib/linked-choices';
import { buildEngineSchema } from '../lib/schema-mapping';
import { field, schemaResponse } from './fixtures';

/**
 * M133 (`R-5da4a30f`, `D60` = A) — the choices a form takes from another form's answers reach the guest page beside the
 * schema, are merged into a COPY of it before the engine is built, and never break the form when they cannot load.
 */

const LINK = { options_source: { form_id: 'src-1', field_key: 'facility_name' }, options: [] };

function linkedSchema() {
    return schemaResponse({
        versionId: 'ver-7',
        fields: [
            field({ key: 'facility', field_type: 'dropdown', config: { ...LINK }, hint: 'Where you were seen.' }),
            field({ key: 'district', field_type: 'single_select', config: { options: [{ value: 'n', label: 'North' }] } }),
        ],
    });
}

const LISTS: LinkedChoiceLists = {
    facility: {
        stamp: 'abc',
        available: true,
        truncated: false,
        options: [
            { value: 'Mabini RHU', label: 'Mabini RHU' },
            { value: 'San Jose RHU', label: 'San Jose RHU' },
        ],
    },
};

function res(status: number, body: unknown): Response {
    return { ok: status >= 200 && status < 300, status, headers: new Headers(), json: async () => body, clone() { return this; } } as unknown as Response;
}

describe('linked choices — the read and its cache key', () => {
    it('reads one version under the current token, off the schema prefix', () => {
        expect(linkedChoicesUrl('tok a', 'ver-7')).toBe('/api/v1/public/linked-choices/tok%20a/ver-7');
    });

    it('keys a cached list by version with the token stripped, and leaves any other URL alone', () => {
        expect(linkedChoicesCacheKey('https://acme.test/api/v1/public/linked-choices/token-a/ver-7')).toBe('https://acme.test/api/v1/public/linked-choices/ver-7');
        expect(linkedChoicesCacheKey('https://acme.test/api/v1/public/f/token-a')).toBe('https://acme.test/api/v1/public/f/token-a');
        expect(linkedChoicesCacheKey('https://acme.test/api/v1/public/linked-choices/only-one')).toBe('https://acme.test/api/v1/public/linked-choices/only-one');
    });
});

describe('linked choices — merged into a copy of the schema', () => {
    it('only asks when a question takes its choices from another form', () => {
        expect(hasLinkedChoices(linkedSchema())).toBe(true);
        expect(hasLinkedChoices(schemaResponse({ fields: [field({ key: 'a', field_type: 'dropdown', config: { options: [] } })] }))).toBe(false);
        expect(hasLinkedChoices(schemaResponse({ fields: [field({ key: 'a', field_type: 'dropdown', config: { options_source: null } })] }))).toBe(false);
    });

    it('gives the linked question its list as choices, the engine included, and leaves everything else and the original alone', () => {
        const schema = linkedSchema();
        const merged = mergeLinkedChoices(schema, LISTS);
        const [facility, district] = merged.version.schema.fields;

        expect(facility.config?.options).toEqual(LISTS.facility.options);
        expect(facility.hint).toBe('Where you were seen.');
        expect(district).toBe(schema.version.schema.fields[1]);
        // The cached response is never mutated: an offline reload merges again from what the worker kept.
        expect(schema.version.schema.fields[0].config?.options).toEqual([]);
        // ONE list for controls and engine: the engine schema is built from the merged copy.
        expect(buildEngineSchema(merged).fields.find((f) => f.key === 'facility')?.options).toEqual(['Mabini RHU', 'San Jose RHU']);
    });

    it('says the choices are not available when the read failed or the source stopped sharing, with no choices', () => {
        for (const lists of [null, {}, { facility: { ...LISTS.facility, available: false, options: [] } }]) {
            const facility = mergeLinkedChoices(linkedSchema(), lists).version.schema.fields[0];

            expect(facility.config?.options).toEqual([]);
            expect(facility.hint).toBe(`Where you were seen. ${LINKED_CHOICES_UNAVAILABLE}`);
        }
    });

    it('says there is nothing to pick yet when the list loaded empty', () => {
        const facility = mergeLinkedChoices(linkedSchema(), { facility: { ...LISTS.facility, options: [] } }).version.schema.fields[0];

        expect(facility.hint).toBe(`Where you were seen. ${LINKED_CHOICES_EMPTY}`);
    });
});

describe('linked choices — fetchSchema', () => {
    it('reads the lists after the schema, under the same token, and returns the merged copy', async () => {
        const fetchImpl = vi.fn(async (url: string) => (url.startsWith('/api/v1/public/f/') ? res(200, { data: linkedSchema() }) : res(200, { data: { version_id: 'ver-7', lists: LISTS } })));
        const client = createApiClient({ token: 't1', slug: 's', fetch: fetchImpl as unknown as typeof fetch });

        const schema = await client.fetchSchema();

        expect(fetchImpl).toHaveBeenNthCalledWith(2, '/api/v1/public/linked-choices/t1/ver-7', { headers: { Accept: 'application/json' } });
        expect(schema.version.schema.fields[0].config?.options).toEqual(LISTS.facility.options);
    });

    it('never asks for a form with no linked question', async () => {
        const fetchImpl = vi.fn(async () => res(200, { data: schemaResponse({ fields: [field({ key: 'a' })] }) }));
        const client = createApiClient({ token: 't1', slug: 's', fetch: fetchImpl as unknown as typeof fetch });

        await client.fetchSchema();

        expect(fetchImpl).toHaveBeenCalledTimes(1);
    });

    it('still returns the form when the list read fails or throws', async () => {
        for (const failing of [async () => res(404, { message: 'nope' }), async () => { throw new TypeError('offline'); }]) {
            const fetchImpl = vi.fn(async (url: string) => (url.startsWith('/api/v1/public/f/') ? res(200, { data: linkedSchema() }) : failing()));
            const client = createApiClient({ token: 't1', slug: 's', fetch: fetchImpl as unknown as typeof fetch });

            const schema = await client.fetchSchema();

            expect(schema.version.schema.fields[0].hint).toContain(LINKED_CHOICES_UNAVAILABLE);
        }
    });
});
