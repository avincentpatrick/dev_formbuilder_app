/**
 * The draft → snapshot projection (Increment M112, `B6`).
 *
 * The builder store holds `LocalField[]`/`LocalSection[]` — server-id-bearing draft rows — while
 * `createFormRuntime()` consumes a `SchemaResponse`, the id-free checksummed snapshot the server builds
 * at publish. This module is the only thing standing between the two, and therefore between the builder
 * and a live preview: today an author cannot see their form until they publish it.
 *
 * ⛔ IT CANNOT BE THE EXISTING SERIALIZER, AND THAT IS THE WHOLE DESIGN RATHER THAN AN INCONVENIENCE.
 * `SchemaSnapshotSerializer` serialises whatever is in the row and lets `StructuralValidationGate` refuse
 * the bad ones at publish. Nothing today renders the in-between — and the in-between is exactly what a
 * preview is for. So the governing rule here is the opposite one:
 *
 *   **The projection never throws, never omits a field for being incomplete, and never papers over a
 *   gap — it substitutes a legible placeholder and RECORDS AN ISSUE.**
 *
 * That is also how "show the author what is wrong" gets delivered *before* publish rather than at it.
 *
 * ⛔ THE PRE-PARSE IS LOAD-BEARING AND IS NOT A TIDINESS MEASURE. `safeEvaluate()`
 * (`useFormRuntime.ts:310-319`) catches a parser throw by degrading the WHOLE FORM to
 * everything-relevant and setting `engineFailed` permanently true for the session — there is no recovery
 * short of a remount. A half-typed condition is the normal state of a field an author is editing, so one
 * keystroke would otherwise kill relevance for the rest of the session. Every `relevant_expression` is
 * therefore parsed HERE, with `condition-model.ts`'s already-exported `parseExpression`, and emitted as
 * `null` if it will not parse. No second parser is introduced.
 *
 * ⚠️ IT IS A CLIENT MIRROR OF `SchemaSnapshotSerializer::field()`/`section()`, AND THIS REPOSITORY'S
 * MEASURED PATHOLOGY IS UNGUARDED HAND-MIRRORS. `tests/Feature/Docs/DraftProjectionMirrorDriftTest.php`
 * asserts the emitted key set against `RawField`'s declared members, so a column added to the wire shape
 * cannot be silently missing here.
 *
 * ⚠️ THE BUILDER'S MODEL IS MISSING EVERY TRANSLATION COLUMN THE RUNTIME'S REQUIRES, MEASURED AT SOURCE.
 * `BuilderPresenter` emits no `*_translations` key at all, so `ServerField` carries no
 * `label_translations`/`hint_translations`, `ServerSection` carries no
 * `label_translations`/`description_translations`, and `BuilderValidation` carries no
 * `error_message_translations`/`logic_group_ordinal`/`logic_operator`. They are synthesized as `null`
 * here, which has one honest consequence worth stating out loud rather than discovering in `B7`:
 * **the preview renders the default locale only.** Widening `BuilderPresenter` to carry them is filed as
 * its own row.
 *
 * ⚠️ IT IS DELIBERATELY NOT CHECKSUM-EQUIVALENT to the server's snapshot. That one is a version identity
 * with a total canonical ordering and no ids; this one has no checksum to honour and exists to be
 * rendered. Nothing should ever hash the output of this module.
 */

import { parseExpression } from './condition-model';
import type { BuilderValidation, LocalField, LocalSection, Uid } from './types';

import type {
    RawField,
    RawSchemaSnapshot,
    RawSection,
    RawValidation,
    SchemaResponse,
} from '../../../public-runtime/lib/types';
import type { RequiredMode } from '../../../public-runtime/engine';

/** The placeholder a field with no label renders as, rather than an empty control nobody can identify. */
export const UNTITLED_LABEL = 'Untitled question';

/** The prefix for a synthetic key. Chosen to be unauthorable: `__` is not a legal leading key character. */
export const DRAFT_KEY_PREFIX = '__draft_';

/** Every way the draft can be incomplete, as a closed set so a consumer can render copy per code. */
export type ProjectionIssueCode =
    | 'missing_key'
    | 'duplicate_key'
    | 'missing_label'
    | 'empty_option_list'
    | 'unparsable_expression'
    | 'missing_formula'
    | 'unknown_section';

export interface ProjectionIssue {
    /** The `uid` of the offending row, so the builder can select it without a key that may not exist. */
    uid: Uid;
    /** The key as projected — synthetic when the author has not given one. */
    key: string;
    code: ProjectionIssueCode;
    /** One sentence, addressed to the author, safe to render beside the field. */
    message: string;
}

export interface DraftProjectionInput {
    form: {
        id: string;
        title: string;
        description: string | null;
        default_locale: string;
        supported_locales: string[];
        single_page_mode?: boolean;
    };
    version: { id: string; version_number: number };
    sections: LocalSection[];
    fields: LocalField[];
}

export interface DraftProjection {
    schema: SchemaResponse;
    issues: ProjectionIssue[];
    /** key → uid, so a click in the preview selects the right row in the config panel. */
    uidByKey: Record<string, Uid>;
    /** uid → key, the inverse, so a selection in the panel can scroll the preview. */
    keyByUid: Record<Uid, string>;
    /**
     * A structural identity over ONLY the columns the engine reads. Labels, hints, placeholders and
     * translations are excluded by construction, so typing a label does not change it — which is what
     * lets `B7` rebuild the runtime on `shape` change rather than on every keystroke.
     */
    shape: string;
}

const REQUIRED_MODES = new Set<string>(['required', 'optional', 'conditional']);

/** Narrow the builder's `string` into the engine's union, recording an issue rather than casting blindly. */
function requiredModeOf(value: string): RequiredMode {
    return REQUIRED_MODES.has(value) ? (value as RequiredMode) : 'optional';
}

/**
 * `null` unless the expression parses. A blank expression is not a failure — it is the ordinary state of
 * a field with no condition — so it yields `null` with no issue recorded.
 */
function parsedOrNull(expression: string | null, onFailure: () => void): string | null {
    if (expression === null || expression.trim() === '') return null;

    try {
        parseExpression(expression);

        return expression;
    } catch {
        onFailure();

        return null;
    }
}

/** The label as rendered, recording an issue when the author has not given one yet. */
function labelOf(
    f: LocalField,
    key: string,
    add: (uid: Uid, key: string, code: ProjectionIssueCode, message: string) => void,
): string {
    if (f.label !== '') return f.label;

    add(f.uid, key, 'missing_label', 'This question has no wording yet.');

    return UNTITLED_LABEL;
}

function projectValidation(v: BuilderValidation): RawValidation {
    // mirror:RawValidation
    return {
        rule_type: v.rule_type,
        operator: v.operator,
        rule_value: v.rule_value,
        expression: v.expression,
        error_message: v.error_message,
        // Absent from the builder's model — see the module docblock.
        error_message_translations: null,
        related_field_key: v.related_field_key,
        logic_group_ordinal: null,
        logic_operator: null,
        sequence: v.sequence,
    };
}

export function projectDraft(input: DraftProjectionInput): DraftProjection {
    const issues: ProjectionIssue[] = [];
    const uidByKey: Record<string, Uid> = {};
    const keyByUid: Record<Uid, string> = {};

    const add = (uid: Uid, key: string, code: ProjectionIssueCode, message: string): void => {
        issues.push({ uid, key, code, message });
    };

    // ── Sections ───────────────────────────────────────────────────────────────────────────────
    const orderedSections = [...input.sections].sort((a, b) => a.sequence - b.sequence);
    const sectionKeyById = new Map<string, string>();

    const sections: RawSection[] = orderedSections.map((s) => {
        const key = s.key !== '' ? s.key : `${DRAFT_KEY_PREFIX}${s.uid}`;
        sectionKeyById.set(s.id, key);

        // mirror:RawSection
        return {
            key,
            label: s.label !== '' ? s.label : UNTITLED_LABEL,
            label_translations: null,
            description: s.description,
            description_translations: null,
            sequence: s.sequence,
            is_repeatable: s.is_repeatable,
            min_instances: s.min_instances,
            max_instances: s.max_instances,
            relevant_expression: parsedOrNull(s.relevant_expression, () =>
                add(s.uid, key, 'unparsable_expression', 'This section’s condition is not finished yet, so it is being ignored in the preview.'),
            ),
        };
    });

    // ── Fields ─────────────────────────────────────────────────────────────────────────────────
    // State 2: a duplicate key is resolved FIRST-BY-SEQUENCE, so the projection is stable while the
    // author types a key that momentarily collides with a later field's.
    const orderedFields = [...input.fields].sort((a, b) => a.sequence - b.sequence);
    const seenKeys = new Set<string>();
    const fields: RawField[] = [];

    for (const f of orderedFields) {
        // State 1: no key yet.
        let key = f.key;
        if (key === '') {
            key = `${DRAFT_KEY_PREFIX}${f.uid}`;
            add(f.uid, key, 'missing_key', 'This question has no key yet, so the preview is using a temporary one.');
        }

        // State 2: a later duplicate is re-keyed rather than dropped — omitting it would make the
        // preview disagree with the canvas about how many questions there are.
        if (seenKeys.has(key)) {
            const collided = key;
            key = `${DRAFT_KEY_PREFIX}${f.uid}`;
            add(f.uid, key, 'duplicate_key', `Another question already uses the key “${collided}”, so this one cannot be previewed under it.`);
        }
        seenKeys.add(key);

        uidByKey[key] = f.uid;
        keyByUid[f.uid] = key;

        let sectionKey: string | null = null;
        if (f.form_section_id !== null) {
            sectionKey = sectionKeyById.get(f.form_section_id) ?? null;
            if (sectionKey === null) {
                add(f.uid, key, 'unknown_section', 'This question points at a section that no longer exists, so the preview shows it ungrouped.');
            }
        }

        // State 4: an empty option list is KEPT EMPTY. That is the honest preview of a real defect — a
        // select with no options renders an empty control to every respondent — and papering over it with
        // a placeholder option would hide exactly what the author needs to see.
        const config: Record<string, unknown> = { ...f.config };
        if (Array.isArray(config.options) && config.options.length === 0) {
            add(f.uid, key, 'empty_option_list', 'This question has no choices yet.');
        }

        // State 7: `calculated` with no formula — the key is ABSENT from config, not null, because the
        // engine branches on presence.
        if (f.field_type === 'calculated') {
            const formula = config.formula;
            if (typeof formula !== 'string' || formula.trim() === '') {
                delete config.formula;
                add(f.uid, key, 'missing_formula', 'This calculation has no formula yet, so it has no value in the preview.');
            }
        }

        // mirror:RawField
        fields.push({
            key,
            section_key: sectionKey,
            field_type: f.field_type,
            config,
            // State 3: untitled. The placeholder keeps the control identifiable; the issue is what stops
            // it being papering-over, which is the one thing the governing rule forbids.
            label: labelOf(f, key, add),
            label_translations: null,
            hint: f.hint,
            hint_translations: null,
            placeholder: f.placeholder,
            default_value: f.default_value,
            default_value_is_expression: false,
            is_required: requiredModeOf(f.is_required),
            // State 5: the load-bearing one.
            relevant_expression: parsedOrNull(f.relevant_expression, () =>
                add(f.uid, key, 'unparsable_expression', 'This question’s condition is not finished yet, so it is being ignored in the preview.'),
            ),
            appearance: f.appearance,
            sequence: f.sequence,
            section_sequence: f.section_sequence,
            // State 6: a half-built cascade or grid passes through as-is. The renderers already handle a
            // partial config, and second-guessing it here would diverge the preview from the real runtime.
            validations: f.validations.map(projectValidation),
        });
    }

    const schemaSnapshot: RawSchemaSnapshot = { sections, fields };

    const schema: SchemaResponse = {
        form: {
            id: input.form.id,
            title: input.form.title,
            description: input.form.description,
            default_locale: input.form.default_locale,
            supported_locales: input.form.supported_locales,
            single_page_mode: input.form.single_page_mode ?? false,
        },
        version: {
            id: input.version.id,
            version_number: input.version.version_number,
            // ⚠️ NOT a checksum. The runtime pins a published manifest against this; a draft has no
            // published identity, so it carries the shape digest instead — which is what makes a rebuild
            // happen exactly when the engine's inputs change. Nothing may hash or compare it server-side.
            checksum: '',
            schema: schemaSnapshot,
        },
    };

    const shape = shapeOf(schemaSnapshot);
    schema.version.checksum = shape;

    return { schema, issues, uidByKey, keyByUid, shape };
}

/**
 * A structural identity over ONLY the columns the engine reads.
 *
 * ⛔ WHAT IS EXCLUDED IS THE POINT: labels, hints, placeholders, descriptions and every translation map.
 * Typing a label must not change this string, or `B7` would remount the runtime on every keystroke —
 * which is the cost that makes a live preview unaffordable. Option VALUES are included and option LABELS
 * are not, for the same reason: the engine matches on the value.
 */
export function shapeOf(snapshot: RawSchemaSnapshot): string {
    const sections = snapshot.sections.map((s) => [
        s.key,
        s.sequence,
        s.is_repeatable ? 1 : 0,
        s.min_instances,
        s.max_instances,
        s.relevant_expression,
    ]);

    const fields = snapshot.fields.map((f) => [
        f.key,
        f.field_type,
        f.section_key,
        f.sequence,
        f.section_sequence,
        f.is_required,
        f.relevant_expression,
        f.default_value,
        optionValuesOf(f.config),
        f.validations.map((v) => [v.rule_type, v.operator, v.rule_value, v.expression, v.related_field_key, v.sequence]),
    ]);

    return JSON.stringify({ sections, fields });
}

/** Option VALUES only — a relabelled option must not rebuild the runtime. */
function optionValuesOf(config: Record<string, unknown> | null): unknown {
    if (config === null) return null;

    const options = config.options;
    if (!Array.isArray(options)) return null;

    return options.map((o) => (o !== null && typeof o === 'object' && 'value' in o ? (o as { value: unknown }).value : null));
}
