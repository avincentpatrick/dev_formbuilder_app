/** Test builders for the raw `schema_snapshot` shapes the F5 backend returns. */
import type { RawField, RawSchemaSnapshot, RawSection, RawValidation, SchemaResponse } from '../lib/types';

export function field(partial: Partial<RawField> & { key: string }): RawField {
    return {
        key: partial.key,
        section_key: partial.section_key ?? null,
        field_type: partial.field_type ?? 'short_text',
        config: partial.config ?? null,
        label: partial.label ?? partial.key,
        label_translations: partial.label_translations ?? null,
        hint: partial.hint ?? null,
        hint_translations: partial.hint_translations ?? null,
        placeholder: partial.placeholder ?? null,
        default_value: partial.default_value ?? null,
        default_value_is_expression: partial.default_value_is_expression ?? false,
        is_required: partial.is_required ?? 'optional',
        relevant_expression: partial.relevant_expression ?? null,
        appearance: partial.appearance ?? null,
        sequence: partial.sequence ?? 0,
        section_sequence: partial.section_sequence ?? null,
        validations: partial.validations ?? [],
    };
}

export function section(partial: Partial<RawSection> & { key: string }): RawSection {
    return {
        key: partial.key,
        label: partial.label ?? partial.key,
        label_translations: partial.label_translations ?? null,
        description: partial.description ?? null,
        description_translations: partial.description_translations ?? null,
        sequence: partial.sequence ?? 0,
        is_repeatable: partial.is_repeatable ?? false,
        min_instances: partial.min_instances ?? null,
        max_instances: partial.max_instances ?? null,
        relevant_expression: partial.relevant_expression ?? null,
    };
}

export function validation(partial: Partial<RawValidation> = {}): RawValidation {
    return {
        rule_type: partial.rule_type ?? null,
        operator: partial.operator ?? null,
        rule_value: partial.rule_value ?? null,
        expression: partial.expression ?? null,
        error_message: partial.error_message ?? null,
        error_message_translations: partial.error_message_translations ?? null,
        related_field_key: partial.related_field_key ?? null,
        logic_group_ordinal: partial.logic_group_ordinal ?? null,
        logic_operator: partial.logic_operator ?? null,
        sequence: partial.sequence ?? 0,
    };
}

export function schemaResponse(opts: {
    fields: RawField[];
    sections?: RawSection[];
    form?: Partial<SchemaResponse['form']>;
    checksum?: string;
}): SchemaResponse {
    const schema: RawSchemaSnapshot = { sections: opts.sections ?? [], fields: opts.fields };
    return {
        form: {
            id: opts.form?.id ?? 'form-1',
            title: opts.form?.title ?? 'Test form',
            description: opts.form?.description ?? null,
            default_locale: opts.form?.default_locale ?? 'en',
            supported_locales: opts.form?.supported_locales ?? ['en'],
            single_page_mode: opts.form?.single_page_mode ?? true,
        },
        version: {
            id: 'ver-1',
            version_number: 1,
            checksum: opts.checksum ?? 'checksum-abc',
            schema,
        },
    };
}
