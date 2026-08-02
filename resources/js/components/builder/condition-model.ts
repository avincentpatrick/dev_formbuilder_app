/**
 * The structured form of a `relevant_expression`, and the printer that turns one back into text
 * (Increment H21d2, Doc #27 §8 option (a)).
 *
 * ── WHY THIS MODULE IS THE CLASSIFIER ───────────────────────────────────────────────────────────────
 * H21d1 built the "can this shape be understood?" test in its harmless direction — as prose — and recorded
 * that H21d2 must not build it a second time for editing, because two classifications are how the rail and
 * the editor would come to disagree about which expressions are safe to touch. So the classification lives
 * HERE, once, as `toCondition()`, and `condition-describer.ts` now renders its sentence FROM the `Condition`
 * this module returns. Describable and representable are the same set by construction rather than by treaty.
 *
 * The price is worth stating plainly: this model must be EXACTLY as expressive as the set H21d1 shipped,
 * quirks included — a reversed comparison (`18 < ${age}`), a field-vs-field comparison (`${end} > ${start}`,
 * which H21d1 deliberately corrected INTO the described set), a literal-vs-literal one (`1 = 1`). Narrowing
 * the model to something tidier would silently narrow the RAIL's prose, which is not this increment's to
 * change. `condition-describer.test.ts` passes byte-unchanged, and that is the guarantee.
 *
 * ── THE PRINTER, AND WHY IT CHECKS ITSELF ───────────────────────────────────────────────────────────
 * This is the third place in the repository where expression syntax is CONSTRUCTED, against two parsers that
 * are held byte-identical by the golden corpus — the drift surface §8 names as the cost of option (a). Two
 * cheap defences: `serialize()` re-parses its own output and refuses to return text that does not round-trip
 * to the same `Condition`; and `tests/fixtures/condition-serializer.json` is cross-parsed by PHP's
 * `ExpressionParser` in `tests/Unit/Expressions/ConditionSerializerParityTest.php`, so text only the
 * TypeScript parser accepts is a red test rather than a draft that saves fine and refuses to publish.
 *
 * Grammar v2.0 also cannot express three things an author can type into a value box, and each is REFUSED
 * here rather than mangled — the lexer has no escape sequences at all, and no exponent form for numbers.
 */

import {
    ExpressionLexer,
    ExpressionParser,
    FunctionRegistry,
    type ComparisonOperator,
    type LogicOperator,
    type Node,
} from '../../../public-runtime/engine';

/** The six comparison operators the parser can produce. `is_null`/`contains` are lowering-only. */
export type Comparator = Extract<ComparisonOperator, 'eq' | 'neq' | 'gt' | 'lt' | 'gte' | 'lte'>;

/** One side of a comparison. Both sides are the same shape — see the docblock on why that is load-bearing. */
export type Operand =
    | { kind: 'field'; key: string }
    | { kind: 'number'; value: number }
    | { kind: 'text'; value: string };

export type Condition =
    /** `${a} > 18`, `18 < ${a}`, `${end} > ${start}` — any operand pairing the describer reads. */
    | { kind: 'compare'; op: Comparator; left: Operand; right: Operand }
    /** `${a} = ''` — the emptiness idiom, which reads as "is blank" and is only eq/neq. */
    | { kind: 'blank'; op: Extract<Comparator, 'eq' | 'neq'>; subject: Operand }
    /** `count(${roster}) > 0` — the count must be on the LEFT and the other side a number. */
    | { kind: 'count'; op: Comparator; section: string; n: number }
    /** `selected(${c}, 'red')` and its `not(...)` negation — the only negation form in this language. */
    | { kind: 'selected'; field: string; value: string; negated: boolean }
    /** An `and`/`or` chain, n-ary and flattened. Grouping is preserved between DIFFERING operators. */
    | { kind: 'group'; op: LogicOperator; children: Condition[] };

/** Why a `Condition` could not be printed. Every arm is a real limit of grammar v2.0, not a policy. */
export type SerializeRefusal =
    /** The value contains BOTH `'` and `"`, and the lexer has no escape sequences (`lexer.ts:151`). */
    | { reason: 'quotes'; value: string }
    /** Not finite, or its shortest decimal form needs exponent notation — which `lexNumber` cannot read. */
    | { reason: 'number'; value: number }
    /** Outside `[A-Za-z_][A-Za-z0-9_]*`, so `${…}` would lex as `malformed_reference`. */
    | { reason: 'key'; key: string }
    /** Nothing left to print — an empty group. The caller writes `null` into the column instead. */
    | { reason: 'empty' }
    /** The self-check failed: the text parsed back to something else, or not at all. Never expected. */
    | { reason: 'unparseable'; text: string };

export type SerializeResult =
    /** `condition` is the NORMALIZED model the text prints — adopt it, so the editor stays canonical. */
    | { text: string; condition: Condition; error?: undefined }
    | { error: SerializeRefusal; text?: undefined; condition?: undefined };

/**
 * One parser for the builder. Shared with `condition-describer.ts` rather than instantiated twice: the two
 * modules are halves of one reading, and a second registry is a second place a function list can drift.
 */
const parser = new ExpressionParser(new ExpressionLexer(), new FunctionRegistry());

/** Parse for the builder's own consumption. Throws `ExpressionSyntaxError` exactly as the engine does. */
export function parseExpression(expression: string): Node {
    return parser.parse(expression);
}

// ── AST → model ─────────────────────────────────────────────────────────────────────────────────────

const COMPARATORS = new Set<string>(['eq', 'neq', 'gt', 'lt', 'gte', 'lte']);

/**
 * THE classifier. Returns null the moment it meets a shape this model cannot hold — including one bad
 * operand deep inside an otherwise readable `and` chain, which is what makes the WHOLE reading opaque.
 *
 * The arms below mirror `clauseOf` in `condition-describer.ts` as H21d1 shipped it, in its order: the
 * comparator whitelist first, then the count shape, then the emptiness idiom, then the generic operands.
 * The order matters — `count(${r}) = ''` must fall through the count arm and then be refused, not read as
 * an emptiness test on a function call.
 */
export function toCondition(node: Node): Condition | null {
    switch (node.type) {
        case 'logical': {
            const left = toCondition(node.left);
            const right = toCondition(node.right);
            if (left === null || right === null) return null;

            // Flattened into an n-ary group. The parser is left-associative, and the describer already
            // renders same-operator nesting without parentheses, so flattening changes no sentence — while
            // giving the editor a list of rows to draw instead of a comb of nested pairs.
            return { kind: 'group', op: node.op, children: [...membersOf(left, node.op), ...membersOf(right, node.op)] };
        }
        case 'comparison':
            return comparisonCondition(node.op, node.left, node.right);
        case 'call':
            return selectedCondition(node, false);
        case 'not':
            // Only a negated membership predicate survives. `not(A and B)` is a condition about conditions,
            // and the honest rendering of it is the author's own text.
            return node.operand.type === 'call' ? selectedCondition(node.operand, true) : null;
        default:
            return null;
    }
}

function membersOf(condition: Condition, op: LogicOperator): Condition[] {
    return condition.kind === 'group' && condition.op === op ? condition.children : [condition];
}

function comparisonCondition(op: ComparisonOperator, left: Node, right: Node): Condition | null {
    if (!COMPARATORS.has(op)) return null;
    const comparator = op as Comparator;

    const counted = countCondition(comparator, left, right);
    if (counted !== null) return counted;

    const subject = operandOf(left);
    if (subject === null) return null;

    if (right.type === 'literal' && right.literalKind === 'string' && right.value === '') {
        return comparator === 'eq' || comparator === 'neq' ? { kind: 'blank', op: comparator, subject } : null;
    }

    const object = operandOf(right);

    return object === null ? null : { kind: 'compare', op: comparator, left: subject, right: object };
}

/** Returns null to mean "not this shape", NOT "unrepresentable" — the caller carries on. */
function countCondition(op: Comparator, left: Node, right: Node): Condition | null {
    if (left.type !== 'call' || left.name !== 'count' || left.args.length !== 1) return null;

    const counted = left.args[0];
    if (counted.type !== 'field') return null;
    if (right.type !== 'literal' || right.literalKind !== 'number') return null;

    return { kind: 'count', op, section: counted.key, n: Number(right.value) };
}

function selectedCondition(node: Node, negated: boolean): Condition | null {
    if (node.type !== 'call' || node.name !== 'selected' || node.args.length !== 2) return null;

    const [subject, choice] = node.args;
    if (subject.type !== 'field' || choice.type !== 'literal') return null;

    return { kind: 'selected', field: subject.key, value: String(choice.value), negated };
}

function operandOf(node: Node): Operand | null {
    if (node.type === 'field') return { kind: 'field', key: node.key };
    if (node.type !== 'literal') return null;

    return node.literalKind === 'number'
        ? { kind: 'number', value: Number(node.value) }
        : { kind: 'text', value: String(node.value) };
}

// ── model → canonical text ──────────────────────────────────────────────────────────────────────────

const SYMBOLS: Record<Comparator, string> = {
    eq: '=',
    neq: '!=',
    gt: '>',
    lt: '<',
    gte: '>=',
    lte: '<=',
};

/** Exactly `lexNumber`'s shape: digits, then at most one fractional part. No exponent, no sign, no bare dot. */
const NUMBER_TEXT = /^-?[0-9]+(\.[0-9]+)?$/;

/** Exactly `lexReference`'s shape, which is ASCII-only by construction on both sides of the engine. */
const KEY_TEXT = /^[A-Za-z_][A-Za-z0-9_]*$/;

class Refusal extends Error {
    constructor(readonly refusal: SerializeRefusal) {
        super('unprintable condition');
        this.name = 'Refusal';
    }
}

/**
 * Print a condition as canonical expression text, then PROVE it by parsing the result back and checking it
 * classifies to the same thing. The self-check is what turns the engine's budgets — 2000 UTF-8 bytes, 500
 * tokens, 64 levels of nesting — and every quoting hazard into a refusal at the source, rather than a draft
 * that saves cleanly and then fails `ExpressionValidationGate` at publish, where the author has lost the
 * context that would explain it.
 */
export function serialize(condition: Condition): SerializeResult {
    const normalized = normalize(condition);
    if (normalized === null) return { error: { reason: 'empty' } };

    let text: string;
    try {
        text = render(normalized);
    } catch (error) {
        if (error instanceof Refusal) return { error: error.refusal };
        throw error;
    }

    let reparsed: Condition | null;
    try {
        reparsed = toCondition(parser.parse(text));
    } catch {
        return { error: { reason: 'unparseable', text } };
    }

    if (reparsed === null || !sameCondition(reparsed, normalized)) {
        return { error: { reason: 'unparseable', text } };
    }

    return { text, condition: normalized };
}

/**
 * Canonicalize: flatten same-operator nesting, unwrap a group of one, drop a group of none. An author who
 * adds an "All of" group inside an "All of" group has expressed nothing the flat form does not, and the
 * printer must not emit text that parses back to a different tree than the one on screen.
 *
 * Returns null when nothing survives, which is how "the author emptied the editor" reaches the caller as a
 * request to write `null` into the column rather than an empty string.
 */
export function normalize(condition: Condition): Condition | null {
    if (condition.kind !== 'group') return condition;

    const children = condition.children.flatMap((child) => {
        const kept = normalize(child);
        if (kept === null) return [];

        return kept.kind === 'group' && kept.op === condition.op ? kept.children : [kept];
    });

    if (children.length === 0) return null;
    if (children.length === 1) return children[0];

    return { kind: 'group', op: condition.op, children };
}

/**
 * Whether every part of a condition has been filled in — the predicate that lets an editor hold a row an
 * author is halfway through building without writing it to the draft.
 *
 * "Unset" is encoded as an empty key/value and as `NaN` for a number, because a partially-built row is still
 * a `Condition` and giving the model a second nullable shape for it would leak the editor into the printer.
 * Both encodings are ALSO refused by `serialize()` on their own merits (an empty key is a malformed
 * reference, `NaN` is not a finite number), so this predicate is the friendly gate and not the only one.
 */
export function isComplete(condition: Condition): boolean {
    switch (condition.kind) {
        case 'group':
            return condition.children.length > 0 && condition.children.every(isComplete);
        case 'compare':
            return completeOperand(condition.left) && completeOperand(condition.right);
        case 'blank':
            // No right-hand side to fill in — `''` is the shape, not a value the author typed.
            return completeOperand(condition.subject);
        case 'count':
            return condition.section !== '' && Number.isFinite(condition.n);
        case 'selected':
            return condition.field !== '' && condition.value !== '';
    }
}

function completeOperand(operand: Operand): boolean {
    if (operand.kind === 'field') return operand.key !== '';
    if (operand.kind === 'number') return Number.isFinite(operand.value);

    return operand.value !== '';
}

function render(condition: Condition): string {
    switch (condition.kind) {
        case 'group':
            // A child group is parenthesised iff its operator DIFFERS from its parent's — the describer's
            // own rule, so the sentence and the syntax agree about grouping. (Same-operator nesting cannot
            // reach here: `normalize` has already flattened it.)
            return condition.children
                .map((child) => (child.kind === 'group' ? `(${render(child)})` : render(child)))
                .join(` ${condition.op} `);
        case 'compare':
            return `${operandText(condition.left)} ${SYMBOLS[condition.op]} ${operandText(condition.right)}`;
        case 'blank':
            return `${operandText(condition.subject)} ${SYMBOLS[condition.op]} ''`;
        case 'count':
            return `count(${keyText(condition.section)}) ${SYMBOLS[condition.op]} ${numberText(condition.n)}`;
        case 'selected': {
            const call = `selected(${keyText(condition.field)}, ${stringText(condition.value)})`;

            return condition.negated ? `not(${call})` : call;
        }
    }
}

function operandText(operand: Operand): string {
    switch (operand.kind) {
        case 'field':
            return keyText(operand.key);
        case 'number':
            return numberText(operand.value);
        case 'text':
            return stringText(operand.value);
    }
}

function keyText(key: string): string {
    if (!KEY_TEXT.test(key)) throw new Refusal({ reason: 'key', key });

    // Written by concatenation on purpose: a template literal holding `${` is a PHP-8.3-style trap in the
    // other direction — it reads as interpolation to every future editor of this file.
    return '${' + key + '}';
}

/**
 * `String(1e21)` is `"1e+21"` and `String(1e-7)` is `"1e-7"`; neither is a number to `lexNumber`, which
 * reads digits and at most one fractional part. A negative folds back into a negative Number literal via
 * the parser's unary-minus rule, so `-5` is safe and needs no parentheses.
 */
function numberText(value: number): string {
    const text = String(value);
    if (!Number.isFinite(value) || !NUMBER_TEXT.test(text)) throw new Refusal({ reason: 'number', value });

    return text;
}

/**
 * The lexer has NO escape sequences — a string literal runs to the first matching delimiter and a backslash
 * is an ordinary character. So a value containing one quote character is printed with the other, and a value
 * containing both is not expressible in grammar v2.0 at all and must be refused rather than mangled.
 */
function stringText(value: string): string {
    if (!value.includes("'")) return `'${value}'`;
    if (!value.includes('"')) return `"${value}"`;

    throw new Refusal({ reason: 'quotes', value });
}

// ── equality ────────────────────────────────────────────────────────────────────────────────────────

/**
 * Structural equality, written out rather than done with `JSON.stringify`: the two sides are built by
 * different paths (one by the editor, one by re-parsing the printed text) and key ORDER is not a fact about
 * a condition. A stringify comparison would report a difference that is not one, and — worse — could report
 * agreement between two objects that merely stringify alike.
 */
export function sameCondition(a: Condition, b: Condition): boolean {
    if (a.kind !== b.kind) return false;

    switch (a.kind) {
        case 'group': {
            const other = b as Extract<Condition, { kind: 'group' }>;

            return (
                a.op === other.op &&
                a.children.length === other.children.length &&
                a.children.every((child, i) => sameCondition(child, other.children[i]))
            );
        }
        case 'compare': {
            const other = b as Extract<Condition, { kind: 'compare' }>;

            return a.op === other.op && sameOperand(a.left, other.left) && sameOperand(a.right, other.right);
        }
        case 'blank': {
            const other = b as Extract<Condition, { kind: 'blank' }>;

            return a.op === other.op && sameOperand(a.subject, other.subject);
        }
        case 'count': {
            const other = b as Extract<Condition, { kind: 'count' }>;

            return a.op === other.op && a.section === other.section && a.n === other.n;
        }
        case 'selected': {
            const other = b as Extract<Condition, { kind: 'selected' }>;

            return a.field === other.field && a.value === other.value && a.negated === other.negated;
        }
    }
}

function sameOperand(a: Operand, b: Operand): boolean {
    if (a.kind !== b.kind) return false;
    if (a.kind === 'field') return a.key === (b as Extract<Operand, { kind: 'field' }>).key;

    return a.value === (b as Extract<Operand, { kind: 'number' | 'text' }>).value;
}
