import { describe, expect, it } from 'vitest';
import { gridHasRules, gridRuleSites } from './grid-rules';

/*
 * M246 (`grid-question-table`) — the builder's list of a grid's own rules, the mirror of
 * `app/Support/Forms/GridRules.php` (pinned there by `tests/Unit/Forms/GridRulesTest.php` on the same config).
 */

const config = {
    rows: [
        { value: 'r1', label: 'First' },
        { value: 'r2', label: 'Second', relevant: "${kept} = 'yes'", not_needed: ['why'] },
        { value: 'r3', label: 'Third', relevant: '   ' },
    ],
    columns: [
        { value: 'agree', label: 'Agree', type: 'single_select' },
        { value: 'why', label: 'Why', type: 'long_text', relevant: "${region} != ''", needed_when: "${.agree} = 'no'", required_when: "${.agree} = 'no' and ${strict} = 1" },
    ],
    cells: [],
};

describe('gridRuleSites', () => {
    it('lists every non-blank row and column rule, rows first, with where ${.col} may be used — as PHP does', () => {
        expect(gridRuleSites('matrix', config)).toEqual([
            { expression: "${kept} = 'yes'", rule: 'row', row: 'r2', column: null, rowScope: false },
            { expression: "${region} != ''", rule: 'column', row: null, column: 'why', rowScope: false },
            { expression: "${.agree} = 'no'", rule: 'needed', row: null, column: 'why', rowScope: true },
            { expression: "${.agree} = 'no' and ${strict} = 1", rule: 'required', row: null, column: 'why', rowScope: true },
        ]);
    });

    it('reads only row rules on a Likert grid, and nothing on any other type or a malformed config', () => {
        expect(gridRuleSites('likert_matrix', config).map((site) => site.rule)).toEqual(['row']);
        expect(gridRuleSites('short_text', config)).toEqual([]);
        expect(gridRuleSites('matrix', null)).toEqual([]);
        expect(gridRuleSites('matrix', { rows: 'oops', columns: [7, 'x'] })).toEqual([]);
    });

    it('says whether a grid has rules at all, counting a "Not needed" cell', () => {
        expect(gridHasRules('matrix', config)).toBe(true);
        expect(gridHasRules('matrix', { rows: [{ value: 'a', not_needed: ['x'] }], columns: [] })).toBe(true);
        expect(gridHasRules('matrix', { rows: [{ value: 'a', not_needed: [] }], columns: [] })).toBe(false);
    });
});
