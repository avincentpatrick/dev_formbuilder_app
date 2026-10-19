/**
 * What the "Insert a question" picker offers (M178, `R-75719ac4`). The text-spot scope is pinned against the server by
 * the shared `tests/fixtures/template-scope.json` (also run by `tests/Unit/Templates/TemplateScopeParityTest.php`).
 */
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';
import { DATE_GROUP, LEAD_GROUP, findTrigger, matchCandidates, referenceCandidates, type ReferenceField, type ReferenceSection, type ReferenceSource, type TemplateHost } from './reference-candidates';
import type { OperandKindOption, PaletteType } from './types';

interface FixtureField { key: string; label: string; field_type: string; pipeable: boolean; section: string | null; sequence: number }
interface Fixture {
    form: { sections: ReferenceSection[]; fields: FixtureField[] };
    cases: { name: string; host: TemplateHost; offered: string[] }[];
}

const fixture = JSON.parse(readFileSync(join(process.cwd(), 'tests/fixtures/template-scope.json'), 'utf8')) as Fixture;

function fixtureSource(): ReferenceSource {
    const idOf = (key: string | null) => fixture.form.sections.find((section) => section.key === key)?.id ?? null;
    const fields: ReferenceField[] = fixture.form.fields.map((field) => ({
        key: field.key, label: field.label, field_type: field.field_type, form_section_id: idOf(field.section), sequence: field.sequence,
    }));
    const types = [...new Map(fixture.form.fields.map((field) => [field.field_type, field])).values()].map(
        (field) => ({ value: field.field_type, label: field.field_type, operand_kind: 'value', pipeable: field.pipeable }) as PaletteType,
    );

    return { fields, sections: fixture.form.sections, types };
}

describe('referenceCandidates — a text spot offers what the publish gate accepts (the shared fixture)', () => {
    for (const testCase of fixture.cases) {
        it(testCase.name, () => {
            const offered = referenceCandidates(fixtureSource(), { grammar: 'template', host: testCase.host }).map((candidate) => candidate.key);

            expect([...offered].sort()).toEqual([...testCase.offered].sort());
        });
    }

    it('lists them in canvas order, grouped by section, each with its label and code', () => {
        const offered = referenceCandidates(fixtureSource(), { grammar: 'template', host: { kind: 'confirmation' } });

        expect(offered.map((candidate) => [candidate.group, candidate.label, candidate.key])).toEqual([
            [LEAD_GROUP, 'Your name', 'name'],
            [LEAD_GROUP, 'Total', 'total'],
            [LEAD_GROUP, 'City', 'city'],
            ['Household', 'Household size', 'size'],
            ['Household', 'Head of household', 'head'],
            // An unnamed section and an unlabelled question are listed by their codes.
            ['closing', 'token', 'token'],
            ['closing', 'Remarks', 'remarks'],
        ]);
        expect(offered[0].insert).toBe('${name}');
    });
});

describe('referenceCandidates — a formula spot', () => {
    const KINDS = [
        { value: 'value', offered: true },
        { value: 'number', offered: true },
        { value: 'computed', offered: true },
        { value: 'object', offered: false },
        { value: 'none', offered: false },
    ] as OperandKindOption[];
    const TYPES = [
        { value: 'short_text', operand_kind: 'value' },
        { value: 'integer', operand_kind: 'number' },
        { value: 'calculated', operand_kind: 'computed', pipeable: true },
        { value: 'matrix', operand_kind: 'object' },
        { value: 'note', operand_kind: 'none' },
    ] as PaletteType[];
    const field = (key: string, field_type: string, sequence: number): ReferenceField => ({ key, label: key, field_type, form_section_id: null, sequence });
    const source: ReferenceSource = {
        fields: [field('total', 'calculated', 0), field('age', 'integer', 1), field('grid', 'matrix', 2), field('intro', 'note', 3), field('later', 'short_text', 4)],
        sections: [],
        types: TYPES,
        kinds: KINDS,
    };

    it('offers every question a formula may name, later ones too, but not itself, a grid, a note or — before R4 — a calculation', () => {
        const offered = referenceCandidates(source, { grammar: 'expression', ownKey: 'age' }).map((candidate) => candidate.key);

        expect(offered).toEqual(['later', 'today()']);
    });

    it('ends with Today, which writes today()', () => {
        const offered = referenceCandidates(source, { grammar: 'expression', ownKey: null });
        const today = offered[offered.length - 1];

        expect([today.group, today.label, today.insert]).toEqual([DATE_GROUP, 'Today', 'today()']);
    });
});

describe('typing to find a question', () => {
    it('opens on @ at the start of a word, and on ${, with what has been typed so far', () => {
        expect(findTrigger('How old is @na')).toEqual({ from: 11, query: 'na' });
        expect(findTrigger('@')).toEqual({ from: 0, query: '' });
        expect(findTrigger('. <= ${li')).toEqual({ from: 5, query: 'li' });
    });

    it('leaves an email address and an escaped $${ alone, and closes once a space is typed', () => {
        expect(findTrigger('write to ana@example')).toBeNull();
        expect(findTrigger('costs $${pr')).toBeNull();
        expect(findTrigger('How old is @name ')).toBeNull();
    });

    it('matches by code or by label, keeping the order', () => {
        const candidates = referenceCandidates(fixtureSource(), { grammar: 'template', host: { kind: 'confirmation' } });

        expect(matchCandidates(candidates, 'HOUSE').map((candidate) => candidate.key)).toEqual(['size', 'head']);
        expect(matchCandidates(candidates, 'rem').map((candidate) => candidate.key)).toEqual(['remarks']);
    });
});
