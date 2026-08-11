// Shared builder types (Increment D4a). The server shapes mirror BuilderPresenter's output; the local
// shapes add a stable client `uid` decoupled from the server `id`, so the undo/redo command stack can
// keep referring to a field/section even across a delete→undo cycle that mints a brand-new server row.
//
// `ShareProps` MOVED OUT to `@/components/forms/types` in J2b — the form hub renders the same block, so the
// payload stopped being the builder's. It is imported here rather than re-exported: one live import path
// per type, so two pages cannot end up depending on two names for one shape.

import type { ShareProps } from '@/components/forms/types';

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

// What the structured condition editor is allowed to offer an author (Increment H21d2). Assembled by
// ConfigPanel from the store, because the editor itself takes no store — the sub-editor house contract.
// `fields` and `repeatables` are kept APART rather than merged: section and field keys are not globally
// unique (Doc #27 amendment A7, independent per-table indexes), so one flat list would silently prefer one
// table's `roster` over the other's.
export interface ConditionFieldOption {
    key: string;
    label: string;
    // integer / decimal / calculated / likert_scale — decides whether a NEW fixed value defaults to a number
    // literal or a string one, which is a real semantic difference (Eq rule 5 vs rule 4), not a display one.
    numeric: boolean;
    // The field's own choices, so a `selected()` row offers a dropdown instead of asking an author to
    // retype an option value. Empty for a field that has none.
    options: EnumOption[];
}

export interface ConditionCatalogue {
    fields: ConditionFieldOption[];
    repeatables: { key: string; label: string }[];
}

export interface PaletteType {
    value: string;
    label: string;
    advanced: boolean;
    has_options: boolean;
    // The dedicated config editor this type needs beyond the shared tabs (G4a): 'choices' | 'cascading' |
    // null. Mirrors FieldType::configEditor(); the config panel keys its editor tab off this.
    config_editor: string | null;
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

// A question-library item as the picker shows it (Increment G9b). Mirrors BuilderPresenter::libraryItem —
// the heavy default_config / default_validations jsonb never reaches the client; insert materializes it
// server-side. `is_platform` = a NULL-tenant platform question (vs the tenant's own saved one).
export interface LibraryItem {
    id: string;
    name: string;
    description: string | null;
    category: string | null;
    field_type: string;
    usage_count: number;
    is_platform: boolean;
}

export interface BuilderPageProps {
    form: {
        id: string;
        title: string;
        description: string | null;
        status: string;
        save_and_resume: boolean;
        // Raw schedule window + cap (Increment H12b) — the Schedule modal prefills from these. ISO instants
        // (rendered back into `timezone` for the datetime-local inputs); null when that bound is unset.
        opens_at: string | null;
        closes_at: string | null;
        timezone: string;
        max_responses: number | null;
        // The confirmation template + its locale variants (Increment H6a) — RAW, holes unfilled: an author
        // needs to see `${child_name}`, not a value there is no submission to supply. Null when unset, in
        // which case the runtime's built-in default stands.
        confirmation_message: string | null;
        confirmation_message_translations: Record<string, string>;
        default_locale: string;
        supported_locales: string[];
    };
    share: ShareProps;
    draft: { id: string; version_number: number } | null;
    sections: ServerSection[];
    fields: ServerField[];
    palette: PaletteGroup[];
    enums: BuilderEnums;
    library: LibraryItem[];
    // The canonical IANA identifier list for the Schedule modal's timezone select (Increment H12b).
    timezones: string[];
}

// A group in the canvas: an optional owning section plus its ordered fields. `section === null` is the
// implicit "ungrouped" bucket rendered first.
export interface CanvasGroup {
    section: LocalSection | null;
    fields: LocalField[];
}

export type Selection = { kind: 'field'; uid: Uid } | { kind: 'section'; uid: Uid } | null;
