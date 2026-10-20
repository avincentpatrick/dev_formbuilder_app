/**
 * The calculation editor's presets (M180, `R-f77aa45d`, `D155`, `D156`) — a calculation built by clicking. The user
 * (2026-10-16): *"create a calculated field using the form's own questions by clicking or selecting, not by purely
 * typing … make sure that formula can still be typed … put presets"*, with Fillout's calculation screens.
 *
 * ONE SOURCE OF TRUTH: the formula. A preset WRITES a canonical formula (`presetFormula()`), and a formula in exactly
 * that shape READS BACK as the preset (`parsePreset()`, on the engine's own parser), so nothing else is stored and the
 * editor reopens on Build it or Formula by looking. A formula the author typed in any other shape is theirs; the editor
 * never rewrites it. `calculation-presets.test.ts` holds each preset to its round trip.
 */

import { referencedKeys, type Node } from '../../../public-runtime/engine/ast';
import { ExpressionLexer, ExpressionParser, FunctionRegistry } from '../../../public-runtime/engine';

export type CalculationType = 'number' | 'text' | 'date' | 'duration';
export type DateUnit = 'years' | 'months' | 'weeks' | 'days' | 'hours' | 'minutes';
export type IfOperator = '=' | '!=' | '>' | '>=' | '<' | '<=';

/** A date a preset reads: a question's answer, or today's date on the clock. */
export type DateOperand = { key: string } | { today: true };

export type Preset =
    | { kind: 'sum' | 'average' | 'count'; keys: string[] }
    | { kind: 'difference'; from: string; subtract: string }
    | { kind: 'age'; key: string }
    | { kind: 'duration'; start: DateOperand; end: DateOperand; unit: DateUnit }
    | { kind: 'date_add'; from: DateOperand; count: number; unit: DateUnit }
    | { kind: 'if'; key: string; op: IfOperator; value: string; then: string; otherwise: string }
    | { kind: 'join'; keys: string[]; separator: string };

export type PresetKind = Preset['kind'];

/** A question the editor may offer: its code, its label and its type. */
export interface CalculationQuestion {
    key: string;
    label: string;
    fieldType: string;
}

/** Each preset's name, as the editor's Preset list shows it. */
export const PRESETS: Record<PresetKind, string> = {
    sum: 'Sum',
    difference: 'Difference',
    average: 'Average',
    count: 'Count answered',
    if: 'If … then …',
    join: 'Join answers',
    age: 'Age from a birth date',
    duration: 'Duration between two dates or times',
    date_add: 'A date N days from a date',
};

const BY_TYPE: Record<CalculationType, PresetKind[]> = {
    number: ['sum', 'difference', 'average', 'count', 'if'],
    text: ['join', 'if'],
    date: ['date_add'],
    duration: ['age', 'duration'],
};

export const UNITS: DateUnit[] = ['years', 'months', 'weeks', 'days', 'hours', 'minutes'];

const OPERATORS: Record<string, IfOperator> = { eq: '=', neq: '!=', gt: '>', gte: '>=', lt: '<', lte: '<=' };

/** The presets a Type offers, in the editor's order. */
export function presetsFor(type: CalculationType): PresetKind[] {
    return BY_TYPE[type];
}

/** The Type a preset gives its calculation, and a Duration's unit. */
export function typeOfPreset(preset: Preset): { type: CalculationType; unit: DateUnit | null } {
    switch (preset.kind) {
        case 'age':
            return { type: 'duration', unit: 'years' };
        case 'duration':
            return { type: 'duration', unit: preset.unit };
        case 'date_add':
            return { type: 'date', unit: null };
        case 'join':
            return { type: 'text', unit: null };
        case 'if':
            return { type: isNumber(preset.then) && isNumber(preset.otherwise) ? 'number' : 'text', unit: null };
        default:
            return { type: 'number', unit: null };
    }
}

/** The canonical formula a preset writes. */
export function presetFormula(preset: Preset): string {
    switch (preset.kind) {
        case 'sum':
            return `sum(${preset.keys.map(ref).join(', ')})`;
        case 'difference':
            return `${ref(preset.from)} - ${ref(preset.subtract)}`;
        case 'count':
            return preset.keys.map(answered).join(' + ');
        case 'average':
            return `sum(${preset.keys.map(ref).join(', ')}) / (${preset.keys.map(answered).join(' + ')})`;
        case 'age':
            return `age(${ref(preset.key)})`;
        case 'duration':
            return `date_diff(${dateOperand(preset.start)}, ${dateOperand(preset.end)}, '${preset.unit}')`;
        case 'date_add':
            return `date_add(${dateOperand(preset.from)}, ${preset.count}, '${preset.unit}')`;
        case 'if':
            return `if(${ref(preset.key)} ${preset.op} ${literal(preset.value)}, ${literal(preset.then)}, ${literal(preset.otherwise)})`;
        case 'join':
            return `concat(${preset.keys.map(ref).join(preset.separator === '' ? ', ' : `, ${quote(preset.separator)}, `)})`;
    }
}

/** The preset a formula was written by, or null when it is a formula of the author's own. */
export function parsePreset(formula: string): Preset | null {
    let ast: Node;
    try {
        ast = parser().parse(formula);
    } catch {
        return null;
    }

    const preset = readPreset(ast);

    // Only an exact round trip counts: anything the canonical writer would spell differently is the author's.
    return preset !== null && presetFormula(preset) === formula.trim() ? preset : null;
}

function readPreset(ast: Node): Preset | null {
    if (ast.type === 'call') {
        const [first, second, third] = ast.args;
        switch (ast.name) {
            case 'sum':
                return allFields(ast.args) ? { kind: 'sum', keys: ast.args.map(keyOf) } : null;
            case 'age':
                return null;
            case 'date_diff': {
                const [start, end, unit] = [readDate(first), readDate(second), readUnit(third)];

                return start !== null && end !== null && unit !== null ? { kind: 'duration', start, end, unit } : null;
            }
            case 'date_add': {
                const [from, unit] = [readDate(first), readUnit(third)];

                return from !== null && unit !== null && second.type === 'literal' && typeof second.value === 'number' && Number.isInteger(second.value)
                    ? { kind: 'date_add', from, count: second.value, unit }
                    : null;
            }
            case 'if': {
                // One question's "answered?" term is an if() too — Count answered, before If … then ….
                const counted = readCount(ast);

                return counted !== null ? { kind: 'count', keys: counted } : readIf(ast);
            }
            case 'concat':
                return readJoin(ast.args);
            default:
                return null;
        }
    }

    if (ast.type === 'arithmetic') {
        if (ast.op === 'sub' && ast.left.type === 'field' && ast.right.type === 'field') {
            return { kind: 'difference', from: ast.left.key, subtract: ast.right.key };
        }
        if (ast.op === 'div' && ast.left.type === 'call' && ast.left.name === 'sum' && allFields(ast.left.args)) {
            const keys = ast.left.args.map(keyOf);
            const counted = readCount(ast.right);

            return counted !== null && counted.join() === keys.join() ? { kind: 'average', keys } : null;
        }
        const counted = readCount(ast);

        return counted === null ? null : { kind: 'count', keys: counted };
    }

    const counted = readCount(ast);

    return counted === null ? null : { kind: 'count', keys: counted };
}

/** `if(${a} = '', 0, 1) + …` — the questions it counts as answered, or null. */
function readCount(node: Node): string[] | null {
    if (node.type === 'arithmetic' && node.op === 'add') {
        const left = readCount(node.left);
        const right = readCount(node.right);

        return left !== null && right !== null ? [...left, ...right] : null;
    }

    if (node.type === 'call' && node.name === 'if' && node.args.length === 3) {
        const [test, blank, one] = node.args;
        const isAnswerTest = test.type === 'comparison' && test.op === 'eq' && test.left.type === 'field'
            && test.right.type === 'literal' && test.right.value === '';
        if (isAnswerTest && blank.type === 'literal' && blank.value === 0 && one.type === 'literal' && one.value === 1 && test.left.type === 'field') {
            return [test.left.key];
        }
    }

    return null;
}

function readIf(node: Extract<Node, { type: 'call' }>): Preset | null {
    const [test, then, otherwise] = node.args;
    if (test.type !== 'comparison' || test.left.type !== 'field' || test.right.type !== 'literal' || OPERATORS[test.op] === undefined) {
        return null;
    }
    if (then.type !== 'literal' || otherwise.type !== 'literal') {
        return null;
    }

    return { kind: 'if', key: test.left.key, op: OPERATORS[test.op], value: String(test.right.value), then: String(then.value), otherwise: String(otherwise.value) };
}

function readJoin(args: Node[]): Preset | null {
    if (args.length === 0) {
        return null;
    }
    if (allFields(args)) {
        return { kind: 'join', keys: args.map(keyOf), separator: '' };
    }

    const keys: string[] = [];
    let separator: string | null = null;
    for (const [index, arg] of args.entries()) {
        if (index % 2 === 0) {
            if (arg.type !== 'field') return null;
            keys.push(arg.key);
        } else {
            if (arg.type !== 'literal' || typeof arg.value !== 'string' || (separator !== null && separator !== arg.value)) return null;
            separator = arg.value;
        }
    }

    return args.length % 2 === 1 && separator !== null && separator !== '' ? { kind: 'join', keys, separator } : null;
}

function readDate(node: Node | undefined): DateOperand | null {
    if (node?.type === 'field') return { key: node.key };
    if (node?.type === 'call' && node.name === 'today' && node.args.length === 0) return { today: true };

    return null;
}

function readUnit(node: Node | undefined): DateUnit | null {
    return node?.type === 'literal' && typeof node.value === 'string' && (UNITS as string[]).includes(node.value) ? (node.value as DateUnit) : null;
}

function allFields(args: Node[]): boolean {
    return args.length > 0 && args.every((arg) => arg.type === 'field');
}

function keyOf(node: Node): string {
    return node.type === 'field' ? node.key : '';
}

function ref(key: string): string {
    return `\${${key}}`;
}

function answered(key: string): string {
    return `if(${ref(key)} = '', 0, 1)`;
}

function dateOperand(operand: DateOperand): string {
    return 'today' in operand ? 'today()' : ref(operand.key);
}

function isNumber(text: string): boolean {
    return /^-?[0-9]+(\.[0-9]+)?$/.test(text.trim());
}

/** A value as the grammar writes it: a number bare, anything else quoted. */
function literal(text: string): string {
    return isNumber(text) ? text.trim() : quote(text);
}

/** The grammar has no escapes: single quotes, unless the text holds one — then double (a text holding both loses its double quotes). */
function quote(text: string): string {
    return text.includes("'") ? `"${text.replaceAll('"', '')}"` : `'${text}'`;
}

let cached: ExpressionParser | null = null;
function parser(): ExpressionParser {
    cached ??= new ExpressionParser(new ExpressionLexer(), new FunctionRegistry());

    return cached;
}

/** The questions a formula names — for the editor to keep a preset's choices to questions that exist. */
export function formulaKeys(formula: string): string[] {
    try {
        return referencedKeys(parser().parse(formula));
    } catch {
        return [];
    }
}
