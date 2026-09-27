/**
 * The three `pattern` bodies `DefaultFieldRules` seeds for `email` / `url` / `phone`, driven through THIS
 * engine (Increment M115, closing `R-2a4f6afe`).
 *
 * ⛔ WHY THIS TEST EXISTS AT ALL. `M112` shipped those defaults after exporting the real values and driving
 * them through both engines by hand — all three compiled and agreed on all fifteen samples — but that run
 * lives in a claim file, not in a gate, so the next edit to a pattern body was unprotected. `email` is now
 * ENFORCED by one of these rows rather than by the browser's `type="email"`, so their behaviour here is the
 * only thing standing between an author's field and a respondent who cannot answer it.
 *
 * ⛔ AND THE FAILURE MODE IS SILENT, WHICH IS WHY THE GATE BELONGS ON THIS SIDE. A body PCRE accepts and
 * JavaScript refuses does NOT surface as an engine failure: `matchesPattern()` catches the `RegExp`
 * constructor's throw and returns `false`, two layers BELOW `useFormRuntime`'s `safeEvaluate()`, so
 * `engineFailed` never latches and the "the client engine is not working" banner never renders. Every
 * non-empty answer is simply marked invalid. The publish gate cannot catch it either —
 * `StructuredRuleEvaluator::isCompilablePattern()` runs PCRE, so it agrees with the server and not with V8.
 *
 * ⛔ THE PATTERNS ARE READ OUT OF THE SHIPPED PHP, NEVER TRANSCRIBED. `M112`'s first attempt at this check
 * copied the bodies into the test and was defeated by the tool layer collapsing doubled backslashes — it
 * passed while asserting something the database would never hold. The property any gate here must keep is
 * that it reads the registry, so this one parses `app/Support/Forms/DefaultFieldRules.php` itself, in the
 * same idiom as `resources/js/composables/__tests__/useServerAutosave.test.ts` (which derives its case list
 * from two PHP files) and `tests/Unit/Forms/PdfFieldRoleTest.php` (which does it in the other direction).
 *
 * ⚠️ The SAMPLES live in `tests/fixtures/default-field-patterns.json` and the PHP half reads the same file,
 * so the accept/reject table has one copy and two engines — `step-projection.json`'s arrangement. They are
 * deliberately NOT in `tests/golden/validation/patterns.json`: that corpus's count is pinned at 5, its
 * total at 114, and both numbers are restated in three documents.
 */

import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';
import { EvaluationContext } from '../context';
import { makeExpressionEvaluator } from '../evaluator';
import { StructuredRuleLowering } from '../lowering';
import type { ValidationRow } from '../schema';
import { StructuredRuleEvaluator } from '../structured-rule-evaluator';

// `process.cwd()` rather than `import.meta.url` arithmetic: Vitest's cwd is its config's directory (the
// repo root), and a multi-level `import.meta.url` walk is what lost the `file:` scheme in the G11 CI
// failure. The same rule `resources/public-runtime/__tests__/steps.test.ts` records.
const REGISTRY = 'app/Support/Forms/DefaultFieldRules.php';
const FIXTURE = 'tests/fixtures/default-field-patterns.json';

/** Built as a character code, never written: the tool layer collapses a doubled backslash in a literal. */
const BACKSLASH = String.fromCharCode(92);

interface PatternCase {
    name: string;
    field_type: string;
    answer: string;
    expected: boolean;
}

const registrySource = readFileSync(join(process.cwd(), REGISTRY), 'utf8');
const cases: PatternCase[] = (
    JSON.parse(readFileSync(join(process.cwd(), FIXTURE), 'utf8')) as { cases: PatternCase[] }
).cases;

/** `private const EMAIL = '…';` — the shipped body, exactly as `rule_value` will hold it. */
function shippedPattern(constant: string): string {
    const matched = new RegExp(`private const ${constant} = '([^']*)';`).exec(registrySource);

    // ⛔ ANTI-VACUITY 1: a rename, a visibility change or a reshape (a match arm, a concatenation, a
    // heredoc) must fail LOUDLY here rather than yield an empty string that every case below would
    // then "pass" against.
    expect(matched, `${constant} is no longer a single-quoted \`private const\` in ${REGISTRY}`).not.toBeNull();

    const body = (matched as RegExpExecArray)[1];

    expect(body, `${constant} parsed as an empty pattern in ${REGISTRY}`).not.toBe('');

    // ⛔ THE ESCAPE REFUSAL, AND IT IS NOT DEFENSIVE PADDING. A raw source read equals the stored value
    // only because PHP single-quoted strings escape nothing except a quote and a backslash. Today none of
    // the three bodies contains either. If one ever does, this read silently diverges from what the
    // database holds — a doubled backslash would be read as two and stored as one, and an escaped quote
    // would truncate the match. Refuse to test a string the column will never carry.
    expect(
        body.includes(BACKSLASH + BACKSLASH),
        `${constant} contains a doubled backslash, which PHP stores as one — this reader would be wrong`,
    ).toBe(false);
    expect(
        body.endsWith(BACKSLASH),
        `${constant} appears to end with an escaped quote — the read is truncated, not complete`,
    ).toBe(false);

    return body;
}

const PATTERNS: Record<string, string> = {
    email: shippedPattern('EMAIL'),
    url: shippedPattern('URL'),
    phone: shippedPattern('PHONE'),
};

/** Exactly the production path: a `pattern` row through the real evaluator, as a submission would run it. */
function passesShippedPattern(fieldType: string, answer: string): boolean {
    const row: ValidationRow = {
        id: `${fieldType}-default`,
        form_field_id: 'subject',
        sequence: 0,
        rule_type: 'pattern',
        operator: null,
        related_form_field_id: null,
        rule_value: PATTERNS[fieldType],
        expression: null,
        logic_group: null,
        logic_operator: null,
        error_message: null,
        error_message_translations: null,
    };

    const evaluator = new StructuredRuleEvaluator(makeExpressionEvaluator(), new StructuredRuleLowering());

    return evaluator.passesConstraint(row, 'subject', answer, new EvaluationContext({ subject: answer }, answer), {});
}

describe('the seeded default patterns, in JavaScript', () => {
    it('reads three non-trivial bodies out of the PHP registry', () => {
        // ⛔ ANTI-VACUITY 2: the floor. Three constants, each parsed, each distinct. A regex that stopped
        // matching would already have failed in `shippedPattern`, but a registry that lost a type would
        // not — and the cases below would then silently cover two formats while reporting green.
        expect(Object.keys(PATTERNS)).toHaveLength(3);
        expect(new Set(Object.values(PATTERNS)).size).toBe(3);
        expect(PATTERNS.email).toContain('@');
    });

    it('reads a fixture that exercises both verdicts for all three types', () => {
        // ⛔ ANTI-VACUITY 3: a fixture that failed to load, shrank, or drifted to one-sided expectations
        // would make the parametrised cases vacuous while the suite stayed green.
        expect(cases.length).toBeGreaterThanOrEqual(15);
        expect(new Set(cases.map((c) => c.name)).size).toBe(cases.length);

        for (const fieldType of Object.keys(PATTERNS)) {
            const mine = cases.filter((c) => c.field_type === fieldType);
            expect(mine.some((c) => c.expected), `${fieldType} has no accepting sample`).toBe(true);
            expect(mine.some((c) => !c.expected), `${fieldType} has no rejecting sample`).toBe(true);
        }
    });

    it.each(Object.entries(PATTERNS))('compiles %s in a real RegExp, anchored and Unicode-aware', (_type, body) => {
        // The half PCRE cannot answer. `isCompilablePattern()` on the server proves PHP can compile it;
        // only V8 can prove V8 can. A possessive quantifier or a recursion would throw right here.
        const compiled = StructuredRuleEvaluator.compilePattern(body);

        // ⚠️ THE ASSERTION IS THE PROPERTIES, NOT A BYTE COMPARISON AGAINST THE RAW BODY, AND THE FIRST
        // VERSION OF THIS CASE GOT THAT WRONG. `compilePattern` also ESCAPES every unescaped `/` (PHP
        // delimits with one), so the url body's `://` arrives escaped and a byte comparison would have
        // meant re-implementing that escaping here — a second copy of the thing under test, inside its own
        // gate. Anchoring and the Unicode flag are the properties that matter; that the compiled regex
        // BEHAVES is what the vector cases below prove.
        expect(compiled.source.startsWith('^(?:')).toBe(true);
        expect(compiled.source.endsWith(')$')).toBe(true);
        expect(compiled.flags).toBe('u');
    });

    it.each(cases.map((c) => [c.name, c] as const))('agrees with the server on %s', (_name, testCase) => {
        expect(passesShippedPattern(testCase.field_type, testCase.answer)).toBe(testCase.expected);
    });
});
