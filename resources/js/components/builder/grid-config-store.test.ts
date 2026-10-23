/**
 * M185 (`R-7418e6bd`) — the store's one config write, `setFieldConfig`, which the settings pane's grid lists and the
 * builder's Preview both use: the pane's edits ride the 600 ms debounce, the Preview's are saved now; either way one PATCH
 * and one undo entry, and Undo puts the grid back. Driven through the real store with `fetch` stubbed, as
 * `save-state.test.ts` drives it.
 */
import { flushPromises } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { addGridEntry } from './grid-options';
import { useBuilderStore } from './useBuilderStore';
import type { BuilderPageProps, ServerField } from './types';

const ROWS = [{ value: 'row_1', label: 'Row 1' }];

function jsonResponse(status: number, body: unknown): Response {
    return { status, ok: status >= 200 && status < 300, json: () => Promise.resolve(body) } as unknown as Response;
}

function grid(overrides: Partial<ServerField> = {}): ServerField {
    return {
        id: 'f1',
        form_section_id: 's1',
        key: 'check',
        field_type: 'matrix',
        label: 'Facility check',
        hint: null,
        placeholder: null,
        is_required: 'optional',
        relevant_expression: null,
        appearance: null,
        config: { rows: ROWS, columns: [{ value: 'col_1', label: 'Column 1' }], cells: [{ value: 'y', label: 'Yes' }] },
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

function pageProps(): BuilderPageProps {
    return {
        form: {
            id: 'form-1',
            title: 'Facility Check',
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
        sections: [{ id: 's1', key: 'main', label: 'Main', description: null, is_repeatable: false, min_instances: null, max_instances: null, relevant_expression: null, sequence: 1, version: 'v1' }],
        fields: [grid()],
        palette: [],
        enums: { required_modes: [], indexed_data_types: [], validation_rule_types: [], comparison_operators: [] },
        library: [],
        timezones: ['UTC'],
        crumbs: [],
    };
}

function patches(mock: ReturnType<typeof vi.fn>): Record<string, unknown>[] {
    return mock.mock.calls
        .filter(([, init]) => (init as RequestInit | undefined)?.method === 'PATCH')
        .map(([, init]) => JSON.parse(String((init as RequestInit).body)) as Record<string, unknown>);
}

afterEach(() => {
    vi.unstubAllGlobals();
    vi.useRealTimers();
});

describe('setFieldConfig — one write for the Grid tab and the Preview (M185)', () => {
    it('saves a Preview edit at once: one PATCH carrying the new row, one undo entry, and Undo puts the rows back', async () => {
        const mock = vi.fn((_url: string, init?: RequestInit) => {
            const body = JSON.parse(String(init?.body ?? '{}')) as Partial<ServerField>;

            return Promise.resolve(jsonResponse(200, grid({ config: body.config as Record<string, unknown>, version: 'v2' })));
        });
        vi.stubGlobal('fetch', mock);
        const store = useBuilderStore(pageProps());
        const field = store.fields.value[0];

        // ⛔ THE CLOCK IS FROZEN: `whenIdle()` waits out the debounce, so only a stopped clock tells "saved now" from "saved
        // once the 600 ms run out" — measured: a mutant that dropped the flush passed while this read `whenIdle()` first.
        vi.useFakeTimers();
        store.setFieldConfig(field.uid, { rows: addGridEntry(ROWS, 'row', 'Row') }, { now: true });
        await flushPromises();
        expect(patches(mock)).toHaveLength(1);
        vi.useRealTimers();
        await store.whenIdle();
        await flushPromises();

        expect(patches(mock)).toHaveLength(1);
        expect((patches(mock)[0].config as Record<string, unknown>).rows).toEqual([...ROWS, { value: 'row_2', label: 'Row 2' }]);
        expect(store.canUndo.value).toBe(true);

        await store.undo();
        await store.whenIdle();
        await flushPromises();

        expect(store.fields.value[0].config.rows).toEqual(ROWS);
        expect(patches(mock)).toHaveLength(2);
    });

    it('rides the debounce for the settings pane: a burst of edits is one PATCH, sent only once the burst ends', async () => {
        vi.useFakeTimers();
        const mock = vi.fn(() => Promise.resolve(jsonResponse(200, grid({ version: 'v2' }))));
        vi.stubGlobal('fetch', mock);
        const store = useBuilderStore(pageProps());
        const uid = store.fields.value[0].uid;

        store.setFieldConfig(uid, { rows: [{ value: 'row_1', label: 'W' }] });
        store.setFieldConfig(uid, { rows: [{ value: 'row_1', label: 'Ward' }] });
        await flushPromises();
        expect(patches(mock)).toHaveLength(0);

        vi.advanceTimersByTime(600);
        await flushPromises();
        expect(patches(mock)).toHaveLength(1);
        expect((patches(mock)[0].config as Record<string, unknown>).rows).toEqual([{ value: 'row_1', label: 'Ward' }]);
    });
});
