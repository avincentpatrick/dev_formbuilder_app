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
 * RENDER vectors (`engines: ["php"]`) are counted by the guards but their assertion is SKIPPED here:
 * filling a hole means running `SchemaValueFormatter::displayValue()`, which has no TypeScript twin yet.
 * Doc #26 §3.2 assigns building one to H6b — that twin, not the parser, is the real R3 hazard in this
 * feature. When H6b lands it drops the `engines` key and these run on both engines unchanged.
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

const here = dirname(fileURLToPath(import.meta.url));
const goldenDir = join(here, '..', '..', '..', '..', 'tests', 'golden', 'templates');

type TemplateVector = {
    name: string;
    template_version: string;
    mode?: 'parse' | 'render';
    engines?: string[];
    template?: string;
    template_repeat?: { prefix: string; times: number; core: string; suffix: string };
    expected?: TemplateSegment[] | string;
    expected_whole_literal?: boolean;
    expected_repeat?: { times: number; segment: TemplateSegment };
    expected_error?: string;
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

/** A vector this engine runs: no `engines` key, or one that names 'ts'. */
function runsHere(vector: TemplateVector): boolean {
    return vector.engines === undefined || vector.engines.includes('ts');
}

describe('golden template vectors (PHP ⇄ TS drift contract)', () => {
    for (const vector of loadVectors()) {
        if (!runsHere(vector)) {
            it.skip(`skipped (PHP-only, awaiting H6b's displayValue twin): ${vector.name}`, () => {});
            continue;
        }

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

        // Counted on BOTH sides including the PHP-only render vectors, so a vector cannot be dropped here
        // to make a skip disappear.
        expect(loaded).toBe(manifest.total); // a dropped/added vector fails here
        expect(perFile).toEqual(manifest.files); // a vector moved between files fails here
        expect(new Set(names).size).toBe(manifest.total); // a duplicate name fails here
    });
});
