/**
 * Two projections of the frozen `schema_snapshot` the SPA fetches from F5:
 *  - `buildEngineSchema()` → the static `fields`/`sections`/`validations` the F6a `SemanticValidator` consumes
 *    (the engine's `SchemaField` is intentionally minimal — relevance/validation columns only; id == key since
 *    the snapshot is FK-by-key). Answers + locale are plugged in reactively at evaluate time.
 *  - `buildRenderModel()` → the UI-facing superset (labels/hints/options/translations) the components walk;
 *    the engine never sees any of this. Which control renders a field is `FieldInput.vue`'s decision alone:
 *    the render model carries no control kind since `M130` (see the note above `resolveText()`).
 *
 * Keeping them separate is deliberate: the engine schema is the sole thing the byte-identical PHP/TS authority
 * agrees on; the render model is presentation only. The SPA maps INTO the engine and only FILTERS what the
 * engine returns — it never re-implements any relevance/constraint logic.
 */

import type {
    CascadeConfig,
    ComparisonOperator,
    GridColumnSpec,
    GridConfig,
    LogicOperator,
    RenderSources,
    SchemaField,
    SchemaSection,
    SemanticInput,
    ValidationRow,
    ValidationRuleType,
} from '../engine';
import type {
    AnswerMap,
    RawField,
    RawSection,
    RenderCascade,
    RenderCascadeLevel,
    RenderCascadeOption,
    RenderField,
    RenderGeo,
    RenderMatrix,
    RenderMedia,
    RenderModel,
    RenderOption,
    RenderSection,
    SchemaResponse,
} from './types';

export type EngineSchema = Pick<SemanticInput, 'fields' | 'sections' | 'validations'>;

// The types with a real renderer (mirror of EncodeFormPresenter::SUPPORTED). Everything else is surfaced as
// an "unsupported" notice rather than silently dropped. Increment G4a adds likert_scale + cascading_select.
const SUPPORTED = new Set<string>([
    'short_text', 'long_text', 'email', 'phone', 'url',
    'integer', 'decimal',
    'date', 'time', 'datetime',
    'single_select', 'multi_select', 'dropdown', 'yes_no',
    'likert_scale', 'cascading_select',
    // Increment G4b: the object-valued grids.
    'matrix', 'likert_matrix',
    // Increment G5b2: geospatial capture.
    'geopoint', 'geotrace', 'geoshape',
    // Increment G6: media capture (signature's canvas control is deferred, so it stays unsupported).
    'file_upload', 'image_capture', 'audio_capture', 'video_capture',
]);

/**
 * Field types that render NOTHING on a respondent's form (Increment H7).
 *
 * The definition MOVED to `engine/field-roles.ts` in H21a — the semantic validator gained a second consumer
 * of the same set (the `min_instances` step-visibility narrowing, Doc #27 §4.3 amendment A4) and the engine
 * must not import from `lib/`. It is re-exported here so every existing import path still resolves, and the
 * PHP parity test (`tests/Unit/Forms/PdfFieldRoleTest.php`) now parses the new home.
 */
export { fieldRendersNothing, rendersNothing } from '../engine';

// Field types that carry an author-defined option list (mirror of FieldType::hasOptions()).
const HAS_OPTIONS = new Set<string>(['single_select', 'multi_select', 'dropdown', 'likert_scale']);

// ⛔ M130 DELETED `controlFor()`, its `TEXT_TYPES` set and `RenderField.control`. They claimed to derive the
// control kind "exactly as" `FieldInput.vue` does, no production code read them, and they had already
// drifted from it. `FieldInput.vue`'s own `control` computed is the ONE switch every channel renders through;
// what can drift is the three adapters that build its field, which `EncodeFieldAdapterParityTest` censuses.

/** Resolve a translated string for the current locale, falling back to the default-locale base (never blank). */
export function resolveText(base: string, translations: Record<string, string> | null, locale: string): string {
    const value = translations?.[locale];
    return value !== undefined && value !== '' ? value : base;
}

export function resolveOptional(
    base: string | null,
    translations: Record<string, string> | null,
    locale: string,
): string | null {
    if (base === null) {
        const value = translations?.[locale];
        return value !== undefined && value !== '' ? value : null;
    }
    return resolveText(base, translations, locale);
}

/**
 * Resolve a cascading hierarchy's level + option labels into the current locale, producing the flat shape the
 * shared `FieldInput.vue` cascading control consumes (Increment G4a). Used by both the flat + repeat SPA
 * adapters so translation resolution lives in one place.
 */
export function resolveCascade(
    cascade: RenderCascade | null,
    locale: string,
): {
    levels: { key: string; label: string }[];
    options: { value: string; label: string; level: string; parent: string | null }[];
    skip_single: boolean;
} | null {
    if (cascade === null) {
        return null;
    }

    return {
        levels: cascade.levels.map((level) => ({ key: level.key, label: resolveText(level.label, level.labelTranslations, locale) })),
        options: cascade.options.map((option) => ({
            value: option.value,
            label: resolveText(option.label, option.labelTranslations, locale),
            level: option.level,
            parent: option.parent,
        })),
        skip_single: cascade.skipSingle === true,
    };
}

function buildOptions(field: RawField): RenderOption[] {
    if (!HAS_OPTIONS.has(field.field_type)) {
        return [];
    }
    const raw = field.config?.options;
    if (!Array.isArray(raw)) {
        return [];
    }
    const out: RenderOption[] = [];
    for (const option of raw) {
        if (option === null || typeof option !== 'object' || option.value === undefined || option.value === null) {
            continue;
        }
        const value = String(option.value);
        out.push({
            value,
            label: option.label !== undefined && option.label !== null ? String(option.label) : value,
            labelTranslations: option.label_translations ?? null,
        });
    }
    return out;
}

/** The `config.options[].value` set (as strings) the engine membership check reads. Mirror of PHP's optionValueSet. */
function engineOptionValues(field: RawField): string[] {
    const raw = field.config?.options;
    if (!Array.isArray(raw)) {
        return [];
    }
    const out: string[] = [];
    for (const option of raw) {
        if (option === null || typeof option !== 'object') {
            continue;
        }
        const value = (option as { value?: unknown }).value;
        if (value === undefined || value === null) {
            continue;
        }
        out.push(String(value));
    }

    return out;
}

/** Project a cascading field's `config.levels`/`config.options` into the engine's {@link CascadeConfig}. */
function engineCascadeConfig(field: RawField): CascadeConfig {
    const levelsRaw = field.config?.levels;
    const optionsRaw = field.config?.options;

    const levels = Array.isArray(levelsRaw)
        ? levelsRaw.map((level) =>
              level !== null && typeof level === 'object' ? String((level as { key?: unknown }).key ?? '') : String(level),
          )
        : [];

    const options = Array.isArray(optionsRaw)
        ? optionsRaw.flatMap((option) => {
              if (option === null || typeof option !== 'object') {
                  return [];
              }
              const value = (option as { value?: unknown }).value;
              const level = (option as { level?: unknown }).level;
              if (value === undefined || value === null || level === undefined || level === null) {
                  return [];
              }
              const parent = (option as { parent?: unknown }).parent;

              return [
                  {
                      value: String(value),
                      level: String(level),
                      parent: parent === undefined || parent === null ? null : String(parent),
                  },
              ];
          })
        : [];

    return { levels, options };
}

/** The UI-facing cascading hierarchy (labels + translations kept for the render layer to resolve). */
function buildCascade(field: RawField): RenderCascade | null {
    if (field.field_type !== 'cascading_select') {
        return null;
    }

    const levelsRaw = field.config?.levels;
    const optionsRaw = field.config?.options;

    const levels: RenderCascadeLevel[] = Array.isArray(levelsRaw)
        ? levelsRaw.flatMap((level) => {
              if (level === null || typeof level !== 'object') {
                  return [];
              }
              const key = (level as { key?: unknown }).key;
              if (key === undefined || key === null) {
                  return [];
              }
              const label = (level as { label?: unknown }).label;
              const labelTranslations = (level as { label_translations?: Record<string, string> | null }).label_translations ?? null;

              return [{ key: String(key), label: label !== undefined && label !== null ? String(label) : String(key), labelTranslations }];
          })
        : [];

    const options: RenderCascadeOption[] = Array.isArray(optionsRaw)
        ? optionsRaw.flatMap((option) => {
              if (option === null || typeof option !== 'object') {
                  return [];
              }
              const value = (option as { value?: unknown }).value;
              const level = (option as { level?: unknown }).level;
              if (value === undefined || value === null || level === undefined || level === null) {
                  return [];
              }
              const label = (option as { label?: unknown }).label;
              const labelTranslations = (option as { label_translations?: Record<string, string> | null }).label_translations ?? null;
              const parent = (option as { parent?: unknown }).parent;

              return [
                  {
                      value: String(value),
                      label: label !== undefined && label !== null ? String(label) : String(value),
                      labelTranslations,
                      level: String(level),
                      parent: parent === undefined || parent === null ? null : String(parent),
                  },
              ];
          })
        : [];

    // M186 (`R-70c7673a`): a level with one choice under the one picked above is filled in for the person.
    return { levels, options, skipSingle: field.config?.skip_single_choice_levels === true };
}

/** A grid column as the shared `FieldInput.vue` grid draws it (labels resolved; the three rules only on a `matrix`). */
export interface ResolvedMatrixColumn {
    value: string;
    label: string;
    type?: string;
    options?: { value: string; label: string }[];
    required?: boolean;
}

/**
 * Resolve a composite grid's row/column/cell labels into the current locale, producing the flat shape the
 * shared `FieldInput.vue` grid controls consume (Increment G4b). Used by both the flat + repeat SPA adapters
 * so translation resolution lives in one place. `cells` is empty for `likert_matrix`. M183: a matrix column keeps
 * its type, its (resolved) choices and its required flag, and the grid its layout.
 */
export function resolveMatrix(
    matrix: RenderMatrix | null,
    locale: string,
): { rows: { value: string; label: string }[]; columns: ResolvedMatrixColumn[]; cells: { value: string; label: string }[]; layout: 'table' | 'stacked' } | null {
    if (matrix === null) {
        return null;
    }

    const resolve = (options: RenderOption[]): { value: string; label: string }[] =>
        options.map((option) => ({ value: option.value, label: resolveText(option.label, option.labelTranslations, locale) }));

    const columns = matrix.columns.map((column): ResolvedMatrixColumn => ({
        value: column.value,
        label: resolveText(column.label, column.labelTranslations, locale),
        ...(column.type !== undefined ? { type: column.type, options: resolve(column.options ?? []), required: column.required === true } : {}),
    }));

    return { rows: resolve(matrix.rows), columns, cells: resolve(matrix.cells), layout: matrix.layout };
}

/** A `config.<key>` option-value list (as strings) the engine grid membership check reads. */
function configValueList(field: RawField, key: string): string[] {
    return optionValues(field.config?.[key]);
}

/** The values of a stored option list (as strings), skipping entries with none. */
function optionValues(raw: unknown): string[] {
    if (!Array.isArray(raw)) {
        return [];
    }
    const out: string[] = [];
    for (const option of raw) {
        if (option === null || typeof option !== 'object') {
            continue;
        }
        const value = (option as { value?: unknown }).value;
        if (value === undefined || value === null) {
            continue;
        }
        out.push(String(value));
    }

    return out;
}

/** The types a matrix column may take (M183, `R-5033e87e`; mirror of PHP `MatrixColumns::TYPES`). */
const MATRIX_COLUMN_TYPES = ['single_select', 'multi_select', 'short_text', 'integer', 'decimal', 'date'];

/** A grid's stored column objects, skipping anything that is not one. */
function rawColumns(field: RawField): Record<string, unknown>[] {
    const raw = field.config?.columns;

    return Array.isArray(raw) ? raw.filter((c): c is Record<string, unknown> => c !== null && typeof c === 'object') : [];
}

/**
 * Project a composite grid field's `config.rows`/`columns`/`cells` into the engine's {@link GridConfig}. M183: a
 * matrix column that carries a type, choices of its own or a required flag hands the engine its rule under
 * `column_specs` (the shape PHP `SemanticValidator::columnSpecs` reads); a grid with none projects exactly as before.
 */
function engineGridConfig(field: RawField): GridConfig {
    const grid: GridConfig = {
        rows: configValueList(field, 'rows'),
        columns: configValueList(field, 'columns'),
        cells: configValueList(field, 'cells'),
    };
    if (field.field_type !== 'matrix') {
        return grid;
    }

    const specs: Record<string, GridColumnSpec> = {};
    for (const column of rawColumns(field)) {
        const type = typeof column.type === 'string' && column.type !== '' ? column.type : null;
        const options = optionValues(column.options);
        const required = column.required === true;
        if (column.value === undefined || column.value === null || (type === null && options.length === 0 && !required)) {
            continue;
        }
        specs[String(column.value)] = { type, options, required };
    }

    return Object.keys(specs).length > 0 ? { ...grid, column_specs: specs } : grid;
}

/** A `config.<key>` option list projected to render {value,label} pairs (labels/translations kept). */
function configOptionPairs(field: RawField, key: string): RenderOption[] {
    return optionPairs(field.config?.[key]);
}

/** A stored option list projected to render {value,label} pairs (labels/translations kept). */
function optionPairs(raw: unknown): RenderOption[] {
    if (!Array.isArray(raw)) {
        return [];
    }
    const out: RenderOption[] = [];
    for (const option of raw) {
        if (option === null || typeof option !== 'object') {
            continue;
        }
        const value = (option as { value?: unknown }).value;
        if (value === undefined || value === null) {
            continue;
        }
        const label = (option as { label?: unknown }).label;
        out.push({
            value: String(value),
            label: label !== undefined && label !== null ? String(label) : String(value),
            labelTranslations: (option as { label_translations?: Record<string, string> | null }).label_translations ?? null,
        });
    }

    return out;
}

/**
 * The UI-facing composite grid config (labels + translations kept for the render layer to resolve). M183: a matrix
 * column carries its answer rule resolved — its type (a single choice when none was set), its choices (its own, else
 * the shared `cells`) and its required flag — so the grid never resolves the fallback itself; mirror of PHP
 * `EncodeFormPresenter::matrix()`.
 */
function buildMatrix(field: RawField): RenderMatrix | null {
    if (field.field_type !== 'matrix' && field.field_type !== 'likert_matrix') {
        return null;
    }

    const cells = field.field_type === 'matrix' ? configOptionPairs(field, 'cells') : [];
    const columns = field.field_type === 'likert_matrix'
        ? configOptionPairs(field, 'columns')
        : rawColumns(field).flatMap((column) => {
            const [pair] = optionPairs([column]);
            if (pair === undefined) {
                return [];
            }
            const type = typeof column.type === 'string' && MATRIX_COLUMN_TYPES.includes(column.type) ? column.type : 'single_select';
            const own = optionPairs(column.options);
            const options = type === 'single_select' || type === 'multi_select' ? (own.length > 0 ? own : cells) : [];

            return [{ ...pair, type, options, required: column.required === true }];
        });

    return {
        rows: configOptionPairs(field, 'rows'),
        columns,
        cells,
        layout: field.config?.layout === 'stacked' ? 'stacked' : 'table',
    };
}
/** Read a `config.<key>` value coerced to a finite number, or null. */
function configNumber(field: RawField, key: string): number | null {
    const raw = field.config?.[key];
    if (raw === null || raw === undefined || raw === '') {
        return null;
    }
    const n = Number(raw);
    return Number.isFinite(n) ? n : null;
}

/** The UI-facing geo capture config (Increment G5b2), normalised snake_case config → camelCase RenderGeo. */
function buildGeo(field: RawField): RenderGeo | null {
    if (field.field_type !== 'geopoint' && field.field_type !== 'geotrace' && field.field_type !== 'geoshape') {
        return null;
    }

    const center = field.config?.default_center;
    let defaultCenter: { lat: number; lon: number } | null = null;
    if (center !== null && typeof center === 'object' && !Array.isArray(center)) {
        const lat = Number((center as { lat?: unknown }).lat);
        const lon = Number((center as { lon?: unknown }).lon);
        if (Number.isFinite(lat) && Number.isFinite(lon)) {
            defaultCenter = { lat, lon };
        }
    }

    return {
        captureAltitude: field.config?.capture_altitude === true,
        accuracyThreshold: configNumber(field, 'accuracy_threshold'),
        defaultCenter,
        defaultZoom: configNumber(field, 'default_zoom'),
    };
}

/** The four media types wired to a renderer (Increment G6) — signature's canvas control is deferred. */
function isMediaFieldType(fieldType: string): boolean {
    return fieldType === 'file_upload' || fieldType === 'image_capture' || fieldType === 'audio_capture' || fieldType === 'video_capture';
}

/** The UI-facing media capture config (Increment G6), normalised snake_case config → camelCase RenderMedia. */
function buildMedia(field: RawField): RenderMedia | null {
    if (!isMediaFieldType(field.field_type)) {
        return null;
    }

    const acceptedRaw = field.config?.accepted_types;
    const acceptedTypes = Array.isArray(acceptedRaw) ? acceptedRaw.filter((t): t is string => typeof t === 'string') : [];
    const source = field.config?.capture_source;

    return {
        acceptedTypes,
        maxFileSizeBytes: configNumber(field, 'max_file_size_bytes'),
        maxCount: configNumber(field, 'max_count'),
        minCount: configNumber(field, 'min_count'),
        captureSource: typeof source === 'string' ? source : null,
    };
}

/** The min/max count the engine's media pass reads (Increment G6) → engine SchemaField.media. */
function engineMediaConfig(field: RawField): { maxCount: number | null; minCount: number | null } {
    return { maxCount: configNumber(field, 'max_count'), minCount: configNumber(field, 'min_count') };
}

function toRenderField(field: RawField): RenderField {
    const supported = SUPPORTED.has(field.field_type);
    const hasConditionalRequirement =
        field.is_required === 'conditional' ||
        field.validations.some((v) => v.rule_type === 'required_if' || v.rule_type === 'required_with');

    return {
        key: field.key,
        sectionKey: field.section_key,
        fieldType: field.field_type,
        supported,
        isRequired: field.is_required,
        hasConditionalRequirement,
        label: field.label,
        labelTranslations: field.label_translations,
        hint: field.hint,
        hintTranslations: field.hint_translations,
        placeholder: field.placeholder,
        options: buildOptions(field),
        cascade: buildCascade(field),
        matrix: buildMatrix(field),
        geo: buildGeo(field),
        media: buildMedia(field),
        appearance: field.appearance ?? null,
        content: field.field_type === 'note' && Array.isArray(field.config?.content) ? (field.config.content as unknown[]) : null,
        calculation: calculationDisplayOf(field),
        suggestions: suggestionsOf(field),
        visibility: visibilityOf(field),
        sequence: field.sequence,
        sectionSequence: field.section_sequence,
    };
}

/**
 * M184 (`R-596d6afd`): a Short text's suggestions — a list file's labels the version was published with, or another
 * form's answers `mergeLinkedChoices()` wrote in. Null for every other type; never checked against the answer.
 */
function suggestionsOf(field: RawField): string[] | null {
    const raw = field.field_type === 'short_text' ? field.config?.suggestions : undefined;

    return Array.isArray(raw) ? raw.filter((item): item is string => typeof item === 'string') : null;
}

/** M177 (`R-f44e9d00`): Show to respondents, as stored — anything but the two values reads as the type's own rule. */
function visibilityOf(field: RawField): 'hidden' | 'shown' | null {
    const value = field.config?.visibility;

    return value === 'hidden' || value === 'shown' ? value : null;
}

function toRenderSection(section: RawSection): RenderSection {
    return {
        key: section.key,
        label: section.label,
        labelTranslations: section.label_translations,
        description: section.description,
        descriptionTranslations: section.description_translations,
        sequence: section.sequence,
        isRepeatable: section.is_repeatable,
        minInstances: section.min_instances ?? null,
        maxInstances: section.max_instances ?? null,
        repeatLayout: section.repeat_layout === 'table' ? 'table' : 'cards',
        relevantExpression: section.relevant_expression,
    };
}

const bySequence = <T extends { sequence: number }>(a: T, b: T): number => a.sequence - b.sequence;

export function buildRenderModel(schema: SchemaResponse): RenderModel {
    return {
        form: schema.form,
        sections: schema.version.schema.sections.map(toRenderSection).sort(bySequence),
        fields: schema.version.schema.fields.map(toRenderField).sort(bySequence),
    };
}

/**
 * The piping SOURCE map (Increment H6b, Doc #26 §3.2) — the TypeScript twin of
 * `App\Services\Templates\TemplateSources::fromSnapshot()`: field key ⇒ its raw `field_type` and its RAW
 * `config`, which is what `displayValue()` reads to resolve a choice code to its author-defined label.
 *
 * Built from `version.schema.fields` rather than from the render model ON PURPOSE, and this is the trap
 * Doc #26 §3.2 names by hand: `toRenderField()` has already projected `config` into six presentation
 * shapes (a note's raw `content` since M130) and dropped the rest, and `buildOptions()` only runs for `HAS_OPTIONS` types — so a
 * `cascading_select`'s options are not on `RenderField` at all, and a renderer fed from there would emit
 * "ncr; manila" where PHP emits "Metro Manila; Manila". Re-deriving `config` would also be a SECOND
 * normalisation of the one input the two engines must agree on byte for byte.
 *
 * TOTAL over the snapshot: `hidden`, `calculated`, `note` and `page_break` are all included. Two of those
 * matter — `hidden` and `calculated` are PIPEABLE (§3.1, deliberately differing from OCR eligibility), so
 * filtering by the `SUPPORTED` set above (a different question: "types with a renderer") would silently
 * break piping's headline cases, a running total and a URL-prefilled name.
 *
 * One knowing divergence from the PHP twin: `fromSnapshot()` SKIPS a field whose `field_type` is unknown
 * to the deployment (`FieldType::tryFrom()` returning null), so its hole renders empty. There is no
 * `FieldType` enum here, so the entry is kept and `displayValue()`'s total fallback decides. Reachable
 * only when a cached SPA bundle meets a snapshot from a newer deployment; recorded, not papered over.
 */
export function buildTemplateSources(schema: SchemaResponse): RenderSources {
    const sources: RenderSources = {};

    for (const field of schema.version.schema.fields) {
        sources[field.key] = { type: field.field_type, config: field.config ?? {} };
    }

    return sources;
}

export function buildEngineSchema(schema: SchemaResponse): EngineSchema {
    const fields: SchemaField[] = schema.version.schema.fields.map((f) => ({
        id: f.key,
        key: f.key,
        sequence: f.sequence,
        field_type: f.field_type,
        is_required: f.is_required,
        form_section_id: f.section_key,
        relevant_expression: f.relevant_expression,
        // Grammar v2.0: a calculated field's formula, so the engine's compute pass can produce its value.
        calculate: typeof f.config?.calculated_formula === 'string' ? f.config.calculated_formula : null,
        // Choice-membership + cascading integrity (Increment G4a) — the engine reads only these projections
        // of `config`, never the raw editor payload, so it stays byte-identical with the PHP authority.
        options: HAS_OPTIONS.has(f.field_type) ? engineOptionValues(f) : null,
        cascade: f.field_type === 'cascading_select' ? engineCascadeConfig(f) : null,
        // Composite grid membership sets (Increment G4b) — row/column/cell VALUE keys only.
        grid: f.field_type === 'matrix' || f.field_type === 'likert_matrix' ? engineGridConfig(f) : null,
        // Media count bounds (Increment G6) — min/max attachment count for the engine's processMedia pass.
        media: isMediaFieldType(f.field_type) ? engineMediaConfig(f) : null,
        // M177 (`R-f44e9d00`): Show to respondents — a Never question raises no error nobody could repair.
        visibility: visibilityOf(f),
    }));

    const sections: SchemaSection[] = schema.version.schema.sections.map((s) => ({
        id: s.key,
        key: s.key,
        sequence: s.sequence,
        relevant_expression: s.relevant_expression,
        // Repeat-group columns (Increment G2) — without these the F6a engine's G1 repeat pass is inert in
        // the SPA (a repeatable section would fall through to the flat top-level path).
        is_repeatable: s.is_repeatable,
        min_instances: s.min_instances ?? null,
        max_instances: s.max_instances ?? null,
    }));

    const validations: ValidationRow[] = schema.version.schema.fields.flatMap((f) =>
        f.validations.map((v, index) => ({
            id: `${f.key}:${index}`,
            form_field_id: f.key,
            sequence: v.sequence,
            rule_type: (v.rule_type as ValidationRuleType | null) ?? null,
            operator: (v.operator as ComparisonOperator | null) ?? null,
            related_form_field_id: v.related_field_key,
            rule_value: v.rule_value === null ? null : String(v.rule_value),
            expression: v.expression,
            logic_group: v.logic_group_ordinal === null ? null : String(v.logic_group_ordinal),
            logic_operator: (v.logic_operator as LogicOperator | null) ?? null,
            error_message: v.error_message,
            error_message_translations: v.error_message_translations,
        })),
    );

    return { fields, sections, validations };
}

/**
 * The ISO-8601 clock string `today()` / `now()` read, in PHP's EXACT shape (Increment H21a, Doc #27 §3.4).
 *
 * `Carbon::now()->toIso8601String()` under `config/app.php`'s `'timezone' => 'UTC'` emits
 * `2026-08-01T12:34:56+00:00` — seconds precision, explicit offset, never `Z`, and every `now` literal in
 * the golden corpus matches that shape. `new Date().toISOString()` emits `2026-08-01T12:34:56.789Z`, a
 * DIFFERENT string, and both engines return the injected clock verbatim from `now()` — so the format is
 * itself a mirror surface and the naive call would have opened a fresh divergence in the increment that
 * exists to close one.
 *
 * STAMPED IN UTC, NOT THE DEVICE OFFSET, and that is the load-bearing choice. The server re-validates at
 * submit under its own UTC clock (`SemanticValidator::validate()`), so a local offset would make `today()`
 * disagree across the evening window in every UTC+ zone: the client renders a step the server then prunes,
 * `passed()` is true because an irrelevant field is never required-checked, and the respondent gets a 201
 * with silently dropped answers. UTC costs only that `today()` is the machine's calendar day rather than the
 * respondent's — a narrowing that is visible and arguable, not a silent data fault.
 *
 * M180 (`D153`) closed that narrowing without reopening the fault: the clock is stamped in the FORM'S zone
 * (`schedule.timezone`), and so is the server's (`App\Support\Forms\FormClock`) — both sides read the same calendar
 * day again, now the respondent's. The offset comes from `Intl` for that instant (daylight saving included); an
 * unknown zone falls back to UTC, as the server's does. Same shape: seconds, an explicit `±HH:MM`, never `Z`.
 */
export function isoClock(at: Date, timeZone = 'UTC'): string {
    const pad = (n: number): string => String(n).padStart(2, '0');
    const instant = Math.floor(at.getTime() / 1000) * 1000;
    let offsetMinutes = 0;

    try {
        const parts = new Intl.DateTimeFormat('en-US', {
            timeZone, hourCycle: 'h23', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit',
        }).formatToParts(new Date(instant));
        const part = (type: Intl.DateTimeFormatPartTypes): number => Number(parts.find((p) => p.type === type)?.value);
        const wall = Date.UTC(part('year'), part('month') - 1, part('day'), part('hour'), part('minute'), part('second'));
        offsetMinutes = Math.round((wall - instant) / 60000);
    } catch {
        offsetMinutes = 0; // an unknown zone: UTC, as the server falls back
    }

    const local = new Date(instant + offsetMinutes * 60000);
    const sign = offsetMinutes < 0 ? '-' : '+';
    const magnitude = Math.abs(offsetMinutes);

    return `${local.getUTCFullYear()}-${pad(local.getUTCMonth() + 1)}-${pad(local.getUTCDate())}`
        + `T${pad(local.getUTCHours())}:${pad(local.getUTCMinutes())}:${pad(local.getUTCSeconds())}`
        + `${sign}${pad(Math.floor(magnitude / 60))}:${pad(magnitude % 60)}`;
}

/**
 * Combine the static engine schema with the live answers + locale into a `SemanticInput`.
 *
 * `now` is OPTIONAL so every pre-H21a caller stays byte-identical (the A8 device H6b and H17 both used).
 * Before H21a nothing passed it at all, so `SemanticInput.now` fell to null, `hasNow()` was false, and BOTH
 * clock functions returned ABSENT in the SPA while PHP always stamps a clock — a live PHP/TS divergence the
 * R3 gate structurally cannot see, because the golden runner supplies `now` from each vector.
 */
export function toSemanticInput(engineSchema: EngineSchema, answers: AnswerMap, locale: string, now?: string | null): SemanticInput {
    return { ...engineSchema, answers, locale, now: now ?? null };
}

/**
 * M180 (`R-f77aa45d`) — a calculation's display settings, as `displayValue()` reads them; null for any other type and for
 * a calculation with none (it shows exactly as before). The mirror of `EncodeFormPresenter::calculationDisplay()`.
 */
function calculationDisplayOf(field: RawField): Record<string, unknown> | null {
    if (field.field_type !== 'calculated' || field.config === null) {
        return null;
    }

    const display: Record<string, unknown> = {};
    for (const key of ['calculated_type', 'decimal_places', 'duration_unit']) {
        if (field.config[key] !== undefined && field.config[key] !== null) {
            display[key] = field.config[key];
        }
    }

    return Object.keys(display).length > 0 ? display : null;
}
