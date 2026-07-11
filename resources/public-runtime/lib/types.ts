/**
 * Public-runtime SPA types (Increment F6b). Three layers:
 *  1. RAW — the exact JSON the F5 backend returns (the mint dataset + the `/api/v1/public` schema envelope,
 *     whose `version.schema` is the id-free, FK-by-key `schema_snapshot`).
 *  2. RENDER — the UI-facing model the components walk (labels/translations/options/control kind). The F6a
 *     engine's `SchemaField` deliberately omits all of this (it only needs relevance/validation columns), so
 *     the render model is a strict superset built alongside the `SemanticInput` in `schema-mapping.ts`.
 *  3. RUNTIME — bootstrap, normalized errors, and the localStorage draft blob.
 */

import type { EngineValue, InstanceAnswers, RequiredMode } from '../engine';

// Re-exported so runtime/lib modules can pull the answer-value type from one place alongside the SPA types.
export type { EngineValue, InstanceAnswers } from '../engine';

/**
 * The runtime answer map (Increment G2). A flat field key maps to a scalar {@link EngineValue}; a repeatable
 * section key maps to a list of per-instance answer maps ({@link InstanceAnswers}[]) — the exact nested shape
 * the G1 pipeline persists and the F6a engine consumes.
 */
export type AnswerMap = Record<string, EngineValue | InstanceAnswers[]>;

// ── 1. RAW (wire) ────────────────────────────────────────────────────────────────────────────

/** A single author-defined choice option inside `config.options` (forward-compatible translations). */
export interface RawOption {
    value: string | number;
    label?: string;
    label_translations?: Record<string, string> | null;
}

export interface RawFieldConfig {
    options?: RawOption[];
    [key: string]: unknown;
}

export interface RawValidation {
    rule_type: string | null;
    operator: string | null;
    rule_value: string | number | null;
    expression: string | null;
    error_message: string | null;
    error_message_translations: Record<string, string> | null;
    related_field_key: string | null;
    logic_group_ordinal: number | null;
    logic_operator: string | null;
    sequence: number;
}

export interface RawField {
    key: string;
    section_key: string | null;
    field_type: string;
    config: RawFieldConfig | null;
    label: string;
    label_translations: Record<string, string> | null;
    hint: string | null;
    hint_translations: Record<string, string> | null;
    placeholder: string | null;
    default_value: string | null;
    default_value_is_expression: boolean;
    is_required: RequiredMode;
    relevant_expression: string | null;
    appearance: string | null;
    sequence: number;
    section_sequence: number | null;
    validations: RawValidation[];
}

export interface RawSection {
    key: string;
    label: string;
    label_translations: Record<string, string> | null;
    description: string | null;
    description_translations: Record<string, string> | null;
    sequence: number;
    is_repeatable: boolean;
    /** Repeat-group instance bounds (Increment G1/G2). Null = unbounded on that side. */
    min_instances: number | null;
    max_instances: number | null;
    relevant_expression: string | null;
}

export interface RawSchemaSnapshot {
    sections: RawSection[];
    fields: RawField[];
}

export interface SchemaResponse {
    form: {
        id: string;
        title: string;
        description: string | null;
        default_locale: string;
        supported_locales: string[];
        single_page_mode: boolean;
    };
    version: {
        id: string;
        version_number: number;
        checksum: string;
        schema: RawSchemaSnapshot;
    };
}

/** The mint JSON re-fetched by the SPA (`Accept: application/json` on `GET /f/{slug}`). */
export interface MintResponse {
    shareToken: string;
    expiresAt: string;
    form: { id: string; title: string };
}

// ── 2. RENDER ────────────────────────────────────────────────────────────────────────────────

/** The control kinds the reused F4b `FieldInput.vue` renders (derived exactly as its own `control` computed). */
export type ControlKind =
    | 'text'
    | 'textarea'
    | 'number'
    | 'select'
    | 'checkboxes'
    | 'yesno'
    | 'note'
    | 'unsupported';

export interface RenderOption {
    value: string;
    label: string;
    labelTranslations: Record<string, string> | null;
}

export interface RenderField {
    key: string;
    sectionKey: string | null;
    fieldType: string;
    control: ControlKind;
    supported: boolean;
    isRequired: RequiredMode;
    /** True when a validation rule (`required_if`/`required_with`) can make this field conditionally required. */
    hasConditionalRequirement: boolean;
    label: string;
    labelTranslations: Record<string, string> | null;
    hint: string | null;
    hintTranslations: Record<string, string> | null;
    placeholder: string | null;
    options: RenderOption[];
    sequence: number;
    sectionSequence: number | null;
}

export interface RenderSection {
    key: string;
    label: string;
    labelTranslations: Record<string, string> | null;
    description: string | null;
    descriptionTranslations: Record<string, string> | null;
    sequence: number;
    isRepeatable: boolean;
    /** Repeat-group instance bounds (Increment G2). Null = unbounded on that side. */
    minInstances: number | null;
    maxInstances: number | null;
    relevantExpression: string | null;
}

export interface RenderModel {
    form: SchemaResponse['form'];
    sections: RenderSection[];
    fields: RenderField[];
}

// ── 3. RUNTIME ───────────────────────────────────────────────────────────────────────────────

/** Read once from the `#app` mount node's dataset (see `public-runtime.blade.php`). */
export interface Bootstrap {
    shareToken: string;
    expiresAt: string;
    formId: string;
    formTitle: string;
    slug: string;
    defaultLocale: string;
}

/** How the SPA should react to a normalized API error. */
export type ErrorKind =
    | 'field' // 422 — map onto fields
    | 'remint' // token expired — silently re-mint + retry, same schema
    | 'refresh' // version superseded — re-mint + re-fetch schema
    | 'rate_limited' // 429 — back off
    | 'terminal' // 401 invalid / 403 disabled / 404 — unrecoverable
    | 'unknown';

export interface NormalizedError {
    httpStatus: number;
    code: string;
    message: string;
    /** Field key → messages, already stripped of any `answers.` request-validation prefix. */
    fieldErrors: Record<string, string[]>;
    kind: ErrorKind;
    retryAfterSeconds: number | null;
}

/** The client-only autosave payload (localStorage; device-scoped, UX §5.1). */
export interface DraftBlob {
    checksum: string;
    locale: string;
    currentStepKey: string;
    answers: AnswerMap;
    savedAt: string;
}

export interface SubmitResult {
    id: string;
    status: string;
    created: boolean;
}
