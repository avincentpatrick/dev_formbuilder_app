// Shared builder types (Increment D4a). The server shapes mirror BuilderPresenter's output; the local
// shapes add a stable client `uid` decoupled from the server `id`, so the undo/redo command stack can
// keep referring to a field/section even across a delete→undo cycle that mints a brand-new server row.
//
// `ShareProps` MOVED OUT to `@/components/forms/types` in J2b — the form hub renders the same block, so the
// payload stopped being the builder's. It is imported here rather than re-exported: one live import path
// per type, so two pages cannot end up depending on two names for one shape.

import type { BreadcrumbItem } from '@meridian/design-system';

import type { AutomationsProps, DataSharingProps, OcrScanningProps, RedirectKind, RedirectTargetOption, ReferenceFileRow, ShareProps, ThemePresetOption } from '@/components/forms/types';

export type Uid = string;

export interface BuilderValidation {
    rule_type: string | null;
    operator: string | null;
    rule_value: string | null;
    expression: string | null;
    error_message: string | null;
    related_field_key: string | null;
    // M131 (`R-799d60f5`) — the rule's group (an existing uuid, or a `new-<family>` token) and how it joins it.
    // OPTIONAL ON PURPOSE: a row built by code that knows nothing of groups has neither, the payload then omits
    // both, and the server keeps what is stored. `rule-grouping.ts` reads them with `?? null`.
    logic_group?: string | null;
    logic_operator?: 'and' | 'or' | null;
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
    // M134 (`R-6d7b9ff7`) — whether `default_value` is a formula (an XLSForm `calculation`), so undo restores it as one.
    default_value_is_expression: boolean;
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
    // M134 (`R-910d2286`) — `OperandKind::for()` of the field's type, from its palette entry: what an expression may
    // compare it with. Optional: a hand-built catalogue carries none, and absent reads as "offer everything".
    operand_kind?: string;
}

export interface ConditionCatalogue {
    fields: ConditionFieldOption[];
    repeatables: { key: string; label: string }[];
    // M134 — the capability row of every operand kind, `BuilderEnums.operand_kinds` passed through. Optional, as above.
    kinds?: OperandKindOption[];
}

// What a question of one operand kind may be compared with in a condition (M134, `R-910d2286`) — transmitted from
// `OperandKind`'s methods, the same enum the publish gate judges an expression by, so the condition editor offers a
// subset of what publish accepts and never restates which types order or equal what.
export interface OperandKindOption {
    value: string;
    // Whether a condition may name such a question at all — never a note, a page break, a grid or a point.
    offered: boolean;
    // more than / less than / at least / at most.
    orders: boolean;
    // is / is not — never for a list or a file, whose answer never equals one value.
    equals: boolean;
    // includes / does not include — `selected()`.
    includes: boolean;
    // The kinds an ordering may compare it with — a date only with a date or a date-time, a time only with a time.
    orders_with: string[];
    // The input a fixed value beside it uses — `date`, `time` or `datetime-local` — or null for the default.
    literal_input: 'date' | 'time' | 'datetime-local' | null;
}

export interface PaletteType {
    value: string;
    label: string;
    advanced: boolean;
    has_options: boolean;
    // The dedicated config editor this type needs beyond the shared tabs (G4a), or null. Mirrors
    // FieldType::configEditor(), which holds the list of values; the config panel keys its editor tab off this.
    config_editor: string | null;
    // What may be ASSERTED about this type's value (M115) — `ValueShape::for()`'s thirteen-member partition,
    // not a thirty-first field-type special case. The config panel matches it against each rule type's
    // `shapes` to decide what the Validation tab may offer, and hides that tab entirely for 'no_answer'.
    value_shape: string;
    // M134 (`R-910d2286`) — what an EXPRESSION may compare this type's answer with, `OperandKind::for()` transmitted.
    operand_kind: string;
    // The palette group this type is one variant of (M125) — `FieldVariantGroup` via BuilderPresenter, or null.
    // The palette shows ONE entry per group (its primary) and the Basics tab switches between the members.
    // Optional: a hand-built palette in a test may carry none, and absent reads as ungrouped.
    variant?: PaletteVariant | null;
    // The layouts an author may choose for this type (M130) — `FieldAppearance::for()` via BuilderPresenter. Empty
    // for a type with no layout setting; optional for the same reason as `variant`, and absent reads as empty.
    appearances?: EnumOption[];
}

export interface PaletteVariant {
    group: string;
    label: string;
    primary: boolean;
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

// One validation rule kind as the server offers it (M115). Mirrors BuilderPresenter::enums() —
// TRANSMITTED DATA regenerated per page load, not a client-side table: the editor filters with `shapes`
// and never re-states which rules suit which field, which is the defect `R-e878d49a` filed.
export interface RuleTypeOption extends EnumOption {
    // ValueShape values whose `allows()` is true — the same table the publish gate refuses on, so the
    // author surface and the gate cannot disagree about what is buildable.
    shapes: string[];
    // Whether the `operator` column is READ for this rule. Only the four conditional kinds read it; for
    // the other seven both lowerings throw, so an operator control beside them changes nothing.
    takes_operator: boolean;
    // Whether the rule names a SECOND field (`related_field_key`). Six do — the four conditionals plus the
    // two field comparisons — and before M115 the editor gave a control to only two of them.
    takes_related_field: boolean;
    // Whether an ABSENT operator is itself a condition ("when that question is answered at all"), which is
    // true for `required_with`/`skip_with` and a broken row for `required_if`/`skip_if`.
    operator_may_be_empty: boolean;
    // M131 (`R-57711a3a`) — the operator a compared question is judged by when the row stores none
    // (`ValidationRuleType::relatedComparison(null)`): `gt`/`lt` for the field comparisons, `is_null` for an
    // empty `_with`, null otherwise. `validation-options.ts` filters the compared-question list by it.
    related_comparison: string | null;
    // Whether this rule is what makes `Conditional` requiredness mean something (M116) — the `required`
    // bucket of `SemanticValidator::family()`, which is `required_if`/`required_with` and NOT the skip pair:
    // a skip rule makes a field irrelevant, never required. The Basics tab's "Required when…" reveal shows
    // exactly the rows where this is true, so it and `requiredState()` cannot disagree about which rows
    // count. ⚠️ Read it; never re-state the two names here.
    governs_requiredness: boolean;
    // M131 (`R-799d60f5`) — the skip pair. With `governs_requiredness` it splits the rules into the three families
    // both engines fold a group within; `rule-grouping.ts` reads the two, never a list of names.
    governs_relevance: boolean;
}

// One comparison operator as a rule row shows it (M115). `label` carries its symbol inside the string
// (`at most (≤)`) so no caller can render the symbol without the words or pair them wrongly — `D59`.
//
// `D59`'s OTHER rendering, the sentence form, is not transmitted: its consumers are the condition editor's
// own maps, which cover a wider vocabulary than this enum (`not_blank` and `excludes` have no PHP case), and
// `tests/Unit/Forms/ConditionLabelMirrorDriftTest.php` is what holds those to `sentenceLabel()`.
export interface OperatorOption extends EnumOption {
    // ⚠️ The shapes of the RELATED field, never the rule's owner: a conditional rule's operator compares
    // the value of the field the rule NAMES.
    shapes: string[];
}

export interface BuilderEnums {
    required_modes: EnumOption[];
    indexed_data_types: EnumOption[];
    validation_rule_types: RuleTypeOption[];
    comparison_operators: OperatorOption[];
    // M133 (`R-5da4a30f`) — the question types that may take their choices from another form,
    // `LinkedChoiceService::LINKABLE_TYPES` transmitted. Optional: a hand-built enum block in a test offers none.
    linked_choice_types?: string[];
    // M134 (`R-910d2286`) — one row per `OperandKind`, what the condition editor may offer. Optional: a hand-built
    // enum block in a test offers none, and the editor then filters nothing.
    operand_kinds?: OperandKindOption[];
}

/** A choice question's link to another form's answers (M133) — `config.options_source`; either half may be unpicked yet. */
export interface OptionsSource {
    form_id: string | null;
    field_key: string | null;
}

/** A form a choice question may take its choices from, with the questions it shares (M133, `linkable_sources`). */
export interface LinkableSource {
    id: string;
    title: string;
    questions: { key: string; label: string }[];
}

// A sibling field a validation row may name (M115) — for the six rule types that compare against a second
// question. Kept separate from `ConditionFieldOption`: that one carries `numeric`, a four-member field-type
// set the validation editor must NOT filter operators with (`FieldTypeMirrorDriftTest` pins it as a
// declared divergence from `ValueShape::allowsOperator()`'s five, and `R-c1f90d7a` owns reconciling it).
export interface ComparableField {
    key: string;
    label: string;
    value_shape: string;
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
        // Presentation mode (`D35`): true renders every section in one scroll, false one section per step.
        // Read by the Pages settings section and by `PreviewPane`, which feeds it into the projection so
        // the preview renders the mode the author actually chose.
        single_page_mode: boolean;
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
        // M130 (`D76`) — where a respondent goes after the thank-you screen (FormSettingsForm says the rest).
        redirect_kind: RedirectKind;
        redirect_form_id: string | null;
        redirect_url: string | null;
        redirect_targets: RedirectTargetOption[];
        // M131 — the preset theme (FormSettingsForm says the rest); `PreviewPane` paints it on the preview.
        theme_preset: string | null;
        theme_presets: ThemePresetOption[];
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
    /**
     * The Scanning settings section's facts (M129), or null where the workspace cannot scan. OPTIONAL, so a
     * builder fixture written before it existed still type-checks — the absent-means-off reading the modal
     * applies to it with `!= null`.
     */
    ocr_scanning?: OcrScanningProps | null;
    /** M132 — the Reference files section's list: the draft's files. Optional, so a fixture without it type-checks. */
    reference_files?: ReferenceFileRow[];
    /** M132 — the Automations section. Optional, so a fixture without it type-checks. */
    automations?: AutomationsProps;
    /** M133 — the Data sharing section, or null for a reader who cannot read the responses (`D87`). Optional. */
    data_sharing?: DataSharingProps | null;
    /** M133 — the forms a choice question may take its choices from. Optional, so a fixture without it offers none. */
    linkable_sources?: LinkableSource[];
    /**
     * The toolbar's path trail, resolved SERVER-SIDE by `CrumbTrail` (Increment J2d).
     *
     * The builder takes the breadcrumb and NOT the tab strip (user decision, J2b) — it is a three-pane
     * workspace on a `height: 100%` grid and a second header row costs the one screen that cannot spare it —
     * so this trail is its only way back, which is why the hub crumb being hard-coded mattered:
     * `can:update,form` does not imply `viewOverview`, it merely coincides with it across the shipped roles.
     */
    crumbs: BreadcrumbItem[];
}

// A group in the canvas: an optional owning section plus its ordered fields. `section === null` is the
// implicit "ungrouped" bucket rendered first.
export interface CanvasGroup {
    section: LocalSection | null;
    fields: LocalField[];
}

export type Selection = { kind: 'field'; uid: Uid } | { kind: 'section'; uid: Uid } | null;

/**
 * The builder's EXPLICIT save verdict (`useBuilderStore`).
 *
 * Lives here rather than in the store so the toolbar's label module can read it without importing the
 * 850-line store and its whole module graph.
 *
 * `idle` and `saved` are distinct FACTS even though the toolbar renders them identically today: `idle` is
 * "this session has written nothing", `saved` is "everything this session wrote, landed". `failed` is
 * reached by a rejected write OR by an open 409 conflict -- in both, the server does not hold what is on
 * screen. Mirrors `AutosaveState` in `@/composables/useServerAutosave`, minus the two states the builder
 * has no path to (there is no lost-update baseline here and no session-expiry stop).
 */
export type SaveState = 'idle' | 'saving' | 'saved' | 'failed';
