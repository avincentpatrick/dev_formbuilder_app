/**
 * AND/OR over a field's rules, as one all/any switch per rule list (M131, `R-799d60f5`, `D80`).
 *
 * ── WHAT THE ENGINES DO, WHICH IS WHAT THIS MAPS ONTO ────────────────────────────────────────────────
 * Both engines first split a field's rules into three FAMILIES — required (`required_if`/`required_with`), skip
 * (`skip_if`/`skip_with`) and constraint (everything else, raw expressions included) — then fold each `logic_group`
 * WITHIN its family, flat and left to right, each later member joining by its own `logic_operator`. Units then
 * combine by family: required and skip as ANY (one holding is enough), constraints as ALL (each is checked and
 * each failure is its own error). So "Required when ALL of these hold" is one `and` group, ANY is no group at
 * all; "the answer must pass ANY of these" is one `or` group, ALL is no group. No new column, no new grammar.
 *
 * ── THE PAYLOAD IS A TOKEN PER ROW, NOT A COMBINATOR PER LIST ───────────────────────────────────────
 * A combinator could not carry a grouping it does not understand, and a template or the question library can
 * make one (a group of some rules but not others, two groups, a group with AND and OR mixed). That grouping is
 * shown as `custom`, kept EXACTLY as it is, and only an explicit "Ungroup these rules" changes it (`D80`). A new
 * group gets a family token (`new-required`, …) that the server maps to `uuid5(field id, token)`, so it is the same
 * group on every save without the builder ever re-reading the field.
 *
 * ── ABSENT IS NOT NULL ───────────────────────────────────────────────────────────────────────────────
 * `logic_group`/`logic_operator` are optional on `BuilderValidation`: a row this client built without knowing about
 * groups (the negative-number floor) has neither, and the payload then omits them, which the server reads as
 * "keep what is stored". This module always reads them with `?? null`, and writes both, together.
 */
import type { BuilderValidation, RuleTypeOption } from './types';

export type RuleFamily = 'required' | 'skip' | 'constraint';

export type Combinator = 'all' | 'any';

export type Grouping =
    | { kind: 'plain'; combinator: Combinator }
    | { kind: 'grouped'; combinator: Combinator; token: string }
    | { kind: 'custom'; reason: string };

/** How a family's ungrouped units combine — and therefore which setting needs no group at all. */
export const FAMILY_DEFAULT: Readonly<Record<RuleFamily, Combinator>> = { required: 'any', skip: 'any', constraint: 'all' };

/** The token a family's NEW group is minted with; the server maps it to a uuid per field. */
export const NEW_GROUP_TOKEN: Readonly<Record<RuleFamily, string>> = {
    required: 'new-required',
    skip: 'new-skip',
    constraint: 'new-constraint',
};

const CONNECTIVE: Readonly<Record<Combinator, 'and' | 'or'>> = { all: 'and', any: 'or' };

const groupOf = (row: BuilderValidation): string | null => row.logic_group ?? null;
const connectiveOf = (row: BuilderValidation): 'and' | 'or' | null => row.logic_operator ?? null;

/**
 * The family a row is folded in, read off the TRANSMITTED facts (`governs_requiredness`, `governs_relevance`). A raw
 * expression is a constraint whatever its rule column says, which is the order `SemanticValidator::family()` tests.
 */
export function familyOf(row: BuilderValidation, ruleTypes: RuleTypeOption[]): RuleFamily {
    if (row.expression !== null && row.expression !== '') return 'constraint';

    const rule = ruleTypes.find((candidate) => candidate.value === row.rule_type);

    if (rule?.governs_requiredness === true) return 'required';
    if (rule?.governs_relevance === true) return 'skip';

    return 'constraint';
}

/**
 * What the switch can say about one family's rules in `rows` (the field's WHOLE array, so a group reaching into
 * another family is seen): `plain` — no group, the family's default; `grouped` — one group holding every rule of
 * the family and nothing else, every later member joined the same way; or `custom`, with the reason in words.
 */
export function describeGrouping(rows: BuilderValidation[], family: RuleFamily, ruleTypes: RuleTypeOption[]): Grouping {
    const members = rows.filter((row) => familyOf(row, ruleTypes) === family);
    const grouped = members.filter((row) => groupOf(row) !== null);

    if (grouped.length === 0) return { kind: 'plain', combinator: FAMILY_DEFAULT[family] };

    const tokens = new Set(grouped.map(groupOf));

    if (tokens.size > 1) return { kind: 'custom', reason: 'they are split into more than one group' };
    if (grouped.length !== members.length) return { kind: 'custom', reason: 'only some of them are grouped' };

    const token = grouped[0].logic_group as string;

    if (rows.some((row) => familyOf(row, ruleTypes) !== family && groupOf(row) === token)) {
        return { kind: 'custom', reason: 'the group also holds rules of another kind' };
    }

    // A one-rule group is a rule on its own, which is the family's default.
    if (members.length === 1) return { kind: 'plain', combinator: FAMILY_DEFAULT[family] };

    const joins = [...members].sort((a, b) => a.sequence - b.sequence).slice(1).map(connectiveOf);

    if (joins.some((join) => join === null)) return { kind: 'custom', reason: 'one of them does not say how it joins the others' };
    if (new Set(joins).size > 1) return { kind: 'custom', reason: 'they mix AND and OR' };

    return { kind: 'grouped', combinator: joins[0] === 'and' ? 'all' : 'any', token };
}

/** The combinator the switch shows for a family that is not `custom`. */
export function combinatorOf(grouping: Grouping): Combinator | null {
    return grouping.kind === 'custom' ? null : grouping.combinator;
}

/**
 * Set one family's combinator, touching no row of another family. The family's default ungroups it; the other
 * setting puts every rule of the family in one group, reusing the family's own token when it has one that no
 * other family shares, and writing the connective on every member — the first's is never read, but a group whose
 * members all agree is one `describeGrouping()` can read back.
 */
export function applyCombinator(
    rows: BuilderValidation[],
    family: RuleFamily,
    combinator: Combinator,
    ruleTypes: RuleTypeOption[],
): BuilderValidation[] {
    const inFamily = (row: BuilderValidation): boolean => familyOf(row, ruleTypes) === family;

    if (combinator === FAMILY_DEFAULT[family]) {
        return rows.map((row) => (inFamily(row) ? { ...row, logic_group: null, logic_operator: null } : row));
    }

    const own = rows.filter(inFamily).map(groupOf).find((token) => token !== null) ?? null;
    const shared = own !== null && rows.some((row) => !inFamily(row) && groupOf(row) === own);
    const token = own !== null && !shared ? own : NEW_GROUP_TOKEN[family];

    return rows.map((row) => (inFamily(row) ? { ...row, logic_group: token, logic_operator: CONNECTIVE[combinator] } : row));
}

/**
 * The group fields a row should carry after it was added to, or moved into, `family`: the family's group when the
 * family is grouped, nothing when it is plain. A `custom` family is left exactly as it is, so the new row stands
 * on its own and the notice keeps saying why.
 */
export function joinFamily(
    rows: BuilderValidation[],
    family: RuleFamily,
    ruleTypes: RuleTypeOption[],
): Pick<BuilderValidation, 'logic_group' | 'logic_operator'> {
    const grouping = describeGrouping(rows, family, ruleTypes);

    return grouping.kind === 'grouped'
        ? { logic_group: grouping.token, logic_operator: CONNECTIVE[grouping.combinator] }
        : { logic_group: null, logic_operator: null };
}
