/**
 * Golden-vector runner (the drift contract, Risk R3) — the TypeScript half of the shared expression suite.
 * Iterates the SAME language-neutral fixtures under `tests/golden/expressions/*.json` that the PHP Pest
 * runner (`tests/Unit/Expressions/GoldenVectorsTest.php`) runs, so any drift between the two engines is a
 * CI failure, not a silent UX inconsistency. The loading + assertion logic mirrors the PHP runner exactly,
 * including the count-parity / per-file / name-uniqueness guards so neither side can quietly drop a vector.
 */

import { readFileSync, readdirSync } from 'node:fs';
import { basename, dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';

import { EvaluationContext } from '../context';
import { ExpressionSyntaxError } from '../errors';
import { GRAMMAR_VERSION, makeExpressionEvaluator } from '../evaluator';

const here = dirname(fileURLToPath(import.meta.url));
const goldenDir = join(here, '..', '..', '..', '..', 'tests', 'golden', 'expressions');

type ExpressionVector = {
    name: string;
    grammar_version: string;
    expression?: string;
    expression_repeat?: { prefix: string; times: number; core: string; suffix: string };
    context?: { answers?: Record<string, unknown>; self?: unknown };
    eval?: 'bool' | 'value';
    expected?: unknown;
    expected_error?: string;
};

function vectorFiles(): string[] {
    return readdirSync(goldenDir).filter((file) => file.endsWith('.json') && file !== 'manifest.json');
}

function loadVectors(): ExpressionVector[] {
    const out: ExpressionVector[] = [];
    for (const file of vectorFiles()) {
        const cases = JSON.parse(readFileSync(join(goldenDir, file), 'utf8')) as ExpressionVector[];
        out.push(...cases);
    }

    return out;
}

function buildExpression(vector: ExpressionVector): string {
    if (vector.expression_repeat) {
        const { prefix, times, core, suffix } = vector.expression_repeat;

        return prefix.repeat(times) + core + suffix.repeat(times);
    }

    return vector.expression as string;
}

describe('golden expression vectors (PHP ⇄ TS drift contract)', () => {
    for (const vector of loadVectors()) {
        it(`matches the golden vector: ${vector.name}`, () => {
            expect(vector.grammar_version).toBe(GRAMMAR_VERSION);

            const engine = makeExpressionEvaluator();
            const context = EvaluationContext.fromVector((vector.context ?? {}) as { answers?: Record<string, never> });
            const expression = buildExpression(vector);

            if (Object.prototype.hasOwnProperty.call(vector, 'expected_error')) {
                let thrown: unknown;
                try {
                    engine.evaluate(expression, context);
                } catch (error) {
                    thrown = error;
                }

                expect(thrown, `expected error “${vector.expected_error}” but none was thrown for: ${expression}`).toBeInstanceOf(ExpressionSyntaxError);
                expect((thrown as ExpressionSyntaxError).slug).toBe(vector.expected_error);

                return;
            }

            const result = (vector.eval ?? 'bool') === 'value'
                ? engine.evaluate(expression, context)
                : engine.evaluateBoolean(expression, context);

            expect(result).toEqual(vector.expected);
        });
    }

    it('enforces the manifest count-parity, per-file, and name-uniqueness guards', () => {
        const manifest = JSON.parse(readFileSync(join(goldenDir, 'manifest.json'), 'utf8')) as { total: number; files: Record<string, number> };

        const perFile: Record<string, number> = {};
        const names: string[] = [];
        let loaded = 0;

        for (const file of vectorFiles()) {
            const cases = JSON.parse(readFileSync(join(goldenDir, file), 'utf8')) as ExpressionVector[];
            perFile[basename(file, '.json')] = cases.length;
            loaded += cases.length;
            for (const vector of cases) {
                names.push(vector.name);
            }
        }

        expect(loaded).toBe(manifest.total); // a dropped/added vector fails here
        expect(perFile).toEqual(manifest.files); // a vector moved between files fails here
        expect(new Set(names).size).toBe(manifest.total); // a duplicate name fails here
    });
});
