/**
 * Two projections of the frozen `schema_snapshot` the SPA fetches from F5:
 *  - `buildEngineSchema()` → the static `fields`/`sections`/`validations` the F6a `SemanticValidator` consumes
 *    (the engine's `SchemaField` is intentionally minimal — relevance/validation columns only; id == key since
 *    the snapshot is FK-by-key). Answers + locale are plugged in reactively at evaluate time.
 *  - `buildRenderModel()` → the UI-facing superset (labels/hints/options/translations/control kind) the
 *    components walk; the engine never sees any of this.
 *
 * Keeping them separate is deliberate: the engine schema is the sole thing the byte-identical PHP/TS authority
 * agrees on; the render model is presentation only. The SPA maps INTO the engine and only FILTERS what the
 * engine returns — it never re-implements any relevance/constraint logic.
 */

import type {
    ComparisonOperator,
    LogicOperator,
    SchemaField,
    SchemaSection,
    SemanticInput,
    ValidationRow,
    ValidationRuleType,
} from '../engine';
import type {
    AnswerMap,
    ControlKind,
    RawField,
    RawSection,
    RenderField,
    RenderModel,
    RenderOption,
    RenderSection,
    SchemaResponse,
} from './types';

export type EngineSchema = Pick<SemanticInput, 'fields' | 'sections' | 'validations'>;

// The 14 Phase-1 scalar types with a real renderer (mirror of EncodeFormPresenter::SUPPORTED). Everything
// else is surfaced as an "unsupported" notice rather than silently dropped.
const SUPPORTED = new Set<string>([
    'short_text', 'long_text', 'email', 'phone', 'url',
    'integer', 'decimal',
    'date', 'time', 'datetime',
    'single_select', 'multi_select', 'dropdown', 'yes_no',
]);

// Field types that carry an author-defined option list (mirror of FieldType::hasOptions()).
const HAS_OPTIONS = new Set<string>(['single_select', 'multi_select', 'dropdown', 'likert_scale']);

const TEXT_TYPES = new Set<string>(['short_text', 'email', 'phone', 'url', 'date', 'time', 'datetime']);

/** Derive the control kind exactly as the reused F4b `FieldInput.vue` does. */
export function controlFor(fieldType: string, supported: boolean): ControlKind {
    if (fieldType === 'note') {
        return 'note';
    }
    if (!supported) {
        return 'unsupported';
    }
    if (TEXT_TYPES.has(fieldType)) {
        return 'text';
    }
    if (fieldType === 'long_text') {
        return 'textarea';
    }
    if (fieldType === 'integer' || fieldType === 'decimal') {
        return 'number';
    }
    if (fieldType === 'single_select' || fieldType === 'dropdown') {
        return 'select';
    }
    if (fieldType === 'multi_select') {
        return 'checkboxes';
    }
    if (fieldType === 'yes_no') {
        return 'yesno';
    }
    return 'unsupported';
}

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

function toRenderField(field: RawField): RenderField {
    const supported = SUPPORTED.has(field.field_type);
    const hasConditionalRequirement =
        field.is_required === 'conditional' ||
        field.validations.some((v) => v.rule_type === 'required_if' || v.rule_type === 'required_with');

    return {
        key: field.key,
        sectionKey: field.section_key,
        fieldType: field.field_type,
        control: controlFor(field.field_type, supported),
        supported,
        isRequired: field.is_required,
        hasConditionalRequirement,
        label: field.label,
        labelTranslations: field.label_translations,
        hint: field.hint,
        hintTranslations: field.hint_translations,
        placeholder: field.placeholder,
        options: buildOptions(field),
        sequence: field.sequence,
        sectionSequence: field.section_sequence,
    };
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

/** Combine the static engine schema with the live answers + locale into a `SemanticInput`. */
export function toSemanticInput(engineSchema: EngineSchema, answers: AnswerMap, locale: string): SemanticInput {
    return { ...engineSchema, answers, locale };
}
