/**
 * Lexer tokens — the mirror of `app/Services/Expressions/TokenType.php` + `Token.php`. `position` is a
 * byte offset in the PHP lexer; here it is a code-unit index. Positions only ever appear in error
 * MESSAGES, never in the `slug` the drift contract asserts on, so the ASCII grammar keeps them aligned and
 * any multi-byte divergence is immaterial to correctness.
 */

export type TokenType =
    | 'lparen'
    | 'rparen'
    | 'comma'
    | 'eq'
    | 'neq'
    | 'gt'
    | 'lt'
    | 'minus'
    | 'number'
    | 'string'
    | 'field_ref'
    | 'self_ref'
    | 'word'
    | 'eof';

export type Token = { type: TokenType; lexeme: string; position: number };
