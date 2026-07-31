/**
 * The TypeScript twin of `app/Services/Templates/TemplateRenderer`
 * (`docs/piping-output-encoding-design.md` §3.2/§3.4, Increment H6b) — the reactive half H6a deliberately
 * left unbuilt, because rendering a template without it would have put a literal `${key}` on a
 * respondent's screen, which §3.4 forbids.
 *
 * ── Two contracts that are easy to get wrong ────────────────────────────────────────────────────────
 * 1. **A hole renders through {@link displayValue}, never through anything else.** `Coercion.toStr()` is
 *    explicitly BARRED (§3.2) — its non-integral float→string parity is documented as unpinned, so a
 *    piped `decimal` rendered through it could disagree with PHP. This file never imports it.
 * 2. **Rendering never fails.** An unknown key appends NOTHING; an unanswered source renders as the
 *    EMPTY STRING — never the raw `${key}` token, never `undefined`, never a placeholder glyph. The
 *    parse is always LENIENT, so a malformed template that somehow reached a published snapshot emits
 *    its literal text with the holes dropped rather than throwing. A question with a gap in it is
 *    recoverable; an exception on a respondent's form is not.
 *
 * ── Repeat scope is the CALLER's job ────────────────────────────────────────────────────────────────
 * `answers` is a FLAT key ⇒ value map, exactly as the PHP takes and for the same reason. A repeat-scoped
 * caller merges `{ ...base, ...instance }` itself — the same shape `SemanticValidator` builds for an
 * instance's evaluation context — so §3.3 rule 2's "current instance" needs no addressable path.
 *
 * ── No cache, deliberately ──────────────────────────────────────────────────────────────────────────
 * The PHP twin is stateless and so is this. Vue's `computed` already memoises at exactly the granularity
 * that matters, and state in a twin whose authority has none is how the two drift. `parseLenient` is
 * pure, so memoising by template string here is safe if profiling ever demands it — but measure first:
 * the parser's own hole-free fast path already returns in one `String.includes`.
 */

import { displayValue } from './display-value';
import { TemplateParser } from './template';

/** One piping source: a field's raw `field_type` and its RAW `config` (never a render-model projection). */
export type RenderSource = { type: string; config: Record<string, unknown> };

/** Field key ⇒ its source. The twin of `App\Services\Templates\TemplateSources`' output. */
export type RenderSources = Record<string, RenderSource>;

const hasOwn = (o: object, k: string): boolean => Object.prototype.hasOwnProperty.call(o, k);

export class TemplateRenderer {
    private readonly parser: TemplateParser;

    constructor(parser: TemplateParser) {
        this.parser = parser;
    }

    /**
     * Fill `template`'s holes from `answers`, formatting each through its field's own type and config.
     *
     * `locale` resolves a piped choice answer's option `label_translations` (amendment A8). §4's order is
     * normative and stays the CALLER's job: resolve the template's own locale variant first, then pass
     * the same locale here so the value dropped in agrees with the sentence around it.
     */
    render(template: string, sources: RenderSources, answers: Record<string, unknown>, locale?: string): string {
        if (template === '') {
            return '';
        }

        let out = '';

        for (const segment of this.parser.parseLenient(template)) {
            if (segment.type === 'literal') {
                out += segment.value;
                continue;
            }

            // `hasOwn`, not `sources[key]`. A hole key is `[A-Za-z_][A-Za-z0-9_]*`, so `${constructor}`
            // and `${toString}` are GRAMMATICAL — and a bare lookup on an object literal would return
            // `Object.prototype`'s member for them. PHP structurally cannot have this bug; we can.
            const source = hasOwn(sources, segment.value) ? sources[segment.value] : undefined;

            // An unknown key renders empty rather than throwing: the publish gate already refused every
            // resolvable-at-publish case, so reaching here means the snapshot and the answer document
            // disagree — recoverable, per §3.4.
            if (source === undefined) {
                continue;
            }

            const answer = hasOwn(answers, segment.value) ? answers[segment.value] : null;

            out += displayValue(source.type, answer, source.config, locale);
        }

        return out;
    }

    /**
     * {@link render} for a nullable column (a hint, a placeholder, a section description), preserving null
     * rather than collapsing it to `''` — "no hint" and "a hint that rendered empty" are different states
     * downstream. The pair mirrors `resolveText`/`resolveOptional` in `schema-mapping.ts`, and composing
     * the two is what §4's "resolve the locale, THEN render" means in practice.
     */
    renderOptional(
        template: string | null,
        sources: RenderSources,
        answers: Record<string, unknown>,
        locale?: string,
    ): string | null {
        return template === null ? null : this.render(template, sources, answers, locale);
    }
}

/** Hand-wired factory, mirroring `makeTemplateParser()`'s role for the golden runner. */
export function makeTemplateRenderer(): TemplateRenderer {
    return new TemplateRenderer(new TemplateParser());
}
