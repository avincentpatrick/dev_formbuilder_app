/**
 * Template golden-vector runner (Increment H6a — the drift contract, Risk R3) — the TypeScript half of the
 * shared template suite. Iterates the SAME language-neutral fixtures under `tests/golden/templates/*.json`
 * that the PHP Pest runner (`tests/Unit/Templates/TemplateGoldenVectorsTest.php`) runs, so any drift
 * between the two template parsers is a CI failure, not a silent UX inconsistency. The loading + assertion
 * logic mirrors the PHP runner, including the count-parity / per-file / name-uniqueness guards so neither
 * side can quietly drop a vector.
 *
 * Vectors assert TEMPLATE_VERSION, never GRAMMAR_VERSION — the sibling grammars are versioned
 * independently (Doc #26 §2).
 *
 * Two modes. A `parse` vector asserts the SEGMENT list; a `render` vector asserts the rendered STRING,
 * feeding `sources` + `answers` (+ an optional `locale`) through the renderer. Until H6b the render
 * vectors carried `engines: ["php"]` and were counted-but-skipped here, because filling a hole means
 * running `SchemaValueFormatter::displayValue()` and that had no TypeScript twin — the real R3 hazard in
 * this feature, and not the parser. H6b built the twin, so the key is gone from the corpus entirely and
 * every vector now runs on both engines.
 *
 * Path resolution copies `golden-expressions.test.ts` verbatim: `dirname(fileURLToPath(import.meta.url))`
 * then `join(...)`. All 30 Vitest suites share ONE `happy-dom` environment, whose DOM `URL` global shadows
 * Node's, so resolving a five-level DIRECTORY URL (trailing slash) does not preserve the `file:` scheme and
 * throws ERR_INVALID_URL_SCHEME on CI while passing locally. Path arithmetic after fileURLToPath is safe.
 */

import { readFileSync, readdirSync } from 'node:fs';
import { basename, dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';

import { TEMPLATE_VERSION, TemplateParser, TemplateSyntaxError, type TemplateSegment } from '../template';
import { makeTemplateRenderer, type RenderSources } from '../template-renderer';

const here = dirname(fileURLToPath(import.meta.url));
const goldenDir = join(here, '..', '..', '..', '..', 'tests', 'golden', 'templates');

type TemplateVector = {
    name: string;
    template_version: string;
    mode?: 'parse' | 'render';
    template?: string;
    template_repeat?: { prefix: string; times: number; core: string; suffix: string };
    sources?: RenderSources;
    answers?: Record<string, unknown>;
    locale?: string;
    expected?: TemplateSegment[] | string;
    expected_whole_literal?: boolean;
    expected_repeat?: { times: number; segment: TemplateSegment };
    expected_error?: string;
    note?: string;   // documentation only; both runners ignore it
};

function vectorFiles(): string[] {
    return readdirSync(goldenDir).filter((file) => file.endsWith('.json') && file !== 'manifest.json');
}

function loadVectors(): TemplateVector[] {
    const out: TemplateVector[] = [];
    for (const file of vectorFiles()) {
        const cases = JSON.parse(readFileSync(join(goldenDir, file), 'utf8')) as TemplateVector[];
        out.push(...cases);
    }

    return out;
}

function buildTemplate(vector: TemplateVector): string {
    if (vector.template_repeat) {
        const { prefix, times, core, suffix } = vector.template_repeat;

        return prefix.repeat(times) + core + suffix.repeat(times);
    }

    return vector.template as string;
}

function expectedSegments(vector: TemplateVector, template: string): TemplateSegment[] {
    if (vector.expected_whole_literal) {
        return [{ type: 'literal', value: template }];
    }

    if (vector.expected_repeat) {
        const { times, segment } = vector.expected_repeat;

        return Array.from({ length: times }, () => segment);
    }

    return vector.expected as TemplateSegment[];
}

describe('golden template vectors (PHP ⇄ TS drift contract)', () => {
    for (const vector of loadVectors()) {
        it(`matches the golden template vector: ${vector.name}`, () => {
            expect(vector.template_version).toBe(TEMPLATE_VERSION);

            const parser = new TemplateParser();
            const template = buildTemplate(vector);

            if (Object.prototype.hasOwnProperty.call(vector, 'expected_error')) {
                let thrown: unknown;
                try {
                    parser.parse(template);
                } catch (error) {
                    thrown = error;
                }

                expect(thrown, `expected error “${vector.expected_error}” but none was thrown for: ${template}`).toBeInstanceOf(TemplateSyntaxError);
                expect((thrown as TemplateSyntaxError).slug).toBe(vector.expected_error);

                return;
            }

            // A fresh renderer per vector, matching `makeTemplateRenderer()` in the PHP runner, so no
            // vector can be affected by another.
            if ((vector.mode ?? 'parse') === 'render') {
                const rendered = makeTemplateRenderer().render(
                    template,
                    vector.sources as RenderSources,
                    vector.answers as Record<string, unknown>,
                    vector.locale,
                );

                expect(rendered).toBe(vector.expected as string);

                return;
            }

            expect(parser.parse(template)).toEqual(expectedSegments(vector, template));
        });
    }

    it('enforces the template manifest count-parity, per-file, and name-uniqueness guards', () => {
        const manifest = JSON.parse(readFileSync(join(goldenDir, 'manifest.json'), 'utf8')) as {
            template_version: string;
            total: number;
            files: Record<string, number>;
        };

        expect(manifest.template_version).toBe(TEMPLATE_VERSION);

        const perFile: Record<string, number> = {};
        const names: string[] = [];
        let loaded = 0;

        for (const file of vectorFiles()) {
            const cases = JSON.parse(readFileSync(join(goldenDir, file), 'utf8')) as TemplateVector[];
            perFile[basename(file, '.json')] = cases.length;
            loaded += cases.length;
            for (const vector of cases) {
                names.push(vector.name);
            }
        }

        // Counted on BOTH sides, so a vector cannot be quietly dropped from one engine's view.
        expect(loaded).toBe(manifest.total); // a dropped/added vector fails here
        expect(perFile).toEqual(manifest.files); // a vector moved between files fails here
        expect(new Set(names).size).toBe(manifest.total); // a duplicate name fails here
    });

    it('requires every vector to carry exactly one expected outcome', () => {
        // The FOURTH guard, mirroring `TemplateGoldenVectorsTest.php`. Doc #26 §7 states four; a guard
        // living on one side only is itself runner drift, and the runners ARE the enforcement mechanism.
        // It exists because the sibling runners default optional keys with `?? []`, which lets a vector
        // that asserts NOTHING pass vacuously.
        const outcomeKeys = ['expected', 'expected_error', 'expected_whole_literal', 'expected_repeat'] as const;

        for (const vector of loadVectors()) {
            const declared = outcomeKeys.filter((key) => Object.prototype.hasOwnProperty.call(vector, key));

            expect(declared, `vector “${vector.name}” must declare exactly one expected outcome`).toHaveLength(1);
        }
    });

    it('requires every render vector to carry the inputs it renders from', () => {
        // A render vector whose `sources`/`answers` were forgotten would render every hole empty and could
        // still match an `expected` that happens to be mostly literal text — green for the wrong reason.
        for (const vector of loadVectors()) {
            if ((vector.mode ?? 'parse') !== 'render') {
                continue;
            }

            expect(vector.sources, `render vector “${vector.name}” needs sources`).toBeTypeOf('object');
            expect(vector.answers, `render vector “${vector.name}” needs answers`).toBeTypeOf('object');
            expect(typeof vector.expected, `render vector “${vector.name}” expects a string`).toBe('string');
        }
    });
});
