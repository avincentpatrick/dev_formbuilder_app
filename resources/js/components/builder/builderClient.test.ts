/**
 * The builder's optimistic-concurrency contract, on the write path H21d2 puts a new editor on top of
 * (Doc #27 §8 and §9).
 *
 * §8 named `builderClient`'s typed 409 result as one of two contracts H21d2 must not break, and "any new
 * write endpoint is the obvious place to lose it". The answer this increment gives is that there IS no new
 * endpoint: the condition editor writes `relevant_expression` through `store.touch()` into the same
 * debounced `PATCH /forms/{form}/fields/{field}` every other config control uses. So what §9's "409 test on
 * the new write path" has to prove is not that a new surface reproduces the contract, but that the new
 * editor is genuinely ON the existing one — hence the second group below, which drives a condition edit
 * through the real store and asserts the conflict lands with both sides intact.
 *
 * The first group is the first JavaScript-side coverage `builderClient` has ever had. Until now the 409 was
 * pinned only server-side, by `tests/Feature/Forms/BuilderRoutesTest.php` — which cannot see that the client
 * RETURNS the conflict rather than throwing it, and a client that threw would surface as a generic
 * "Something went wrong saving your change." with the other side's row discarded.
 */

import { flushPromises } from '@vue/test-utils';
import { afterEach, beforeEach, describe as group, expect, it, vi } from 'vitest';

import { BuilderRequestError, builderClient } from './builderClient';
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

afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
    document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT';
});

group('builderClient surfaces a 409 as a value, never as a throw', () => {
    it('returns the conflict with the server’s current row', async () => {
        fetchMock().mockResolvedValue(
            jsonResponse(409, { message: 'This field changed somewhere else.', current: { id: 'f1', version: 'v2' } }),
        );

        const result = await builderClient.patch<{ id: string; version: string }>('/forms/x/fields/f1', { version: 'v1' });

        expect(result).toEqual({
            conflict: true,
            current: { id: 'f1', version: 'v2' },
            message: 'This field changed somewhere else.',
        });
    });

    it('throws a typed error for every OTHER failure, carrying the validation map', async () => {
        // The anti-vacuity twin: without it, a client that returned `{conflict: true}` for anything non-2xx
        // would satisfy the case above and silently swallow a 422.
        fetchMock().mockResolvedValue(jsonResponse(422, { message: 'The given data was invalid.', errors: { key: ['taken'] } }));

        await expect(builderClient.patch('/forms/x/fields/f1', {})).rejects.toThrow(BuilderRequestError);

        fetchMock().mockResolvedValue(jsonResponse(422, { message: 'The given data was invalid.', errors: { key: ['taken'] } }));
        const error = await builderClient.patch('/forms/x/fields/f1', {}).catch((e: unknown) => e);

        expect(error).toBeInstanceOf(BuilderRequestError);
        expect((error as BuilderRequestError).status).toBe(422);
        expect((error as BuilderRequestError).errors).toEqual({ key: ['taken'] });
    });

    it('keeps a generic message when the error body is not JSON (a 419 CSRF page)', async () => {
        fetchMock().mockResolvedValue({
            status: 419,
            ok: false,
            json: () => Promise.reject(new Error('not json')),
        } as unknown as Response);

        const error = await builderClient.patch('/forms/x/fields/f1', {}).catch((e: unknown) => e);

        expect((error as BuilderRequestError).message).toBe('Request failed (419).');
    });

    it('sends the CSRF token, the credentials and the JSON accept header Laravel’s web group expects', async () => {
        document.cookie = 'XSRF-TOKEN=tok%3Den';
        const mock = fetchMock().mockResolvedValue(jsonResponse(200, { id: 'f1' }));

        await builderClient.patch('/forms/x/fields/f1', { a: 1 });

        const [url, init] = mock.mock.calls[0] as [string, RequestInit];
        expect(url).toBe('/forms/x/fields/f1');
        expect(init.method).toBe('PATCH');
        expect(init.credentials).toBe('same-origin');
        expect(init.body).toBe('{"a":1}');
        expect(init.headers).toMatchObject({
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': 'tok=en',
        });
    });

    it('reads a 204 as success with no body', async () => {
        fetchMock().mockResolvedValue({ status: 204, ok: true, json: () => Promise.reject(new Error('no body')) } as unknown as Response);

        await expect(builderClient.delete('/forms/x/fields/f1')).resolves.toEqual({ conflict: false, data: undefined });
    });
});

// ── the condition write, through the real store ─────────────────────────────────────────────────────

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
    };
}

/** What `ConfigPanel`'s `setField`/`setSection` do: mutate the local row, then tell the store. */
async function writeCondition(store: ReturnType<typeof useBuilderStore>, kind: 'field' | 'section', text: string): Promise<void> {
    const target = kind === 'field' ? store.fields.value[0] : store.sections.value[0];
    target.relevant_expression = text;
    store.touch(target.uid, kind);

    await store.whenIdle();
    await flushPromises();
}

group('a condition edit rides the existing optimistic-concurrency path', () => {
    beforeEach(() => {
        vi.useFakeTimers();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('sends the row’s version token with the condition, and adopts the new one on success', async () => {
        const mock = fetchMock().mockResolvedValue(
            jsonResponse(200, serverField({ relevant_expression: '${age} > 18', version: 'v2' })),
        );
        const store = useBuilderStore(pageProps());

        await writeCondition(store, 'field', '${age} > 18');

        const [url, init] = mock.mock.calls[0] as [string, RequestInit];
        expect(url).toBe('/forms/form-1/fields/f1');
        expect(JSON.parse(init.body as string)).toMatchObject({ relevant_expression: '${age} > 18', version: 'v1' });
        expect(store.conflict.value).toBeNull();
        expect(store.fields.value[0].version).toBe('v2');
    });

    it('routes a 409 to the conflict dialog with BOTH sides of the condition', async () => {
        fetchMock().mockResolvedValue(
            jsonResponse(409, {
                message: 'This field was changed somewhere else.',
                current: serverField({ relevant_expression: "${tier} = 'gold'", version: 'v9' }),
            }),
        );
        const store = useBuilderStore(pageProps());

        await writeCondition(store, 'field', '${age} > 18');

        expect(store.conflict.value?.kind).toBe('field');
        // The in-flight edit is never silently discarded — that is the whole point of the typed result.
        expect((store.conflict.value?.mine as ServerField).relevant_expression).toBe('${age} > 18');
        expect((store.conflict.value?.theirs as ServerField).relevant_expression).toBe("${tier} = 'gold'");
        // …and the stale token is NOT adopted, or the next retry would overwrite the other author blind.
        expect(store.fields.value[0].version).toBe('v1');
    });

    it('does the same for a SECTION condition, which is the other half of the editor’s surface', async () => {
        fetchMock().mockResolvedValue(
            jsonResponse(409, {
                message: 'This section was changed somewhere else.',
                current: serverSection({ relevant_expression: 'count(${roster}) > 0', version: 'v9' }),
            }),
        );
        const store = useBuilderStore(pageProps());

        await writeCondition(store, 'section', "${tier} = 'gold'");

        expect(store.conflict.value?.kind).toBe('section');
        expect((store.conflict.value?.mine as ServerSection).relevant_expression).toBe("${tier} = 'gold'");
        expect((store.conflict.value?.theirs as ServerSection).relevant_expression).toBe('count(${roster}) > 0');
    });

    it('surfaces a non-409 failure as a save error rather than a conflict', async () => {
        fetchMock().mockResolvedValue(jsonResponse(422, { message: 'The relevant expression may not be greater than 2000 characters.' }));
        const store = useBuilderStore(pageProps());

        await writeCondition(store, 'field', '${age} > 18');

        expect(store.conflict.value).toBeNull();
        expect(store.saveError.value).toBe('The relevant expression may not be greater than 2000 characters.');
    });

    it('writes ONCE for a burst of edits, so a condition built row by row is one PATCH and one undo entry', async () => {
        const mock = fetchMock().mockResolvedValue(jsonResponse(200, serverField({ version: 'v2' })));
        const store = useBuilderStore(pageProps());
        const field = store.fields.value[0];

        field.relevant_expression = '${age} > 18';
        store.touch(field.uid, 'field');
        field.relevant_expression = "${age} > 18 and ${tier} = 'gold'";
        store.touch(field.uid, 'field');

        await store.whenIdle();
        await flushPromises();

        expect(mock).toHaveBeenCalledTimes(1);
        expect(JSON.parse((mock.mock.calls[0] as [string, RequestInit])[1].body as string).relevant_expression).toBe(
            "${age} > 18 and ${tier} = 'gold'",
        );
        expect(store.canUndo.value).toBe(true);
    });

    it('records no PATCH at all when the condition ends where it started', async () => {
        // The editor's own idempotence guard means it never emits a no-op; this is the store's independent
        // one, and it is why re-selecting a row cannot burn a version bump.
        const mock = fetchMock().mockResolvedValue(jsonResponse(200, serverField()));
        const store = useBuilderStore(pageProps());

        await writeCondition(store, 'field', null as unknown as string);

        expect(mock).not.toHaveBeenCalled();
    });
});
