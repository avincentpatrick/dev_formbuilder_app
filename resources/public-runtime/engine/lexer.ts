/**
 * Source string → Token[] — the mirror of `app/Services/Expressions/ExpressionLexer.php`
 * (technical-architecture.md §4.3 §4). Left-to-right, position-tracked, no backtracking. Enforces the
 * anti-DoS length/token budget BEFORE the parser runs. Recognises: grouping, the comparison punctuation
 * (`= != > < >= <=`), the arithmetic operators (`+ - * /`), `${key}` references, single/double-quoted
 * strings (no escapes), numbers (at most one dot; no exponent/sign/bare-dot), the `.` self-reference, and
 * lowercase words. Any unrecognised character is an `unexpected_token`.
 *
 * The `ctype_*` classifiers are ASCII-only (matching PHP's byte-wise `ctype_*`): `\s`/`\d`/`\w` regex
 * shorthands are deliberately NOT used (they would admit Unicode). The length cap is measured in UTF-8
 * bytes (`TextEncoder`) to match PHP `strlen`.
 */

import { ExpressionSyntaxError } from './errors';
import type { Token, TokenType } from './tokens';

const MAX_EXPRESSION_LENGTH = 2000;
const MAX_TOKENS = 500;

const isSpace = (c: string): boolean => c === ' ' || c === '\t' || c === '\n' || c === '\v' || c === '\f' || c === '\r';
const isDigit = (c: string): boolean => c >= '0' && c <= '9';
const isLower = (c: string): boolean => c >= 'a' && c <= 'z';
const isAlpha = (c: string): boolean => (c >= 'a' && c <= 'z') || (c >= 'A' && c <= 'Z');
const isAlnum = (c: string): boolean => isAlpha(c) || isDigit(c);

const byteLength = (s: string): number => new TextEncoder().encode(s).length;

/**
 * M246: one path segment starting at `at` — `[A-Za-z0-9_][A-Za-z0-9_-]*`, ASCII only. Returns the index just past it,
 * or null when no segment starts there. Mirrors `ExpressionLexer::segmentEnd()`; the template parser reads it too.
 */
export function segmentEnd(source: string, length: number, at: number): number | null {
    if (at >= length || !(isAlnum(source[at]) || source[at] === '_')) {
        return null;
    }

    let j = at + 1;
    while (j < length && (isAlnum(source[j]) || source[j] === '_' || source[j] === '-')) {
        j++;
    }

    return j;
}

export class ExpressionLexer {
    tokenize(source: string): Token[] {
        if (byteLength(source) > MAX_EXPRESSION_LENGTH) {
            throw ExpressionSyntaxError.expressionTooLong(byteLength(source));
        }

        if (source.trim() === '') {
            throw ExpressionSyntaxError.emptyExpression();
        }

        const length = source.length;
        const tokens: Token[] = [];
        let i = 0;

        while (i < length) {
            const char = source[i];

            if (isSpace(char)) {
                i++;
                continue;
            }

            const state = { i };
            let token: Token;

            if (char === '(') {
                token = this.single('lparen', '(', state);
            } else if (char === ')') {
                token = this.single('rparen', ')', state);
            } else if (char === ',') {
                token = this.single('comma', ',', state);
            } else if (char === '=') {
                token = this.single('eq', '=', state);
            } else if (char === '>') {
                token = this.lexRelational(source, length, state, 'gte', 'gt', '>');
            } else if (char === '<') {
                token = this.lexRelational(source, length, state, 'lte', 'lt', '<');
            } else if (char === '+') {
                token = this.single('plus', '+', state);
            } else if (char === '-') {
                token = this.single('minus', '-', state);
            } else if (char === '*') {
                token = this.single('star', '*', state);
            } else if (char === '/') {
                token = this.single('slash', '/', state);
            } else if (char === '!') {
                token = this.lexNeq(source, length, state);
            } else if (isDigit(char)) {
                token = this.lexNumber(source, length, state);
            } else if (char === '.') {
                token = this.single('self_ref', '.', state);
            } else if (char === "'" || char === '"') {
                token = this.lexString(source, length, state);
            } else if (char === '$') {
                token = this.lexReference(source, length, state);
            } else if (isLower(char) || char === '_') {
                token = this.lexWord(source, length, state);
            } else {
                throw ExpressionSyntaxError.unexpectedToken(char, i);
            }

            tokens.push(token);
            i = state.i;

            if (tokens.length > MAX_TOKENS) {
                throw ExpressionSyntaxError.expressionTooLong(byteLength(source));
            }
        }

        tokens.push({ type: 'eof', lexeme: '', position: length });

        return tokens;
    }

    private single(type: TokenType, lexeme: string, state: { i: number }): Token {
        const token: Token = { type, lexeme, position: state.i };
        state.i += lexeme.length;

        return token;
    }

    private lexNeq(source: string, length: number, state: { i: number }): Token {
        if (state.i + 1 >= length || source[state.i + 1] !== '=') {
            throw ExpressionSyntaxError.unexpectedToken('!', state.i);
        }

        const token: Token = { type: 'neq', lexeme: '!=', position: state.i };
        state.i += 2;

        return token;
    }

    /** A `>`/`<` becomes its `>=`/`<=` form when the next character is `=` (grammar v2.0). */
    private lexRelational(source: string, length: number, state: { i: number }, withEq: TokenType, bare: TokenType, bareLexeme: string): Token {
        if (state.i + 1 < length && source[state.i + 1] === '=') {
            const token: Token = { type: withEq, lexeme: `${bareLexeme}=`, position: state.i };
            state.i += 2;

            return token;
        }

        return this.single(bare, bareLexeme, state);
    }

    private lexNumber(source: string, length: number, state: { i: number }): Token {
        const start = state.i;

        while (state.i < length && isDigit(source[state.i])) {
            state.i++;
        }

        // At most ONE fractional part, and only if a digit follows the dot ("5." / ".5" are not numbers;
        // a second dot terminates the number so "1.2.3" splits into 1.2 · . · 3).
        if (state.i + 1 < length && source[state.i] === '.' && isDigit(source[state.i + 1])) {
            state.i++;
            while (state.i < length && isDigit(source[state.i])) {
                state.i++;
            }
        }

        return { type: 'number', lexeme: source.slice(start, state.i), position: start };
    }

    private lexString(source: string, length: number, state: { i: number }): Token {
        const start = state.i;
        const quote = source[state.i];
        state.i++;
        let value = '';

        while (state.i < length && source[state.i] !== quote) {
            value += source[state.i];
            state.i++;
        }

        if (state.i >= length) {
            throw ExpressionSyntaxError.unterminatedString(start);
        }

        state.i++; // closing quote

        return { type: 'string', lexeme: value, position: start };
    }

    /**
     * `${key}` → field_ref; M246 adds `${key.row}` / `${key.row.col}` → cell_ref and `${.col}` → row_cell_ref, the
     * lexeme being the whole text inside the braces. Mirrors `ExpressionLexer::lexReference()` byte for byte.
     */
    private lexReference(source: string, length: number, state: { i: number }): Token {
        const start = state.i;

        if (state.i + 1 >= length || source[state.i + 1] !== '{') {
            throw ExpressionSyntaxError.unexpectedToken('$', state.i);
        }

        const keyStart = state.i + 2;
        let j = keyStart;

        // `${.col}` — a row-relative cell: exactly one segment after the dot.
        if (j < length && source[j] === '.') {
            const end = segmentEnd(source, length, j + 1);
            if (end === null || end >= length || source[end] !== '}') {
                throw ExpressionSyntaxError.malformedReference(this.rawReference(source, length, start));
            }
            state.i = end + 1;

            return { type: 'row_cell_ref', lexeme: source.slice(keyStart, end), position: start };
        }

        if (j >= length || !(isAlpha(source[j]) || source[j] === '_')) {
            throw ExpressionSyntaxError.malformedReference(this.rawReference(source, length, start));
        }

        j++;
        while (j < length && (isAlnum(source[j]) || source[j] === '_')) {
            j++;
        }

        // At most two `.segment` parts after the key.
        let segments = 0;
        while (j < length && source[j] === '.' && segments < 2) {
            const end = segmentEnd(source, length, j + 1);
            if (end === null) {
                throw ExpressionSyntaxError.malformedReference(this.rawReference(source, length, start));
            }
            j = end;
            segments++;
        }

        if (j >= length || source[j] !== '}') {
            throw ExpressionSyntaxError.malformedReference(this.rawReference(source, length, start));
        }

        const text = source.slice(keyStart, j);
        state.i = j + 1; // past the closing brace

        return { type: segments === 0 ? 'field_ref' : 'cell_ref', lexeme: text, position: start };
    }

    private lexWord(source: string, length: number, state: { i: number }): Token {
        const start = state.i;

        while (state.i < length && (isLower(source[state.i]) || isDigit(source[state.i]) || source[state.i] === '_')) {
            state.i++;
        }

        return { type: 'word', lexeme: source.slice(start, state.i), position: start };
    }

    private rawReference(source: string, length: number, start: number): string {
        const window = Math.min(length - start, 24);

        return source.slice(start, start + window);
    }
}
