// Shared builder types (Increment D4a). The server shapes mirror BuilderPresenter's output; the local
// shapes add a stable client `uid` decoupled from the server `id`, so the undo/redo command stack can
// keep referring to a field/section even across a delete→undo cycle that mints a brand-new server row.

export type Uid = string;

export interface BuilderValidation {
    rule_type: string | null;
    operator: string | null;
    rule_value: string | null;
    expression: string | null;
    error_message: string | null;
    related_field_key: string | null;
    sequence: number;
}

export interface ServerField {
    id: string;
    form_section_id: string | null;
    key: string;
    field_type: string;
    label: string;
    hint: string | null;
    placeholder: string | null;
    is_required: string;
    relevant_expression: string | null;
    appearance: string | null;
    config: Record<string, unknown>;
    default_value: string | null;
    is_pii: boolean;
    is_sensitive: boolean;
    is_queryable: boolean;
    indexed_data_type: string | null;
    sequence: number;
    section_sequence: number | null;
    version: string | null;
    validations: BuilderValidation[];
}

export interface ServerSection {
    id: string;
    key: string;
    label: string;
    description: string | null;
    is_repeatable: boolean;
    min_instances: number | null;
    max_instances: number | null;
    relevant_expression: string | null;
    sequence: number;
    version: string | null;
}

export interface LocalField extends ServerField {
    uid: Uid;
}

export interface LocalSection extends ServerSection {
    uid: Uid;
}

export interface PaletteType {
    value: string;
    label: string;
    advanced: boolean;
    has_options: boolean;
}

export interface PaletteGroup {
    category: string;
    label: string;
    icon: string;
    types: PaletteType[];
}

export interface EnumOption {
    value: string;
    label: string;
}

export interface BuilderEnums {
    required_modes: EnumOption[];
    indexed_data_types: EnumOption[];
    validation_rule_types: EnumOption[];
    comparison_operators: EnumOption[];
}

export interface BuilderPageProps {
    form: { id: string; title: string; description: string | null; status: string };
    draft: { id: string; version_number: number } | null;
    sections: ServerSection[];
    fields: ServerField[];
    palette: PaletteGroup[];
    enums: BuilderEnums;
}

// A group in the canvas: an optional owning section plus its ordered fields. `section === null` is the
// implicit "ungrouped" bucket rendered first.
export interface CanvasGroup {
    section: LocalSection | null;
    fields: LocalField[];
}

export type Selection = { kind: 'field'; uid: Uid } | { kind: 'section'; uid: Uid } | null;
