/**
 * What the "Insert a question" picker offers at a spot (M178, `R-75719ac4`): the form's questions grouped by section,
 * each with its label AND its code, in the order the canvas shows them — filtered to what that spot can use.
 *
 * TWO GRAMMARS, TWO RULES, BOTH READ FROM TRANSMITTED FACTS:
 *   - a FORMULA spot (a calculation, a rule's expression, a condition as text) takes any question an expression may
 *     name — its operand kind `offered` (`OperandKind::offered()`: never a grid, a map, a note or a page break) — wherever
 *     it sits, since a formula may read a later answer. A calculation is left out: no condition, rule or calculation
 *     reads one before R4 (`D149`). "Today" is offered too; the other date presets need R4's date functions (`R-da02fe03`).
 *   - a TEXT spot (a label, help, a placeholder, a section's title or description, the thank-you message) takes only
 *     what the publish gate lets a template name — `pipeable` (`PipingEligibility`), and `TemplateScopeResolver`'s
 *     scope: a question strictly EARLIER than the text (positions `[section sequence | -1, sequence]`, a tie refused), and
 *     never one inside a repeating section unless the text sits in that same section. A section's own heading sits at
 *     `[sequence, -1]`, before every member, so it can only name earlier questions outside repeats; the thank-you message
 *     comes after the whole form. A calculation IS offered here: piping reads it.
 *
 * ⛔ The scope half mirrors PHP and is pinned against it by `tests/fixtures/template-scope.json`, run by
 * `tests/Unit/Templates/TemplateScopeParityTest.php` and this module's test — the server stays the authority at publish.
 */
import type { OperandKindOption, PaletteType } from './types';

export type ReferenceGrammar = 'expression' | 'template';

/** The few columns of a question the picker reads. */
export interface ReferenceField {
    key: string;
    label: string;
    field_type: string;
    form_section_id: string | null;
    sequence: number;
}

/** The few columns of a section the picker reads. */
export interface ReferenceSection {
    id: string;
    key: string;
    label: string;
    sequence: number;
    is_repeatable: boolean;
}

/** Where piped text sits. */
export type TemplateHost =
    | { kind: 'field'; key: string }
    | { kind: 'section'; key: string }
    | { kind: 'confirmation' };

export type ReferenceSpot = { grammar: 'expression'; ownKey: string | null } | { grammar: 'template'; host: TemplateHost };

export interface ReferenceSource {
    fields: ReferenceField[];
    sections: ReferenceSection[];
    types: PaletteType[];
    kinds?: OperandKindOption[];
}

export interface ReferenceCandidate {
    /** The question's code — or `today()` for the date preset. Unique in a list. */
    key: string;
    label: string;
    /** The section it sits in, as the canvas names it. */
    group: string;
    /** What the picker writes at the caret. */
    insert: string;
}

/** The group a question outside every section is listed under. */
export const LEAD_GROUP = 'Not in a section';
export const DATE_GROUP = 'Dates';
export const TODAY: ReferenceCandidate = { key: 'today()', label: 'Today', group: DATE_GROUP, insert: 'today()' };

type Position = [number, number];

function precedes(source: Position, host: Position): boolean {
    return source[0] !== host[0] ? source[0] < host[0] : source[1] < host[1];
}

export function referenceCandidates(source: ReferenceSource, spot: ReferenceSpot): ReferenceCandidate[] {
    const sectionById = new Map(source.sections.map((section) => [section.id, section]));
    const typeOf = (field: ReferenceField) => source.types.find((type) => type.value === field.field_type);
    const positionOf = (field: ReferenceField): Position => [sectionById.get(field.form_section_id ?? '')?.sequence ?? -1, field.sequence];
    const repeatOf = (field: ReferenceField): string | null => {
        const section = sectionById.get(field.form_section_id ?? '');

        return section?.is_repeatable ? section.key : null;
    };

    let accepts: (field: ReferenceField) => boolean;

    if (spot.grammar === 'expression') {
        accepts = (field) => {
            const kind = typeOf(field)?.operand_kind;
            const row = source.kinds?.find((candidate) => candidate.value === kind);

            return field.key !== spot.ownKey && kind !== undefined && kind !== 'computed' && (row === undefined || row.offered);
        };
    } else {
        const host = hostScope(spot.host, source, sectionById);
        accepts = (field) => {
            const repeat = repeatOf(field);

            return typeOf(field)?.pipeable === true
                && (repeat === null || repeat === host.repeat)
                && (host.position === null || precedes(positionOf(field), host.position));
        };
    }

    const ordered = [...source.fields].filter((field) => field.key !== '').sort((a, b) => {
        const [pa, pb] = [positionOf(a), positionOf(b)];

        return pa[0] - pb[0] || pa[1] - pb[1];
    });

    const candidates = ordered.filter(accepts).map((field) => {
        const section = sectionById.get(field.form_section_id ?? '');

        return {
            key: field.key,
            label: field.label.trim() === '' ? field.key : field.label,
            group: section === undefined ? LEAD_GROUP : section.label.trim() === '' ? section.key : section.label,
            insert: `\${${field.key}}`,
        };
    });

    return spot.grammar === 'expression' ? [...candidates, TODAY] : candidates;
}

function hostScope(
    host: TemplateHost,
    source: ReferenceSource,
    sectionById: Map<string, ReferenceSection>,
): { position: Position | null; repeat: string | null } {
    if (host.kind === 'confirmation') return { position: null, repeat: null };

    if (host.kind === 'section') {
        const section = source.sections.find((candidate) => candidate.key === host.key);

        return { position: [section?.sequence ?? -1, -1], repeat: section?.is_repeatable ? section.key : null };
    }

    const field = source.fields.find((candidate) => candidate.key === host.key);
    const section = sectionById.get(field?.form_section_id ?? '');

    return {
        position: [section?.sequence ?? -1, field?.sequence ?? -1],
        repeat: section?.is_repeatable ? section.key : null,
    };
}

/** The candidates a typed `@na` or `${na` still matches, by code or by label, in their order. */
export function matchCandidates(candidates: ReferenceCandidate[], query: string): ReferenceCandidate[] {
    const needle = query.toLowerCase();

    return candidates.filter((candidate) => candidate.key.toLowerCase().includes(needle) || candidate.label.toLowerCase().includes(needle));
}

/**
 * Whether the text before the caret ends in a request for a question: `@` at the start of a word (so an email address
 * is left alone), or `${` (never the escaped `$${`), followed by what has been typed of the code so far.
 */
export function findTrigger(before: string): { from: number; query: string } | null {
    const at = /(?!)@([A-Za-z0-9_]*)$/.exec(before);
    if (at !== null) return { from: before.length - at[1].length - 1, query: at[1] };

    const brace = /(?:^|[^$])\$\{([A-Za-z0-9_]*)$/.exec(before);
    if (brace !== null) return { from: before.length - brace[1].length - 2, query: brace[1] };

    return null;
}
