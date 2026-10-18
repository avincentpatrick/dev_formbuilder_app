import { describe, expect, it } from 'vitest';

import { applyShowMode, showModeFixed, showModeOf } from './field-visibility';

/**
 * M177 (`R-f44e9d00`) — "Show to respondents: Always · Never · Only when…". The mode is DERIVED from what is stored —
 * `config.visibility` (`hidden` = Never, `shown` = Always for a calculation, which is Never by default) and the
 * condition — never kept as a fourth setting that could disagree with them.
 */
const q = (field_type: string, config: Record<string, unknown> = {}, relevant_expression: string | null = null) => ({ field_type, config, relevant_expression });

describe('showModeOf', () => {
    it('reads an ordinary question: Always, Only when with a condition, Never when hidden', () => {
        expect(showModeOf(q('short_text'))).toBe('always');
        expect(showModeOf(q('short_text', {}, "${a} = 'y'"))).toBe('when');
        expect(showModeOf(q('short_text', { visibility: 'hidden' }, "${a} = 'y'"))).toBe('never');
    });

    it('reads a calculation as Never until it is set to show', () => {
        expect(showModeOf(q('calculated'))).toBe('never');
        expect(showModeOf(q('calculated', { visibility: 'shown' }))).toBe('always');
        expect(showModeOf(q('calculated', { visibility: 'shown' }, "${a} = 'y'"))).toBe('when');
    });

    it('fixes the Hidden type at Never, and offers nothing for a page break', () => {
        expect(showModeOf(q('hidden', { visibility: 'shown' }))).toBe('never');
        expect(showModeFixed('hidden')).toBe(true);
        expect(showModeFixed('page_break')).toBe(true);
        expect(showModeFixed('short_text')).toBe(false);
    });
});

describe('applyShowMode', () => {
    it('Always clears the condition, and for a calculation stores that it shows', () => {
        expect(applyShowMode(q('short_text', { visibility: 'hidden', options: [] }, "${a} = 'y'"), 'always')).toEqual({ config: { options: [] }, relevant_expression: null });
        expect(applyShowMode(q('calculated', { calculated_formula: '1' }, "${a} = 'y'"), 'always')).toEqual({ config: { calculated_formula: '1', visibility: 'shown' }, relevant_expression: null });
    });

    it('Never keeps the condition, to use again', () => {
        expect(applyShowMode(q('short_text', {}, "${a} = 'y'"), 'never')).toEqual({ config: { visibility: 'hidden' }, relevant_expression: "${a} = 'y'" });
        expect(applyShowMode(q('calculated', { visibility: 'shown' }), 'never')).toEqual({ config: {}, relevant_expression: null });
    });

    it('Only when keeps the condition and lets the question show', () => {
        expect(applyShowMode(q('short_text', { visibility: 'hidden' }, "${a} = 'y'"), 'when')).toEqual({ config: {}, relevant_expression: "${a} = 'y'" });
        expect(applyShowMode(q('calculated'), 'when')).toEqual({ config: { visibility: 'shown' }, relevant_expression: null });
    });
});
