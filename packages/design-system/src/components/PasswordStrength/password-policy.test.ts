import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';

/**
 * The BROWSER half of the password-policy contract (J3b).
 *
 * `tests/Feature/Auth/PasswordPolicyTest.php` names this file in as many words — *"`password-policy.test.ts`
 * is the other half of this claim and compiles the same strings with `new RegExp(..., 'u')`"* — and until
 * now it did not exist, so the claim was half-made. PCRE-with-`u` is not JavaScript's regular-expression
 * engine; every construct the policy uses (`\p{...}`, `{n,}`, alternation, lookahead) means the same thing
 * in both, and *that is the thing being asserted*, not assumed.
 *
 * ── ⚠️ IT READS THE PHP FILE. THAT IS THE POINT, NOT A SHORTCUT ────────────────────────────────────────
 * Copying the patterns into a TypeScript constant here would reintroduce exactly the drift
 * `PasswordPolicy` exists to prevent — two hand-maintained copies of one rule, in two languages, agreeing
 * until somebody edits one. Reading the source off disk is the idiom this repository already uses for
 * cross-artifact contracts (`token-references.test.ts` scans `resources/` from the package; the
 * notification-type parity test reads the TypeScript union from PHP). The file lives beside the component
 * that consumes those patterns, which is where a reader looking for "why does the checklist tick" lands.
 *
 * ── WHY A PARSER AND NOT A JSON FIXTURE ────────────────────────────────────────────────────────────────
 * Vitest cannot invoke PHP, so the patterns have to be lifted out of the source. The grammar accepted
 * below is deliberately tiny — single-quoted strings and `self::MIN_LENGTH`, concatenated — and every
 * assertion is guarded against the parser silently finding nothing, which is the way a test like this
 * goes quietly vacuous.
 */

const POLICY_PHP = join(process.cwd(), 'app/Support/Auth/PasswordPolicy.php');
const source = readFileSync(POLICY_PHP, 'utf8');

const minLength = Number(source.match(/const\s+(?:int\s+)?MIN_LENGTH\s*=\s*(\d+)/)?.[1]);

/**
 * Evaluate the restricted PHP expression the `pattern` key is allowed to be: single-quoted string
 * literals and `self::MIN_LENGTH`, joined by `.`. PHP single quotes recognise exactly two escapes
 * (`\'` and `\\`), so `'[\s\S]{'` is literally `[\s\S]{` — no other unescaping is correct here.
 */
function evaluatePhpExpression(expression: string): string | null {
    const trimmed = expression.trim().replace(/,$/, '');

    if (trimmed === 'null') {
        return null;
    }

    const tokens = trimmed.match(/'(?:\\.|[^'\\])*'|self::MIN_LENGTH/g);

    if (tokens === null) {
        throw new Error(`Unparseable pattern expression in PasswordPolicy.php: ${trimmed}`);
    }

    return tokens
        .map((token) =>
            token === 'self::MIN_LENGTH'
                ? String(minLength)
                : token.slice(1, -1).replace(/\\(['\\])/g, '$1'),
        )
        .join('');
}

interface ParsedRequirement {
    key: string;
    pattern: string | null;
}

function parseRequirements(): ParsedRequirement[] {
    const parsed: ParsedRequirement[] = [];
    // `.` does not match a newline, so the second group captures to end of line — which matters because a
    // pattern legitimately contains commas (`'[\s\S]{'.self::MIN_LENGTH.',}'`) and a comma-terminated
    // capture would cut one in half and still look like it worked.
    const entry = /'key'\s*=>\s*'([a-z_]+)',[\s\S]*?'pattern'\s*=>\s*(.*)/g;

    for (const match of source.matchAll(entry)) {
        parsed.push({ key: match[1], pattern: evaluatePhpExpression(match[2]) });
    }

    return parsed;
}

const requirements = parseRequirements();

describe('the published password policy, read from PHP', () => {
    it('is actually found and parsed (the anti-vacuity case)', () => {
        // Without this, a rename of the PHP file or a change to its array shape makes every assertion
        // below iterate an empty list and pass. That is the failure mode a source-reading test has.
        expect(minLength).toBe(12);
        expect(requirements.map((requirement) => requirement.key)).toEqual([
            'min_length',
            'mixed_case',
            'numbers',
            'symbols',
            'uncompromised',
        ]);
    });

    it('publishes patterns JavaScript can compile with the `u` flag', () => {
        for (const requirement of requirements) {
            if (requirement.pattern === null) {
                continue;
            }

            expect(
                () => new RegExp(requirement.pattern as string, 'u'),
                `[${requirement.key}] does not compile in JavaScript`,
            ).not.toThrow();
        }
    });

    it('leaves `uncompromised` unevaluatable in the browser, on purpose', () => {
        // ⚠️ IF THIS FAILS, SOMEBODY GAVE THE BREACH ROW A PATTERN TO MAKE IT "WORK". DO NOT FIX THE TEST.
        // The check is a k-anonymity range query against a third party; running it here would leak the
        // SHA-1 prefix of a password being typed, per keystroke, from the user's own IP.
        // `PasswordPolicyTest` asserts the identical thing from the PHP side.
        expect(requirements.find((requirement) => requirement.key === 'uncompromised')?.pattern).toBeNull();
    });
});

/**
 * The same fixtures as `PasswordPolicyTest.php`'s `policy agreement fixtures` dataset, deliberately —
 * including the three non-ASCII rows, which are what make the pair of tests able to fail at all. An
 * ASCII-only fixture passes against `[0-9]` and against `\p{N}` alike, so it cannot detect the exact
 * drift these two files exist to catch. (That is not hypothetical: the PHP dataset's own comment records
 * that its first draft had ASCII fixtures only, and `[0-9]` passed.)
 */
describe.each([
    ['min_length', 'abcdefghijkl', 'abcdefghijk'],
    ['mixed_case', 'aB', 'ab'],
    ['numbers', 'a1', 'ab'],
    ['symbols', 'a!', 'ab'],
    ['numbers (non-ASCII)', 'a\u0663', 'ab'],
    ['mixed_case (non-ASCII)', 'a\u00C9', 'ab'],
    ['symbols (non-ASCII)', 'a\u20AC', 'ab'],
])('%s', (label, satisfies, violates) => {
    const key = label.replace(/ \(non-ASCII\)$/, '');

    it('accepts what the server accepts and refuses what the server refuses', () => {
        const requirement = requirements.find((candidate) => candidate.key === key);

        expect(requirement, `no published requirement keyed ${key}`).toBeDefined();

        const expression = new RegExp(requirement!.pattern as string, 'u');

        expect(expression.test(satisfies), `${key} should match ${JSON.stringify(satisfies)}`).toBe(true);
        expect(expression.test(violates), `${key} should not match ${JSON.stringify(violates)}`).toBe(false);
    });
});
