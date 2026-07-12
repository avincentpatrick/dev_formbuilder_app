/**
 * Golden semantic-validation runner (the validation drift contract, Risk R3) — the TypeScript half of the
 * shared validation suite. Iterates the SAME fixtures under `tests/golden/validation/*.json` that the PHP
 * Pest runner (`tests/Unit/Validation/SemanticGoldenVectorsTest.php`) runs — a schema fragment + answers →
 * the expected relevance mask, errors, effective answers, and computed values. Ids are set equal to keys
 * (identity id→key map), matching the PHP `buildSemanticInput`, and the same field/rule defaults
 * (short-text, optional) are applied so the two engines see byte-identical inputs.
 */

import { readFileSync, readdirSync } from 'node:fs';
import { basename, dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';

import type { EngineValue } from '../coercion';
import { GRAMMAR_VERSION } from '../evaluator';
import type { InstanceAnswers, SchemaField, SchemaSection, SemanticInput, ValidationRow } from '../schema';
import type { ComparisonOperator, LogicOperator, RequiredMode, ValidationRuleType } from '../enums';
import { errorPath, makeSemanticValidator } from '../semantic-validator';

type VectorAnswers = Record<string, EngineValue | InstanceAnswers[]>;

const here = dirname(fileURLToPath(import.meta.url));
const goldenDir = join(here, '..', '..', '..', '..', 'tests', 'golden', 'validation');

type RuleFragment = {
    id?: string;
    rule_type?: ValidationRuleType;
    operator?: ComparisonOperator;
    related?: string;
    rule_value?: string;
    expression?: string;
    sequence?: number;
    logic_group?: string;
    logic_operator?: LogicOperator;
    error_message?: string;
    error_message_translations?: Record<string, string>;
};

type FieldFragment = {
    key: string;
    field_type?: string;
    is_required?: RequiredMode;
    section?: string;
    relevant?: string;
    calculate?: string;
    rules?: RuleFragment[];
    // Choice-membership + cascading config (G4a) — mirror of the PHP builder's config injection.
    options?: string[];
    cascade?: { levels: string[]; options: { value: string; level: string; parent: string | null }[] };
};

type SectionFragment = { key: string; relevant?: string; repeatable?: boolean; min?: number; max?: number };

type SemanticVector = {
    name: string;
    grammar_version: string;
    now?: string;
    schema: { sections?: SectionFragment[]; fields: FieldFragment[] };
    answers?: VectorAnswers;
    locale?: string;
    expected: {
        relevance?: { fields?: Record<string, boolean>; sections?: Record<string, boolean> };
        errors?: { field: string; rule: string }[];
        effective_answers?: VectorAnswers;
        computed?: Record<string, EngineValue>;
        repeat_relevance?: Record<string, Record<string, boolean>[]>;
    };
};

function vectorFiles(): string[] {
    return readdirSync(goldenDir).filter((file) => file.endsWith('.json') && file !== 'manifest.json');
}

function loadVectors(): SemanticVector[] {
    const out: SemanticVector[] = [];
    for (const file of vectorFiles()) {
        const cases = JSON.parse(readFileSync(join(goldenDir, file), 'utf8')) as SemanticVector[];
        out.push(...cases);
    }

    return out;
}

function buildRuleRow(rule: RuleFragment, fieldKey: string, index: number): ValidationRow {
    return {
        id: rule.id ?? `${fieldKey}-${index}`,
        form_field_id: fieldKey,
        sequence: rule.sequence ?? index,
        rule_type: rule.rule_type ?? null,
        operator: rule.operator ?? null,
        related_form_field_id: rule.related ?? null,
        rule_value: rule.rule_value ?? null,
        expression: rule.expression ?? null,
        logic_group: rule.logic_group ?? null,
        logic_operator: rule.logic_operator ?? null,
        error_message: rule.error_message ?? null,
        error_message_translations: rule.error_message_translations ?? null,
    };
}

function buildSemanticInput(vector: SemanticVector): SemanticInput {
    const sections: SchemaSection[] = (vector.schema.sections ?? []).map((section, index) => ({
        id: section.key,
        key: section.key,
        sequence: index,
        relevant_expression: section.relevant ?? null,
        is_repeatable: section.repeatable ?? false,
        min_instances: section.min ?? null,
        max_instances: section.max ?? null,
    }));

    const fields: SchemaField[] = [];
    const validations: ValidationRow[] = [];

    vector.schema.fields.forEach((field, index) => {
        fields.push({
            id: field.key,
            key: field.key,
            sequence: index,
            field_type: field.field_type ?? 'short_text',
            is_required: field.is_required ?? 'optional',
            form_section_id: field.section ?? null,
            relevant_expression: field.relevant ?? null,
            calculate: field.calculate ?? null,
            options: field.options ?? null,
            cascade: field.cascade ?? null,
        });

        (field.rules ?? []).forEach((rule, ruleIndex) => {
            validations.push(buildRuleRow(rule, field.key, ruleIndex));
        });
    });

    return { fields, sections, validations, answers: vector.answers ?? {}, locale: vector.locale ?? 'en', now: vector.now ?? null };
}

function sortErrors(errors: { field: string; rule: string }[]): { field: string; rule: string }[] {
    return [...errors].sort((a, b) => {
        if (a.field !== b.field) {
            return a.field < b.field ? -1 : 1;
        }

        return a.rule < b.rule ? -1 : a.rule > b.rule ? 1 : 0;
    });
}

describe('golden semantic-validation vectors (PHP ⇄ TS drift contract)', () => {
    for (const vector of loadVectors()) {
        it(`matches the golden semantic vector: ${vector.name}`, () => {
            expect(vector.grammar_version).toBe(GRAMMAR_VERSION);

            const result = makeSemanticValidator().evaluate(buildSemanticInput(vector));
            const expected = vector.expected;

            expect(result.fieldRelevance).toEqual(expected.relevance?.fields ?? {});
            expect(result.sectionRelevance).toEqual(expected.relevance?.sections ?? {});
            expect(result.effectiveAnswers).toEqual(expected.effective_answers ?? {});
            expect(result.computed).toEqual(expected.computed ?? {});
            expect(result.repeatFieldRelevance).toEqual(expected.repeat_relevance ?? {});

            const actual = result.errors.map((error) => ({ field: errorPath(error), rule: error.rule }));
            expect(sortErrors(actual)).toEqual(sortErrors(expected.errors ?? []));
        });
    }

    it('enforces the manifest count-parity, per-file, and name-uniqueness guards', () => {
        const manifest = JSON.parse(readFileSync(join(goldenDir, 'manifest.json'), 'utf8')) as { total: number; files: Record<string, number> };

        const perFile: Record<string, number> = {};
        const names: string[] = [];
        let loaded = 0;

        for (const file of vectorFiles()) {
            const cases = JSON.parse(readFileSync(join(goldenDir, file), 'utf8')) as SemanticVector[];
            perFile[basename(file, '.json')] = cases.length;
            loaded += cases.length;
            for (const vector of cases) {
                names.push(vector.name);
            }
        }

        expect(loaded).toBe(manifest.total);
        expect(perFile).toEqual(manifest.files);
        expect(new Set(names).size).toBe(manifest.total);
    });
});
