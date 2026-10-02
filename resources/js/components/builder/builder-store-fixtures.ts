/**
 * The builder store's test fixtures — promoted to a shared module by their THIRD caller (Increment M123), which is
 * the rule `save-state.test.ts` wrote down when it took the second copy.
 *
 * ⚠️ THIS FILE IS TYPE-CHECKED AND THE TEST FILES ARE NOT. `tsconfig.json` excludes every `*.test.ts`, which is how a
 * fixture there can silently lack a member the real props carry. A non-test module is inside the program, so the
 * page props built here are complete — `single_page_mode`, `share` and all — or `vue-tsc` refuses the build.
 *
 * ⚠️ THE TWO OLDER COPIES ARE NOT YET MIGRATED, DELIBERATELY. The ledger cites lines inside `save-state.test.ts` and
 * `builderClient.test.ts` below their fixture blocks, and deleting those blocks would move every one of them while the
 * citation ceiling sits at its limit. The migration is its own filed row.
 */
import { vi } from 'vitest';
import type { BuilderEnums, BuilderPageProps, PaletteGroup, ServerField, ServerSection } from './types';
import type { ConversionPlan } from './useBuilderStore';

export function jsonResponse(status: number, body: unknown): Response {
    return {
        status,
        ok: status >= 200 && status < 300,
        json: () => Promise.resolve(body),
    } as unknown as Response;
}

/** Stubs the global `fetch` the builder client calls, and returns the mock so a test can queue responses. */
export function fetchMock(): ReturnType<typeof vi.fn> {
    const mock = vi.fn();
    vi.stubGlobal('fetch', mock);

    return mock;
}

export interface LoggedRequest {
    method: string;
    url: string;
    body: unknown;
}

/** Every request the store sent, in order — so a test asserts the exact sequence and bodies, not a count. */
export function requestLog(mock: ReturnType<typeof vi.fn>): LoggedRequest[] {
    return mock.mock.calls.map((call) => {
        const [url, init] = call as [string, RequestInit | undefined];
        const raw = init?.body;

        return {
            method: init?.method ?? 'GET',
            url,
            body: typeof raw === 'string' ? (JSON.parse(raw) as unknown) : undefined,
        };
    });
}

export function serverField(overrides: Partial<ServerField> = {}): ServerField {
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

export function serverSection(overrides: Partial<ServerSection> = {}): ServerSection {
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

/**
 * The palette entries these tests convert between. `note` carries `no_answer` and `hidden` carries the `prefill`
 * editor, because those two TRANSMITTED facts — not a type list — are what put a target under "Not asked".
 */
// M125 — the variant groups as BuilderPresenter transmits them: one "Text" and one "Number" in the palette.
const TEXT_PRIMARY = { group: 'text', label: 'Text', primary: true };
const TEXT_OTHER = { group: 'text', label: 'Text', primary: false };
const NUMBER_PRIMARY = { group: 'number', label: 'Number', primary: true };
const NUMBER_OTHER = { group: 'number', label: 'Number', primary: false };

export const PALETTE: PaletteGroup[] = [
    {
        category: 'text',
        label: 'Text',
        icon: 'type',
        types: [
            { value: 'short_text', label: 'Short text', advanced: false, has_options: false, config_editor: null, value_shape: 'text', variant: TEXT_PRIMARY },
            { value: 'long_text', label: 'Long text', advanced: false, has_options: false, config_editor: null, value_shape: 'text', variant: TEXT_OTHER },
            { value: 'email', label: 'Email', advanced: false, has_options: false, config_editor: null, value_shape: 'text' },
            { value: 'phone', label: 'Phone', advanced: false, has_options: false, config_editor: null, value_shape: 'text' },
        ],
    },
    {
        category: 'number',
        label: 'Number',
        icon: 'hash',
        types: [
            { value: 'integer', label: 'Whole number', advanced: false, has_options: false, config_editor: null, value_shape: 'number', variant: NUMBER_PRIMARY },
            { value: 'decimal', label: 'Decimal number', advanced: false, has_options: false, config_editor: null, value_shape: 'number', variant: NUMBER_OTHER },
            { value: 'calculated', label: 'Calculated', advanced: true, has_options: false, config_editor: null, value_shape: 'number' },
        ],
    },
    {
        category: 'layout',
        label: 'Layout',
        icon: 'layout',
        types: [
            { value: 'note', label: 'Note / label', advanced: false, has_options: false, config_editor: null, value_shape: 'no_answer' },
            { value: 'hidden', label: 'Hidden field', advanced: true, has_options: false, config_editor: 'prefill', value_shape: 'text' },
            { value: 'page_break', label: 'Page break', advanced: false, has_options: false, config_editor: null, value_shape: 'no_answer' },
        ],
    },
];

export const ENUMS: BuilderEnums = {
    required_modes: [
        { value: 'optional', label: 'Optional' },
        { value: 'required', label: 'Required' },
        { value: 'conditional', label: 'Conditional' },
    ],
    indexed_data_types: [
        { value: 'text', label: 'Text' },
        { value: 'number', label: 'Number' },
    ],
    validation_rule_types: [
        { value: 'pattern', label: 'Must match a pattern', shapes: ['text'], takes_operator: false, takes_related_field: false, operator_may_be_empty: false, governs_requiredness: false },
        { value: 'min_length', label: 'Minimum length', shapes: ['text'], takes_operator: false, takes_related_field: false, operator_may_be_empty: false, governs_requiredness: false },
        { value: 'required_if', label: 'Required when a condition holds', shapes: ['text', 'number'], takes_operator: true, takes_related_field: true, operator_may_be_empty: false, governs_requiredness: true },
    ],
    comparison_operators: [{ value: 'eq', label: 'equals (=)', shapes: ['text', 'number'] }],
};

export function pageProps(overrides: Partial<BuilderPageProps> = {}): BuilderPageProps {
    return {
        form: {
            id: 'form-1',
            title: 'Branching Router',
            description: null,
            status: 'draft',
            save_and_resume: false,
            single_page_mode: false,
            opens_at: null,
            closes_at: null,
            timezone: 'UTC',
            max_responses: null,
            confirmation_message: null,
            confirmation_message_translations: {},
            default_locale: 'en',
            supported_locales: ['en'],
        },
        share: {
            public_slug: null,
            allow_guest_submissions: false,
            bot_challenge: 'off',
            guest_rate_limit_per_minute: null,
            suggested_slug: 'branching-router',
            is_published: false,
            public_host: 'acme.meridian.test',
            public_url: null,
        },
        draft: { id: 'v-1', version_number: 1 },
        sections: [serverSection()],
        fields: [serverField()],
        palette: PALETTE,
        enums: ENUMS,
        library: [],
        timezones: ['UTC'],
        crumbs: [],
        ...overrides,
    };
}

/** One plan as `GET …/conversions` returns it. Defaults to a lossless short → long text plan with nothing to show. */
export function conversionPlan(overrides: Partial<ConversionPlan> = {}): ConversionPlan {
    return {
        from: 'short_text',
        to: 'long_text',
        lossless: true,
        requires_confirmation: false,
        fingerprint: 'a'.repeat(64),
        config_dropped: [],
        changes: [],
        kept: [],
        dropped: [],
        added: [],
        warnings: [],
        census: [],
        ...overrides,
    };
}
