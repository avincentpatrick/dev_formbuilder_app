<?php

declare(strict_types=1);

namespace App\Services\Templates;

use App\Exceptions\Templates\TemplateSyntaxException;
use App\Services\Expressions\ExpressionParser;
use App\Services\Forms\TemplateValidationGate;

/**
 * Source string → segments, for the piping TEMPLATE grammar v1.0
 * (`docs/piping-output-encoding-design.md` §2). Left-to-right, single pass, no backtracking.
 *
 * A template is a sequence of literal text and holes:
 *
 *     template  ::= ( literal | escape | hole )*
 *     hole      ::= '${' key '}'
 *     key       ::= [A-Za-z_] [A-Za-z0-9_]*
 *     escape    ::= '$${'                     -- yields the literal text "${"
 *     literal   ::= any character sequence containing no unescaped '${'
 *
 * ── A template is NOT an expression ─────────────────────────────────────────────────────────────────
 * Doc #26 §2 pins this as a SIBLING grammar with its own {@see self::TEMPLATE_VERSION}, its own
 * `tests/golden/templates/` corpus and its own PHP⇄TS parity runner; `ExpressionEvaluator::GRAMMAR_VERSION`
 * stays `'2.0'`. An expression yields a scalar and participates in relevance/constraint/calculate
 * evaluation; a template yields TEXT and participates in nothing. They share one lexeme — `${key}` — and no
 * code. The key production below is byte-identical to `ExpressionLexer::lexReference()` deliberately; the
 * one divergence is that a bare `$` here is ordinary literal text (the expression lexer throws
 * `unexpected_token` on it), because inside prose a lone `$` is overwhelmingly a currency sign and the
 * escape belongs in the rare case rather than the common one.
 *
 * The rule is total and unambiguous under leftmost-longest scanning with no escape-of-escape regress:
 * `$$${` scans as a literal `$` (position 0 is not `$$` followed by `{`) then the escape `$${`, yielding
 * `$${`. There is no sequence an author cannot express.
 *
 * ── Two modes, one scanner ──────────────────────────────────────────────────────────────────────────
 * {@see parse()} is strict and is reached only from {@see TemplateValidationGate} at
 * publish; {@see parseLenient()} never throws and is what every RENDER path uses, because §3.4 requires
 * that rendering never fail — a question with a gap in it is recoverable, a 500 on a respondent's form is
 * not. Sharing one scanner is what keeps the two modes from disagreeing about where a hole begins.
 *
 * @phpstan-type TemplateSegment array{type: 'literal'|'hole', value: string}
 */
final class TemplateParser
{
    /**
     * The template grammar version, mirrored by `resources/public-runtime/engine/template.ts`. Asserted
     * per vector by both golden runners. NOT `ExpressionEvaluator::GRAMMAR_VERSION` — coupling the two is
     * exactly what Doc #26 §2's sibling decision exists to prevent.
     */
    public const TEMPLATE_VERSION = '1.0';

    /**
     * Doc #26 §2.1's caps, mirroring `ExpressionLexer`'s figures. Measured in BYTES (`strlen`), matching
     * the expression lexer and the TS twin's `TextEncoder` byte length — not characters, and not the
     * FormRequest rules' `max:2000`, which counts multibyte characters.
     */
    private const MAX_TEMPLATE_BYTES = 2000;

    private const MAX_HOLES = 20;

    /** The malformed-reference context window, mirroring `ExpressionLexer::rawReference()`. */
    private const RAW_WINDOW = 24;

    /**
     * Parse a template, refusing anything malformed or over cap. Publish-time only.
     *
     * @return list<TemplateSegment>
     *
     * @throws TemplateSyntaxException
     */
    public function parse(string $template): array
    {
        return $this->scan($template, strict: true);
    }

    /**
     * Parse for RENDER: never throws (§3.4). A malformed hole emits nothing and the scan resumes past the
     * offending `${`; the caps are not enforced. Unreachable for any template that passed the publish gate
     * — it exists for the residue §3.4 names: a hand-edited row, or a snapshot written by a newer
     * TEMPLATE_VERSION.
     *
     * @return list<TemplateSegment>
     */
    public function parseLenient(string $template): array
    {
        return $this->scan($template, strict: false);
    }

    /**
     * Every distinct hole key in first-seen order, mirroring
     * {@see ExpressionParser::referencedKeys()}'s contract. Used by the publish
     * gate to resolve each reference against the version being published.
     *
     * @return list<string>
     *
     * @throws TemplateSyntaxException
     */
    public function holeKeys(string $template): array
    {
        /** @var array<string, true> $seen */
        $seen = [];

        foreach ($this->parse($template) as $segment) {
            if ($segment['type'] === 'hole') {
                $seen[$segment['value']] = true;
            }
        }

        return array_keys($seen);
    }

    /**
     * The one scanner, both modes.
     *
     * @return list<TemplateSegment>
     *
     * @throws TemplateSyntaxException
     */
    private function scan(string $template, bool $strict): array
    {
        // ── Doc #26 §2.1, as amended by H6a: the caps bind only a HOLE-BEARING value ────────────────
        // The caps exist to bound RENDER cost, and a template with no hole has none — it is returned
        // unchanged. Applying them unconditionally would refuse to publish a form for an over-long value
        // that predates piping entirely: `form_fields.hint` and `form_sections.description` are TEXT, and
        // `XlsformImporter` / `SchemaBlueprintMaterializer` write them via Model::create with no
        // FormRequest in the path, so the `max:2000` request rule does not bind there at all. That would
        // break §6.2's "additive for every existing form" posture for a value piping never touches.
        //
        // `$${` contains `${`, so an escape-only template is still cap-checked.
        if (! str_contains($template, '${')) {
            return $template === '' ? [] : [['type' => 'literal', 'value' => $template]];
        }

        if ($strict) {
            $bytes = strlen($template);
            if ($bytes > self::MAX_TEMPLATE_BYTES) {
                throw TemplateSyntaxException::templateTooLong($bytes);
            }
        }

        /** @var list<TemplateSegment> $segments */
        $segments = [];
        $literal = '';
        $holes = 0;
        $length = strlen($template);
        $i = 0;

        while ($i < $length) {
            if ($template[$i] !== '$') {
                $literal .= $template[$i];
                $i++;

                continue;
            }

            // `$${` — the ONLY escape. Emits a literal `${` and consumes all three bytes, so the `{` can
            // never open a hole. Checked BEFORE the bare `${` case: leftmost-longest.
            if ($i + 2 < $length && $template[$i + 1] === '$' && $template[$i + 2] === '{') {
                $literal .= '${';
                $i += 3;

                continue;
            }

            // A `$` not followed by `{` is ordinary text — "$5", "a$b", "Amount in $" need no escaping.
            if ($i + 1 >= $length || $template[$i + 1] !== '{') {
                $literal .= '$';
                $i++;

                continue;
            }

            $key = $this->readKey($template, $length, $i);

            if ($key === null) {
                if ($strict) {
                    throw TemplateSyntaxException::malformedReference($this->rawReference($template, $length, $i));
                }

                // Lenient: drop the malformed hole and resume past the `${` that opened it, so a single
                // bad hole cannot swallow the rest of the string as literal text.
                $i += 2;

                continue;
            }

            if ($literal !== '') {
                $segments[] = ['type' => 'literal', 'value' => $literal];
                $literal = '';
            }

            $segments[] = ['type' => 'hole', 'value' => $key];
            $holes++;

            if ($strict && $holes > self::MAX_HOLES) {
                throw TemplateSyntaxException::tooManyHoles($holes);
            }

            $i += strlen($key) + 3; // '${' + key + '}'
        }

        if ($literal !== '') {
            $segments[] = ['type' => 'literal', 'value' => $literal];
        }

        return $segments;
    }

    /**
     * The key production, byte-identical to `ExpressionLexer::lexReference()` (`:172`/`:177`): the first
     * character is `ctype_alpha` or `_`, the rest `ctype_alnum` or `_`, then a closing `}`. Byte-wise
     * `ctype_*` (never a `\w` regex, which admits Unicode). `$i` points at the `$`; returns null when the
     * hole is malformed, without moving the cursor.
     */
    private function readKey(string $template, int $length, int $i): ?string
    {
        $start = $i + 2;
        $j = $start;

        if ($j >= $length || ! (ctype_alpha($template[$j]) || $template[$j] === '_')) {
            return null;
        }

        $j++;
        while ($j < $length && (ctype_alnum($template[$j]) || $template[$j] === '_')) {
            $j++;
        }

        if ($j >= $length || $template[$j] !== '}') {
            return null;
        }

        return substr($template, $start, $j - $start);
    }

    /** A 24-byte window from the offending `$`, mirroring `ExpressionLexer::rawReference()` (`:202-207`). */
    private function rawReference(string $template, int $length, int $start): string
    {
        return substr($template, $start, min($length - $start, self::RAW_WINDOW));
    }
}
