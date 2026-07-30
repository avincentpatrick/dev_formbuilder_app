/**
 * The piping TEMPLATE grammar v1.0 — the TypeScript mirror of `app/Services/Templates/TemplateParser.php`
 * and `app/Exceptions/Templates/TemplateSyntaxException.php`
 * (`docs/piping-output-encoding-design.md` §2, Increment H6a).
 *
 *     template  ::= ( literal | escape | hole )*
 *     hole      ::= '${' key '}'
 *     key       ::= [A-Za-z_] [A-Za-z0-9_]*
 *     escape    ::= '$${'                     -- yields the literal text "${"
 *     literal   ::= any character sequence containing no unescaped '${'
 *
 * ── A SIBLING grammar, not a mode of the expression grammar ─────────────────────────────────────────
 * Doc #26 §2 pins this deliberately: an expression yields a scalar and participates in
 * relevance/constraint/calculate evaluation, a template yields TEXT and participates in nothing. They
 * share one lexeme and no code, which is why this file imports nothing from `./lexer` and why
 * {@link TEMPLATE_VERSION} is its own constant beside `GRAMMAR_VERSION`, which stays `'2.0'`. Both golden
 * runners assert their own version per vector; coupling them is exactly what the sibling decision
 * prevents.
 *
 * The one place this diverges from the expression lexer is that a bare `$` is ordinary literal text here
 * (the expression lexer throws `unexpected_token`), because inside prose a lone `$` is overwhelmingly a
 * currency sign — so `"$5"`, `"a$b"` and `"Amount in $"` need no escaping at all.
 *
 * As in `lexer.ts`, the character classifiers are explicit ASCII range comparisons rather than `\w`/`\d`
 * regex shorthands (which would admit Unicode and diverge from PHP's byte-wise `ctype_*`), and the length
 * cap is measured in UTF-8 bytes via `TextEncoder` to match PHP's `strlen`.
 *
 * H6a ships the PARSER only. Rendering a hole means formatting an answer, which is
 * `SchemaValueFormatter::displayValue()`'s job and has no TypeScript twin yet — Doc #26 §3.2 assigns
 * building one to H6b, and it is the real R3 drift surface in this feature, not the parser.
 */

/**
 * The template grammar version, mirroring `TemplateParser::TEMPLATE_VERSION`. Asserted per vector by both
 * golden runners against `tests/golden/templates/manifest.json`.
 */
export const TEMPLATE_VERSION = '1.0';

/**
 * Doc #26 §2.1's caps, mirroring the PHP consts. BYTES, not characters — `TextEncoder` to match `strlen`.
 */
const MAX_TEMPLATE_BYTES = 2000;
const MAX_HOLES = 20;

/** The malformed-reference context window, mirroring `ExpressionLexer::rawReference()`. */
const RAW_WINDOW = 24;

const isAlpha = (c: string): boolean => (c >= 'a' && c <= 'z') || (c >= 'A' && c <= 'Z');
const isDigit = (c: string): boolean => c >= '0' && c <= '9';
const isAlnum = (c: string): boolean => isAlpha(c) || isDigit(c);

const byteLength = (s: string): number => new TextEncoder().encode(s).length;

export type TemplateSegment = { type: 'literal' | 'hole'; value: string };

/**
 * A parse-time template error — the mirror of `App\Exceptions\Templates\TemplateSyntaxException`. Each
 * factory pins a stable snake_case `slug` (never the human message) which the golden `expected_error`
 * vectors assert against, so the two parsers must agree on WHICH error a malformed template produces, not
 * merely that it failed.
 */
export class TemplateSyntaxError extends Error {
    readonly slug: string;

    constructor(message: string, slug: string) {
        super(message);
        this.name = 'TemplateSyntaxError';
        this.slug = slug;
    }

    /** Reuses the expression lexer's slug verbatim (Doc #26 §2.1) — one vocabulary for the shared lexeme. */
    static malformedReference(raw: string): TemplateSyntaxError {
        return new TemplateSyntaxError(`Malformed field reference “${raw}”.`, 'malformed_reference');
    }

    static templateTooLong(bytes: number): TemplateSyntaxError {
        return new TemplateSyntaxError(`The template is too long (${bytes} bytes).`, 'template_too_long');
    }

    static tooManyHoles(count: number): TemplateSyntaxError {
        return new TemplateSyntaxError(`The template has too many references (${count}).`, 'too_many_holes');
    }
}

export class TemplateParser {
    /**
     * Parse a template, refusing anything malformed or over cap. The publish-time mode; PHP is the only
     * engine that runs it in production (all publish-time validation is server-side), but the TS twin
     * exists so the golden corpus can prove the two agree.
     */
    parse(template: string): TemplateSegment[] {
        return this.scan(template, true);
    }

    /**
     * Parse for RENDER: never throws (§3.4). A malformed hole emits nothing and the scan resumes past the
     * `${` that opened it; the caps are not enforced.
     */
    parseLenient(template: string): TemplateSegment[] {
        return this.scan(template, false);
    }

    /** Every distinct hole key in first-seen order, mirroring `TemplateParser::holeKeys()`. */
    holeKeys(template: string): string[] {
        const seen = new Set<string>();
        for (const segment of this.parse(template)) {
            if (segment.type === 'hole') {
                seen.add(segment.value);
            }
        }

        return [...seen];
    }

    private scan(template: string, strict: boolean): TemplateSegment[] {
        // Doc #26 §2.1 as amended by H6a: the caps bind only a HOLE-BEARING value. They exist to bound
        // render cost, and a hole-free template has none — it is returned unchanged. `$${` contains `${`,
        // so an escape-only template is still cap-checked. Mirrors the PHP early return exactly.
        if (!template.includes('${')) {
            return template === '' ? [] : [{ type: 'literal', value: template }];
        }

        if (strict) {
            const bytes = byteLength(template);
            if (bytes > MAX_TEMPLATE_BYTES) {
                throw TemplateSyntaxError.templateTooLong(bytes);
            }
        }

        const segments: TemplateSegment[] = [];
        const length = template.length;
        let literal = '';
        let holes = 0;
        let i = 0;

        while (i < length) {
            if (template[i] !== '$') {
                literal += template[i];
                i++;
                continue;
            }

            // `$${` — the ONLY escape. Emits a literal `${` and consumes all three characters, so the `{`
            // can never open a hole. Checked BEFORE the bare `${` case: leftmost-longest.
            if (i + 2 < length && template[i + 1] === '$' && template[i + 2] === '{') {
                literal += '${';
                i += 3;
                continue;
            }

            // A `$` not followed by `{` is ordinary text.
            if (i + 1 >= length || template[i + 1] !== '{') {
                literal += '$';
                i++;
                continue;
            }

            const key = this.readKey(template, length, i);

            if (key === null) {
                if (strict) {
                    throw TemplateSyntaxError.malformedReference(template.slice(i, i + RAW_WINDOW));
                }

                // Lenient: drop the malformed hole and resume past the `${` that opened it, so one bad
                // hole cannot swallow the rest of the string as literal text.
                i += 2;
                continue;
            }

            if (literal !== '') {
                segments.push({ type: 'literal', value: literal });
                literal = '';
            }

            segments.push({ type: 'hole', value: key });
            holes++;

            if (strict && holes > MAX_HOLES) {
                throw TemplateSyntaxError.tooManyHoles(holes);
            }

            i += key.length + 3; // '${' + key + '}'
        }

        if (literal !== '') {
            segments.push({ type: 'literal', value: literal });
        }

        return segments;
    }

    /**
     * The key production, mirroring `TemplateParser::readKey()` and, through it,
     * `ExpressionLexer::lexReference()`. `i` points at the `$`; returns null when the hole is malformed,
     * without moving the cursor.
     */
    private readKey(template: string, length: number, i: number): string | null {
        const start = i + 2;
        let j = start;

        if (j >= length || !(isAlpha(template[j]) || template[j] === '_')) {
            return null;
        }

        j++;
        while (j < length && (isAlnum(template[j]) || template[j] === '_')) {
            j++;
        }

        if (j >= length || template[j] !== '}') {
            return null;
        }

        return template.slice(start, j);
    }
}

/** Hand-wired factory, mirroring `makeExpressionEvaluator()`'s role for the golden runners. */
export function makeTemplateParser(): TemplateParser {
    return new TemplateParser();
}
