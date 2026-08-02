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
 * The reason is §8's, and it is about H21d2 rather than about prose: round-tripping author-typed text
 * through a structured model and back is lossy in parenthesisation, whitespace and operand order, so the
 * condition editor must render anything it cannot represent READ-ONLY and never rewrite it. That decision
 * needs a predicate — "can this shape be represented?" — and this module is it, one increment early and in
 * its harmless direction. `status: 'opaque'` here becomes `read-only` there. Building the classifier twice,
 * once for prose and once for editing, is how the two would come to disagree about which expressions are
 * safe to touch.
 *
 * ── WHAT IT DOES NOT DO ─────────────────────────────────────────────────────────────────────────────
 * It never PRINTS an expression. There is no AST→text path anywhere in this repository (H1f verified it),
 * and H21d2 is the increment that must ship one. Prose is not syntax: nothing here can be fed back into a
 * parser, which is exactly why it is safe to run over expressions that will never be rewritten.
 *
 * It is also not a mirror of any PHP class. The reference walk below resembles
 * `ExpressionParser::referencedKeys()` and is deliberately NOT presented as its twin (R3): nothing
 * behavioural depends on it — it drives a chip on a card — and the authority on whether a reference
 * resolves remains `ExpressionValidationGate`, at publish, in PHP.
 */

import {
    ExpressionLexer,
    ExpressionParser,
    ExpressionSyntaxError,
    FunctionRegistry,
    type Node,
} from '../../../public-runtime/engine';

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

const parser = new ExpressionParser(new ExpressionLexer(), new FunctionRegistry());

export function describe(expression: string | null, labels: LabelLookup = {}): ConditionReading {
    if (expression === null || expression.trim() === '') {
        return { status: 'blank' };
    }

    let ast: Node;
    try {
        ast = parser.parse(expression);
    } catch (error) {
        if (error instanceof ExpressionSyntaxError) {
            return { status: 'invalid', slug: error.slug, reason: error.message };
        }
        // The lexer/parser throw nothing else, but a canvas must not be the thing that discovers otherwise
        // by unmounting itself. An unrecognised failure is still "this does not parse".
        return { status: 'invalid', slug: 'unknown', reason: 'This condition could not be read.' };
    }

    const references = collectReferences(ast);
    const prose = say(ast, labels);

    return prose === null ? { status: 'opaque', references } : { status: 'described', prose, references };
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
                // all (the parser rejects a `.` outside a constraint), so it is not a case worth naming.
        }
    };

    walk(node);

    return found;
}

/**
 * The describable shapes, and nothing else. Returns null the moment it meets anything it cannot render in
 * full — including one bad operand deep inside an otherwise readable `and` chain.
 *
 * The set is deliberately small and closed:
 *  - a comparison whose operands are each a bare reference or a literal;
 *  - `selected(${key}, 'value')` and its `not(...)` negation — the membership predicates;
 *  - `count(${section})` compared to a number, which is the repeat-group branch H21a made work;
 *  - `and` / `or` chains of the above, at any depth.
 *
 * Everything else — arithmetic operands, `if(...)`, `int(...)`, `today()`/`now()`, a comparison of two
 * function calls, a `not()` of a whole chain — is opaque BY DECISION rather than by omission. Each of those
 * has a reading that is either wrong or longer than the expression it explains, and the author already has
 * the expression.
 */
function say(node: Node, labels: LabelLookup): string | null {
    const clause = clauseOf(node, labels);

    return clause === null ? null : `Shown when ${clause}.`;
}

function clauseOf(node: Node, labels: LabelLookup): string | null {
    switch (node.type) {
        case 'logical': {
            const left = clauseOf(node.left, labels);
            const right = clauseOf(node.right, labels);
            if (left === null || right === null) return null;

            // Grouping is PRESERVED, not flattened. `(A or B) and C` and `A or (B and C)` are different
            // conditions and a reading that renders both as "A or B and C" is worse than no reading — it is
            // a confident wrong answer, which is the one failure mode §8 calls the disqualifier.
            const group = (child: Node, rendered: string): string =>
                child.type === 'logical' && child.op !== node.op ? `(${rendered})` : rendered;

            return `${group(node.left, left)} ${node.op} ${group(node.right, right)}`;
        }
        case 'comparison':
            return comparisonClause(node.op, node.left, node.right, labels);
        case 'call':
            return callClause(node, labels, false);
        case 'not':
            // Only a negated membership predicate reads cleanly. "not (A and B)" is a sentence about
            // sentences, and the honest rendering of it is the expression itself.
            return node.operand.type === 'call' ? callClause(node.operand, labels, true) : null;
        default:
            return null;
    }
}

const COMPARATORS: Record<string, string> = {
    eq: 'is',
    neq: 'is not',
    gt: 'is more than',
    lt: 'is less than',
    gte: 'is at least',
    lte: 'is at most',
};

/** The same six operators against a COUNT, where "is more than 0 entries" is not English. */
const COUNT_COMPARATORS: Record<string, string> = {
    eq: 'has exactly',
    neq: 'does not have exactly',
    gt: 'has more than',
    lt: 'has fewer than',
    gte: 'has at least',
    lte: 'has at most',
};

function comparisonClause(op: string, left: Node, right: Node, labels: LabelLookup): string | null {
    const comparator = COMPARATORS[op];
    if (comparator === undefined) return null;

    const counted = countClause(op, left, right, labels);
    if (counted !== null) return counted;

    const subject = operandOf(left, labels);
    if (subject === null) return null;

    // `${key} = ''` is the emptiness test, and "is blank" is what the author means by it — rendering it as
    // «is “”» would be technically faithful and practically useless. Tested on the NODE rather than on the
    // rendered string, so it cannot be spoofed by a literal that happens to render the same way.
    if (right.type === 'literal' && right.literalKind === 'string' && right.value === '') {
        return op === 'eq' ? `${subject} is blank` : op === 'neq' ? `${subject} is not blank` : null;
    }

    const object = operandOf(right, labels);

    return object === null ? null : `${subject} ${comparator} ${object}`;
}

/**
 * `count(${roster}) > 0` — the repeat-group branch, which read 0 in every relevance expression until H21a
 * fixed it (Doc #27 §3.3) and is therefore the shape an author is least likely to recognise on sight.
 *
 * Narrow on purpose. The count must be on the LEFT (the only side an author writes it, and inventing a
 * reading for the mirror image is guesswork) and the other operand must be a NUMBER LITERAL — "has more
 * than Membership tier entries" is not a sentence. Anything else falls through to the generic path, where
 * `operandOf` refuses a call and the whole clause goes opaque, which is the right degradation.
 *
 * Returns null to mean "not this shape", NOT "undescribable" — the caller continues rather than giving up.
 */
function countClause(op: string, left: Node, right: Node, labels: LabelLookup): string | null {
    if (left.type !== 'call' || left.name !== 'count' || left.args.length !== 1) return null;

    const counted = left.args[0];
    if (counted.type !== 'field') return null;
    if (right.type !== 'literal' || right.literalKind !== 'number') return null;

    const comparator = COUNT_COMPARATORS[op];
    if (comparator === undefined) return null;

    const n = Number(right.value);

    return `${labelFor(counted.key, labels)} ${comparator} ${n} ${n === 1 ? 'entry' : 'entries'}`;
}

/** A bare reference or a literal. Anything else (arithmetic, a nested call) makes the clause opaque. */
function operandOf(node: Node, labels: LabelLookup): string | null {
    if (node.type === 'field') return labelFor(node.key, labels);
    if (node.type !== 'literal') return null;

    return node.literalKind === 'number' ? String(node.value) : `“${String(node.value)}”`;
}

function callClause(node: Node, labels: LabelLookup, negated: boolean): string | null {
    if (node.type !== 'call') return null;

    // `selected(${key}, 'value')` — the only function that IS a condition on its own. `contains` is
    // internal to structured-rule lowering and never parseable, so it cannot appear in authored text.
    if (node.name !== 'selected' || node.args.length !== 2) return null;

    const [subject, choice] = node.args;
    if (subject.type !== 'field' || choice.type !== 'literal') return null;

    const verb = negated ? 'does not include' : 'includes';

    return `${labelFor(subject.key, labels)} ${verb} “${String(choice.value)}”`;
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
