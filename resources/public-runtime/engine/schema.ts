/**
 * The plain-data schema shapes the semantic validator consumes — the TS analogue of the `FormField` /
 * `FormSection` / `FormFieldValidation` model attributes the PHP `SemanticInput` carries. The SPA builds
 * these from the frozen `schema_snapshot` (id == key there, since the snapshot is FK-by-key); the golden
 * runner builds them from a vector fragment. Unset columns are `null`, matching the PHP models' magic-get.
 */

import type { EngineValue } from './coercion';
import type { ComparisonOperator, LogicOperator, RequiredMode, ValidationRuleType } from './enums';

/** One cascading-select level's option pool (Increment G4a): a flat list keyed by level + parent. */
export interface CascadeConfig {
    levels: string[];
    options: { value: string; level: string; parent: string | null }[];
}

/**
 * Composite grid membership sets (Increment G4b): the declared row/column (and, for `matrix`, cell) VALUE
 * keys — the engine reads only these to prune + membership-check the object answer. `cells` is absent for
 * `likert_matrix` (its per-row score is drawn from `columns`). Absent ⇒ no composite check (byte-identical).
 */
export interface GridConfig {
    rows: string[];
    columns: string[];
    cells?: string[];
}

/** A likert_matrix answer `{row: score}` (Increment G4b). */
export type LikertMatrixAnswer = Record<string, EngineValue>;
/** A matrix answer `{row: {col: cell}}` (Increment G4b). */
export type MatrixAnswer = Record<string, Record<string, EngineValue>>;
/** Either object-valued grid answer shape — deliberately OUTSIDE `EngineValue` (never scalar-coerced). */
export type CompositeAnswer = LikertMatrixAnswer | MatrixAnswer;

export interface SchemaField {
    id: string;
    key: string;
    sequence: number;
    field_type?: string;
    is_required: RequiredMode;
    form_section_id: string | null;
    relevant_expression: string | null;
    /** Grammar v2.0: a calculated field's formula (from `config.calculated_formula`); null / omitted otherwise. */
    calculate?: string | null;
    /**
     * Choice-membership option values (Increment G4a): the `config.options[].value` set for single/multi/
     * dropdown/likert_scale. Absent / empty ⇒ no membership check (keeps pre-G4a vectors byte-identical).
     */
    options?: string[] | null;
    /** Cascading-select hierarchy (Increment G4a): drives per-level membership + parent-consistency. */
    cascade?: CascadeConfig | null;
    /** Composite grid config (Increment G4b: matrix / likert_matrix). Absent ⇒ no composite check. */
    grid?: GridConfig | null;
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
    /**
     * Field key => value, plus (Increment G1) repeatable-section key => list of instance answer maps, plus
     * (Increment G4b) a composite grid field key => its object-valued answer.
     */
    answers: Record<string, EngineValue | InstanceAnswers[] | CompositeAnswer>;
    locale: string;
    /** Grammar v2.0: the injected clock (ISO-8601) that `today()`/`now()` read; null / omitted → unset. */
    now?: string | null;
}
