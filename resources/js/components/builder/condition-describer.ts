/**
 * Reads a `relevant_expression` and says, in English, what it means — or admits it cannot (Increment H21d1,
 * Doc #27 §8).
 *
 * ── THE ONE RULE ────────────────────────────────────────────────────────────────────────────────────
 * A reading is produced ONLY when the whole expression is describable. There is no partial reading, no
 * "…and 2 more conditions", no paraphrase that drops a clause. An author who typed something this module
 * does not understand gets their own text back, unchanged and unannotated, and that is the correct outcome
 * rather than a degraded one.
 *
 * ── WHERE THE CLASSIFICATION LIVES (Increment H21d2) ────────────────────────────────────────────────
 * It is no longer here. H21d1 built the "can this shape be understood?" test fused into the prose — `say()`
 * returning null WAS "opaque" — and recorded that H21d2's editor needed the same predicate in its other
 * direction: render anything it cannot represent read-only, and never rewrite it. Building that twice is
 * exactly how the rail and the editor would come to disagree about which expressions are safe to touch.
 *
 * So `condition-model.ts` now owns it. `toCondition()` is the classifier; everything below renders a
 * SENTENCE from the `Condition` it returns, and `say()` is therefore total — there is no shape it can meet
 * and refuse, because a shape it could refuse would be a shape the editor thinks it can edit. Describable
 * and representable are one set by construction rather than by treaty.
 *
 * The describable set itself is UNCHANGED by that move, and `condition-describer.test.ts` passing
 * byte-unchanged is the guarantee. It stays deliberately small and closed:
 *  - a comparison whose operands are each a bare reference or a literal, in either order;
 *  - `selected()` and its `not()` negation — the membership predicates;
 *  - `count(${section})` compared to a number, which is the repeat-group branch H21a made work;
 *  - `and` / `or` chains of the above, at any depth, with grouping preserved rather than flattened.
 * Arithmetic, `if()`, `int()`, the clock functions and a `not()` over a whole chain are opaque BY DECISION
 * rather than by omission: each has a reading that is either wrong or longer than the expression it
 * explains, and the author already has the expression.
 *
 * ── WHAT IT DOES NOT DO ─────────────────────────────────────────────────────────────────────────────
 * It never PRINTS an expression. Prose is not syntax and cannot be fed back into a parser, which is exactly
 * why it is safe to run over expressions that will never be rewritten. The AST→text path is
 * `condition-model.ts`'s `serialize()`, which prints from the MODEL and never from a sentence.
 *
 * It is also not a mirror of any PHP class. The reference walk below resembles
 * `ExpressionParser::referencedKeys()` and is deliberately NOT presented as its twin (R3): nothing
 * behavioural depends on it — it drives a chip on a card — and the authority on whether a reference
 * resolves remains `ExpressionValidationGate`, at publish, in PHP.
 */

import { ExpressionSyntaxError, type Node } from '../../../public-runtime/engine';
import { parseExpression, toCondition, type Comparator, type Condition, type Operand } from './condition-model';

/** A field/section key → the label an author would recognise. Keys with no entry render as themselves. */
export type LabelLookup = Record<string, string>;

export type ConditionReading =
    /** No condition at all — the node is always shown. */
    | { status: 'blank' }
    /** Understood in full. `prose` is a complete sentence; `references` are the keys it names. */
    | { status: 'described'; prose: string; references: string[] }
    /** Parses, but says something this module cannot express in full. The raw text is the reading. */
    | { status: 'opaque'; references: string[] }
    /** Does not parse. `slug` is the engine's stable error slug; `reason` is its message. */
    | { status: 'invalid'; slug: string; reason: string };

export function describe(expression: string | null, labels: LabelLookup = {}): ConditionReading {
    if (expression === null || expression.trim() === '') {
        return { status: 'blank' };
    }

    let ast: Node;
    try {
        ast = parseExpression(expression);
    } catch (error) {
        if (error instanceof ExpressionSyntaxError) {
            return { status: 'invalid', slug: error.slug, reason: error.message };
        }
        // The lexer/parser throw nothing else, but a canvas must not be the thing that discovers otherwise
        // by unmounting itself. An unrecognised failure is still "this does not parse".
        return { status: 'invalid', slug: 'unknown', reason: 'This condition could not be read.' };
    }

    // The references are collected from the RAW AST, not from the model: an opaque condition still gets its
    // `${key}` chips, and that is the one thing the canvas can honestly say about a shape it cannot read.
    const references = collectReferences(ast);
    const condition = toCondition(ast);

    return condition === null
        ? { status: 'opaque', references }
        : { status: 'described', prose: say(condition, labels), references };
}

/** Every `${key}` the expression names, in first-appearance order and without duplicates. */
function collectReferences(node: Node): string[] {
    const found: string[] = [];

    const walk = (current: Node): void => {
        switch (current.type) {
            case 'field':
                if (!found.includes(current.key)) found.push(current.key);
                return;
            case 'comparison':
            case 'arithmetic':
            case 'logical':
                walk(current.left);
                walk(current.right);
                return;
            case 'not':
                walk(current.operand);
                return;
            case 'call':
                current.args.forEach(walk);
                return;
            default:
                // literal / self — nothing to collect. `self` cannot appear in a relevance expression at
                // all (it is refused at publish outside a constraint), so it is not a case worth naming.
        }
    };

    walk(node);

    return found;
}

function say(condition: Condition, labels: LabelLookup): string {
    return `Shown when ${clauseOf(condition, labels)}.`;
}

const COMPARATORS: Record<Comparator, string> = {
    eq: 'is',
    neq: 'is not',
    gt: 'is more than',
    lt: 'is less than',
    gte: 'is at least',
    lte: 'is at most',
};

/** The same six operators against a COUNT, where "is more than 0 entries" is not English. */
const COUNT_COMPARATORS: Record<Comparator, string> = {
    eq: 'has exactly',
    neq: 'does not have exactly',
    gt: 'has more than',
    lt: 'has fewer than',
    gte: 'has at least',
    lte: 'has at most',
};

function clauseOf(condition: Condition, labels: LabelLookup): string {
    switch (condition.kind) {
        case 'group':
            // Grouping is PRESERVED, not flattened. `(A or B) and C` and `A or (B and C)` are different
            // conditions and a reading that renders both as "A or B and C" is worse than no reading — it is
            // a confident wrong answer, which is the one failure mode §8 calls the disqualifier. A child
            // group always carries the OTHER operator (`normalize` flattens same-operator nesting), so it
            // always needs its parentheses.
            return condition.children
                .map((child) => (child.kind === 'group' ? `(${clauseOf(child, labels)})` : clauseOf(child, labels)))
                .join(` ${condition.op} `);
        case 'compare':
            return `${operandOf(condition.left, labels)} ${COMPARATORS[condition.op]} ${operandOf(condition.right, labels)}`;
        case 'blank':
            // `${key} = ''` is the emptiness test, and "is blank" is what the author means by it — rendering
            // it as «is “”» would be technically faithful and practically useless.
            return `${operandOf(condition.subject, labels)} ${condition.op === 'eq' ? 'is blank' : 'is not blank'}`;
        case 'count': {
            // `count(${roster}) > 0` — the repeat-group branch, which read 0 in every relevance expression
            // until H21a fixed it (Doc #27 §3.3) and is therefore the shape an author is least likely to
            // recognise on sight.
            const n = condition.n;

            return `${labelFor(condition.section, labels)} ${COUNT_COMPARATORS[condition.op]} ${n} ${n === 1 ? 'entry' : 'entries'}`;
        }
        case 'selected':
            return `${labelFor(condition.field, labels)} ${condition.negated ? 'does not include' : 'includes'} “${condition.value}”`;
    }
}

function operandOf(operand: Operand, labels: LabelLookup): string {
    switch (operand.kind) {
        case 'field':
            return labelFor(operand.key, labels);
        case 'number':
            return String(operand.value);
        case 'text':
            return `“${operand.value}”`;
    }
}

/**
 * A key rendered for a reader. An unknown key renders as ITSELF rather than as a placeholder: a dangling
 * reference is a real publish-gate refusal the author needs to recognise by name, and "unknown field" would
 * hide the one piece of information that lets them find it.
 */
function labelFor(key: string, labels: LabelLookup): string {
    const label = labels[key];

    return label === undefined || label.trim() === '' ? key : label.trim();
}
