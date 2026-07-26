/**
 * Public-runtime SPA types (Increment F6b). Three layers:
 *  1. RAW — the exact JSON the F5 backend returns (the mint dataset + the `/api/v1/public` schema envelope,
 *     whose `version.schema` is the id-free, FK-by-key `schema_snapshot`).
 *  2. RENDER — the UI-facing model the components walk (labels/translations/options/control kind). The F6a
 *     engine's `SchemaField` deliberately omits all of this (it only needs relevance/validation columns), so
 *     the render model is a strict superset built alongside the `SemanticInput` in `schema-mapping.ts`.
 *  3. RUNTIME — bootstrap, normalized errors, and the localStorage draft blob.
 */

import type { CompositeAnswer, EngineValue, GeoAnswer, InstanceAnswers, MediaAnswer, RequiredMode } from '../engine';

// Re-exported so runtime/lib modules can pull the answer-value type from one place alongside the SPA types.
export type { EngineValue, InstanceAnswers, CompositeAnswer, GeoAnswer, MediaAnswer } from '../engine';

/**
 * The runtime answer map (Increment G2/G4b/G5b2). A flat field key maps to a scalar {@link EngineValue}; a
 * repeatable section key maps to a list of per-instance answer maps ({@link InstanceAnswers}[]); a composite
 * grid field key (matrix/likert_matrix) maps to its object-valued answer ({@link CompositeAnswer}); a geo
 * field key maps to its GeoJSON envelope ({@link GeoAnswer}); a media field key maps to its AttachmentRef
 * list ({@link MediaAnswer}) — the exact nested shapes the G1/G4b/G5b1/G6 pipeline persists and the F6a
 * engine consumes.
 */
export type AnswerMap = Record<string, EngineValue | InstanceAnswers[] | CompositeAnswer | GeoAnswer | MediaAnswer>;

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

/** The live scheduled-form label the runtime branches on (Increment H12a backend; consumed by H12b). */
export type ScheduleAcceptance = 'open' | 'opens_soon' | 'closed' | 'capacity_reached';

/**
 * The scheduled-form window + response cap block (Increment H12a `PublicFormPresenter.form.schedule`).
 * `acceptance` is computed SERVER-SIDE at load; `remaining` is cap headroom (null when uncapped). Enforcement
 * is authoritative in the write path — the block is advisory for rendering the closed/opens-soon/full states.
 */
export interface ScheduleBlock {
    opens_at: string | null;
    closes_at: string | null;
    timezone: string;
    max_responses: number | null;
    acceptance: ScheduleAcceptance;
    remaining: number | null;
}

export interface SchemaResponse {
    form: {
        id: string;
        title: string;
        description: string | null;
        default_locale: string;
        supported_locales: string[];
        single_page_mode: boolean;
        // Increment H10 — whether the SPA should offer "Save and finish later" (per-form opt-in AND tenant
        // plan; the backend PublicFormPresenter ANDs both). Older cached manifests may omit it → treat as false.
        save_and_resume?: boolean;
        // Increment H12a/b — the schedule window + response cap. Optional: an older cached manifest may omit
        // it, which the runtime treats as an unconstrained (always-open) form.
        schedule?: ScheduleBlock;
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
    // Increment G4a: a single-choice rating scale (radio group) + an N-level dependent select.
    | 'scale'
    | 'cascading'
    // Increment G4b: the object-valued grids — a likert grid (radio-group per row) + a full matrix (per-cell select).
    | 'likert_matrix'
    | 'matrix'
    // Increment G5b2: geospatial capture (geopoint / geotrace / geoshape) — a coordinate/vertex control + map.
    | 'geo'
    // Increment G6: media capture (file / image / audio / video) — a file input + progressive-enhancement capture.
    | 'media'
    | 'note'
    | 'unsupported';

export interface RenderOption {
    value: string;
    label: string;
    labelTranslations: Record<string, string> | null;
}

/** One cascading-select level's label (Increment G4a); translations resolved by the render layer. */
export interface RenderCascadeLevel {
    key: string;
    label: string;
    labelTranslations: Record<string, string> | null;
}

/** One cascading-select option, tagged with its owning level + parent value (Increment G4a). */
export interface RenderCascadeOption {
    value: string;
    label: string;
    labelTranslations: Record<string, string> | null;
    level: string;
    parent: string | null;
}

export interface RenderCascade {
    levels: RenderCascadeLevel[];
    options: RenderCascadeOption[];
}

/**
 * Composite grid render config (Increment G4b): the rows + columns (and, for `matrix`, the shared per-cell
 * choice pool `cells`); translations resolved by the render layer. `cells` is empty for `likert_matrix`
 * (its per-row choice IS the column scale). Reuses {@link RenderOption} for each value/label entry.
 */
export interface RenderMatrix {
    rows: RenderOption[];
    columns: RenderOption[];
    cells: RenderOption[];
}

/**
 * Geo capture config (Increment G5b2): author options for the geopoint/geotrace/geoshape control. Labels-free
 * (no translation resolution). Mirrors the `GeoFieldConfig` the shared `FieldInput.vue` control reads.
 */
export interface RenderGeo {
    captureAltitude: boolean;
    accuracyThreshold: number | null;
    defaultCenter: { lat: number; lon: number } | null;
    defaultZoom: number | null;
}

/**
 * Media capture config (Increment G6): author options for the file/image/audio/video control. Mirrors the
 * `MediaFieldConfig` the shared `FieldInput.vue` control reads (camelCase). `acceptedTypes`/`maxFileSizeBytes`/
 * `captureSource` drive upload-time behaviour (client reject + `accept`/`capture` attrs); `maxCount`/`minCount`
 * also feed the engine's count check via `SchemaField.media`.
 */
export interface RenderMedia {
    acceptedTypes: string[];
    maxFileSizeBytes: number | null;
    maxCount: number | null;
    minCount: number | null;
    captureSource: string | null;
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
    /** Cascading-select hierarchy (Increment G4a); null for every other field type. */
    cascade: RenderCascade | null;
    /** Composite grid config (Increment G4b: matrix / likert_matrix); null for every other field type. */
    matrix: RenderMatrix | null;
    /** Geo capture config (Increment G5b2: geopoint / geotrace / geoshape); null for every other field type. */
    geo: RenderGeo | null;
    /** Media capture config (Increment G6: file / image / audio / video); null for every other field type. */
    media: RenderMedia | null;
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
    // Increment H10 — present (non-empty) only when the SPA was opened via the `/f/resume/{token}` web shell.
    // Drives the resume-and-restore flow (App.vue `load()`); empty on a normal `/f/{slug}` entry.
    resumeToken: string;
}

/** How the SPA should react to a normalized API error. */
export type ErrorKind =
    | 'field' // 422 — map onto fields
    | 'remint' // token expired — silently re-mint + retry, same schema
    | 'refresh' // version superseded — re-mint + re-fetch schema
    | 'rate_limited' // 429 — back off
    | 'schedule' // 403 form_not_open/form_closed/max_responses_reached — show the schedule state (H12b)
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

// ── H10 save-and-resume ────────────────────────────────────────────────────────────────────────

/** Body of `POST /api/v1/public/f/{token}/draft` — an upsert of the durable server draft (UX §5.2). */
export interface SaveDraftPayload {
    answers: AnswerMap;
    clientSubmissionUuid: string;
    locale: string;
    /** The SPA's current step key, restored verbatim on resume; omitted for single-page forms. */
    draftCurrentStep?: string | null;
    /** When set, "Save and finish later" also emails the resume link to this address. */
    guestContactEmail?: string | null;
    deviceId?: string | null;
    appVersion?: string | null;
    /** True for the explicit "Save and finish later" action (email the link); false for ambient draft saves. */
    finishLater?: boolean;
}

/** Response of a draft save — the durable resume handle. */
export interface SaveDraftResult {
    id: string;
    completenessPercent: number | null;
    resumeToken: string;
    resumeUrl: string;
    expiresAt: string;
}

/** Response of `GET /api/v1/public/drafts/{resumeToken}` — the saved state to restore (server tier). */
export interface ResumeDraftResult {
    id: string;
    completenessPercent: number | null;
    clientSubmissionUuid: string | null;
    formVersionId: string;
    answers: AnswerMap;
    /** Server-side "last saved" (ISO 8601) — the tiebreaker for the Dexie↔server newest-wins reconciliation. */
    lastSavedAt: string | null;
    draftCurrentStep: string | null;
    /** The locale the draft was last saved in; null falls back to the form default. */
    locale: string | null;
    /** A fresh short-lived SHARE token for the pinned version — the resumed session drives the ordinary endpoints. */
    shareToken: string;
    shareTokenExpiresAt: string;
}
