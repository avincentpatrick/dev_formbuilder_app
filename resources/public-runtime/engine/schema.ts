/**
 * The plain-data schema shapes the semantic validator consumes — the TS analogue of the `FormField` /
 * `FormSection` / `FormFieldValidation` model attributes the PHP `SemanticInput` carries. The SPA builds
 * these from the frozen `schema_snapshot` (id == key there, since the snapshot is FK-by-key); the golden
 * runner builds them from a vector fragment. Unset columns are `null`, matching the PHP models' magic-get.
 */

import type { EngineValue } from './coercion';
import type { ComparisonOperator, LogicOperator, RequiredMode, ValidationRuleType } from './enums';

export interface SchemaField {
    id: string;
    key: string;
    sequence: number;
    field_type?: string;
    is_required: RequiredMode;
    form_section_id: string | null;
    relevant_expression: string | null;
}

export interface SchemaSection {
    id: string;
    key: string;
    sequence: number;
    relevant_expression: string | null;
    /** Repeat groups (Increment G1). Omitted / false on a plain section — treated as non-repeatable. */
    is_repeatable?: boolean;
    min_instances?: number | null;
    max_instances?: number | null;
}

/** One repeat-group instance: a field-key => value map (same frozen per-field shapes as flat answers). */
export type InstanceAnswers = Record<string, EngineValue>;

export interface ValidationRow {
    id: string;
    form_field_id: string;
    sequence: number;
    rule_type: ValidationRuleType | null;
    operator: ComparisonOperator | null;
    related_form_field_id: string | null;
    rule_value: string | null;
    expression: string | null;
    logic_group: string | null;
    logic_operator: LogicOperator | null;
    error_message: string | null;
    error_message_translations: Record<string, string> | null;
}

export interface SemanticInput {
    fields: SchemaField[];
    sections: SchemaSection[];
    validations: ValidationRow[];
    /** Field key => value, plus (Increment G1) repeatable-section key => list of instance answer maps. */
    answers: Record<string, EngineValue | InstanceAnswers[]>;
    locale: string;
}
