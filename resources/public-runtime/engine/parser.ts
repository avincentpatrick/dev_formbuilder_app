/**
 * Recursive-descent parser: Token[] → AST — the mirror of `app/Services/Expressions/ExpressionParser.php`
 * (technical-architecture.md §4.3 §3). Precedence, lowest→highest: `or` < `and` < `not(…)` < comparison
 * (non-associative — a single comparator, no chaining) < primary. Enforces operand result-kind typing (a
 * Boolean-kind node — a nested comparison, and/or, not, or selected — is rejected as a comparison operand
 * or a `selected()` first argument, §3) and an anti-DoS parse-depth cap.
 *
 * The PHP parser keeps a bounded LRU parse memo; it is a pure cache with no behavioural effect and is
 * omitted here (the SPA re-parses cheaply per fill and the drift contract is about verdicts, not caching).
 */

import type { ComparisonOperator } from './enums';
import { ExpressionSyntaxError } from './errors';
import { FunctionRegistry } from './function-registry';
import { fieldRef, kind, numberLiteral, stringLiteral, type Node } from './ast';
import { ExpressionLexer } from './lexer';
import type { Token, TokenType } from './tokens';

const MAX_PARSE_DEPTH = 64;

export class ExpressionParser {
    private readonly lexer: ExpressionLexer;
    private readonly functions: FunctionRegistry;
    private tokens: Token[] = [];
    private pos = 0;
    private depth = 0;

    constructor(lexer: ExpressionLexer, functions: FunctionRegistry) {
        this.lexer = lexer;
        this.functions = functions;
    }

    parse(expression: string): Node {
        this.tokens = this.lexer.tokenize(expression);
        this.pos = 0;
        this.depth = 0;

        const ast = this.parseExpr();
        this.expect('eof'); // trailing tokens (e.g. a chained comparison) are unexpected

        return ast;
    }

    private parseExpr(): Node {
        return this.parseOr();
    }

    private parseOr(): Node {
        let left = this.parseAnd();

        while (this.isWord('or')) {
            this.advance();
            left = { type: 'logical', op: 'or', left, right: this.parseAnd() };
        }

        return left;
    }

    private parseAnd(): Node {
        let left = this.parseNot();

        while (this.isWord('and')) {
            this.advance();
            left = { type: 'logical', op: 'and', left, right: this.parseNot() };
        }

        return left;
    }

    private parseNot(): Node {
        if (!this.isWord('not')) {
            return this.parseComparison();
        }

        this.advance(); // 'not'

        if (!this.check('lparen')) {
            throw this.unexpected();
        }

        this.descend();
        this.advance(); // '('
        const inner = this.parseExpr();
        this.expect('rparen');
        this.ascend();

        return { type: 'not', operand: inner };
    }

    private parseComparison(): Node {
        const left = this.parseOperand();

        const op = this.comparatorFor(this.peek().type);

        if (op === null) {
            return left;
        }

        this.advance();
        const right = this.parseOperand();

        if (kind(left) !== 'value' || kind(right) !== 'value') {
            throw ExpressionSyntaxError.nonValueOperand('a comparison operand');
        }

        return { type: 'comparison', op, left, right };
    }

    private comparatorFor(type: TokenType): ComparisonOperator | null {
        switch (type) {
            case 'eq':
                return 'eq';
            case 'neq':
                return 'neq';
            case 'gt':
                return 'gt';
            case 'lt':
                return 'lt';
            default:
                return null;
        }
    }

    private parseOperand(): Node {
        const token = this.peek();

        if (token.type === 'lparen') {
            this.descend();
            this.advance();
            const inner = this.parseExpr();
            this.expect('rparen');
            this.ascend();

            return inner;
        }

        if (token.type === 'word') {
            return this.parseWordOperand(token);
        }

        if (token.type === 'field_ref') {
            this.advance();

            return fieldRef(token.lexeme);
        }

        if (token.type === 'self_ref') {
            this.advance();

            return { type: 'self' };
        }

        if (token.type === 'number') {
            this.advance();

            return numberLiteral(Number(token.lexeme));
        }

        if (token.type === 'minus') {
            this.advance();

            if (!this.check('number')) {
                throw this.unexpected();
            }

            const number = this.advance();

            return numberLiteral(-1 * Number(number.lexeme));
        }

        if (token.type === 'string') {
            this.advance();

            return stringLiteral(token.lexeme);
        }

        throw this.unexpected();
    }

    private parseWordOperand(token: Token): Node {
        const followedByCall = this.tokens[this.pos + 1].type === 'lparen';

        if (!followedByCall) {
            throw this.unexpected(); // a bare word is never a valid operand
        }

        if (token.lexeme !== 'selected' || !this.functions.isPublic('selected')) {
            throw ExpressionSyntaxError.unknownFunction(token.lexeme);
        }

        return this.parseSelected();
    }

    private parseSelected(): Node {
        this.descend();
        this.advance(); // 'selected'
        this.advance(); // '('

        const first = this.parseOperand();

        if (kind(first) !== 'value') {
            throw ExpressionSyntaxError.nonValueOperand('a selected() argument');
        }

        if (!this.check('comma')) {
            throw ExpressionSyntaxError.arityMismatch('selected', 2, 1);
        }
        this.advance(); // ','

        if (!this.check('string')) {
            throw this.unexpected(); // the value argument must be a string literal
        }
        const literal = this.advance();
        const second = stringLiteral(literal.lexeme);

        if (this.check('comma')) {
            throw ExpressionSyntaxError.arityMismatch('selected', 2, 3);
        }

        this.expect('rparen');
        this.ascend();

        return { type: 'call', name: 'selected', args: [first, second] };
    }

    private descend(): void {
        if (++this.depth > MAX_PARSE_DEPTH) {
            throw ExpressionSyntaxError.tooDeeplyNested(this.depth);
        }
    }

    private ascend(): void {
        this.depth--;
    }

    private peek(): Token {
        return this.tokens[this.pos];
    }

    private advance(): Token {
        const token = this.tokens[this.pos];

        if (this.pos < this.tokens.length - 1) {
            this.pos++;
        }

        return token;
    }

    private check(type: TokenType): boolean {
        return this.peek().type === type;
    }

    private isWord(lexeme: string): boolean {
        const token = this.peek();

        return token.type === 'word' && token.lexeme === lexeme;
    }

    private expect(type: TokenType): Token {
        if (!this.check(type)) {
            throw this.unexpected();
        }

        return this.advance();
    }

    private unexpected(): ExpressionSyntaxError {
        const token = this.peek();

        return ExpressionSyntaxError.unexpectedToken(token.lexeme, token.position);
    }
}
