/**
 * Recursive-descent parser: Token[] → AST — the mirror of `app/Services/Expressions/ExpressionParser.php`
 * (technical-architecture.md §4.3 §3). Precedence, lowest→highest (grammar v2.0 inserts arithmetic below
 * comparison): `or` < `and` < `not(…)` < comparison (`= != > < >= <=`, non-associative) < additive
 * (`+ -`) < multiplicative (`* /`) < unary `-` < primary. Enforces operand result-kind typing (a
 * Boolean-kind node — a nested comparison, and/or, not, or a membership predicate — is rejected as a
 * comparison / arithmetic operand or a `selected()` first argument, §3) and an anti-DoS parse-depth cap.
 *
 * The PHP parser keeps a bounded LRU parse memo; it is a pure cache with no behavioural effect and is
 * omitted here (the SPA re-parses cheaply per fill and the drift contract is about verdicts, not caching).
 */

import type { ArithmeticOperator, ComparisonOperator } from './enums';
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
        const left = this.parseAdditive();

        const op = this.comparatorFor(this.peek().type);

        if (op === null) {
            return left;
        }

        this.advance();
        const right = this.parseAdditive();

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
            case 'gte':
                return 'gte';
            case 'lte':
                return 'lte';
            default:
                return null;
        }
    }

    /** `+`/`-`, left-associative; below comparison, above `*`/`/`. */
    private parseAdditive(): Node {
        let left = this.parseMultiplicative();

        while (this.check('plus') || this.check('minus')) {
            const op: ArithmeticOperator = this.advance().type === 'plus' ? 'add' : 'sub';
            const right = this.parseMultiplicative();
            left = this.arithmetic(op, left, right);
        }

        return left;
    }

    /** `*`/`/`, left-associative; above `+`/`-`, below unary minus. */
    private parseMultiplicative(): Node {
        let left = this.parseUnary();

        while (this.check('star') || this.check('slash')) {
            const op: ArithmeticOperator = this.advance().type === 'star' ? 'mul' : 'div';
            const right = this.parseUnary();
            left = this.arithmetic(op, left, right);
        }

        return left;
    }

    /**
     * Unary minus. `-<number>` folds to a negative Number literal (byte-compatible with grammar v1.0);
     * `-<operand>` for any other Value-kind operand lowers to `0 - operand`.
     */
    private parseUnary(): Node {
        if (this.check('minus')) {
            this.advance();

            if (this.check('number')) {
                const number = this.advance();

                return numberLiteral(-1 * Number(number.lexeme));
            }

            const operand = this.parseUnary();

            if (kind(operand) !== 'value') {
                throw ExpressionSyntaxError.nonValueOperand('an arithmetic operand');
            }

            return { type: 'arithmetic', op: 'sub', left: numberLiteral(0), right: operand };
        }

        return this.parsePrimary();
    }

    private arithmetic(op: ArithmeticOperator, left: Node, right: Node): Node {
        if (kind(left) !== 'value' || kind(right) !== 'value') {
            throw ExpressionSyntaxError.nonValueOperand('an arithmetic operand');
        }

        return { type: 'arithmetic', op, left, right };
    }

    private parsePrimary(): Node {
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

        if (!this.functions.isPublic(token.lexeme)) {
            throw ExpressionSyntaxError.unknownFunction(token.lexeme);
        }

        // `selected` keeps its bespoke shape (arg1 must be a string LITERAL); every other library function
        // takes full-expression arguments validated generically below.
        return token.lexeme === 'selected' ? this.parseSelected() : this.parseCall(token.lexeme);
    }

    private parseSelected(): Node {
        this.descend();
        this.advance(); // 'selected'
        this.advance(); // '('

        const first = this.parseExpr();

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

    /**
     * The general library-function call `name(arg, …)` (grammar v2.0). Each argument is a full expression;
     * the arity is checked against the registry, then per-function operand-kind rules are applied.
     */
    private parseCall(name: string): Node {
        this.descend();
        this.advance(); // name
        this.advance(); // '('

        const args: Node[] = [];
        if (!this.check('rparen')) {
            args.push(this.parseExpr());
            while (this.check('comma')) {
                this.advance();
                args.push(this.parseExpr());
            }
        }

        this.expect('rparen');
        this.ascend();

        const arity = this.functions.arity(name);
        if (arity !== null && args.length !== arity) {
            throw ExpressionSyntaxError.arityMismatch(name, arity, args.length);
        }

        this.validateCallArgs(name, args);

        return { type: 'call', name, args };
    }

    /**
     * Per-function operand typing (§3): `if`'s condition is any kind (coerced with toBool) but its two
     * branches must be Value-kind; `count`/`int` take one Value-kind operand; `today`/`now` take none.
     */
    private validateCallArgs(name: string, args: Node[]): void {
        const requireValue = (node: Node, context: string): void => {
            if (kind(node) !== 'value') {
                throw ExpressionSyntaxError.nonValueOperand(context);
            }
        };

        switch (name) {
            case 'if':
                requireValue(args[1], 'an if() branch');
                requireValue(args[2], 'an if() branch');
                break;
            case 'count':
                requireValue(args[0], 'a count() argument');
                break;
            case 'int':
                requireValue(args[0], 'an int() argument');
                break;
            default:
                break; // today/now take no args
        }
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
