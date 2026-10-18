import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import { MdsBarChart } from '@meridian/design-system';

import QuestionResultCard from './QuestionResultCard.vue';
import type { QuestionAggregate } from './types';

/**
 * M177 (`R-71509975`): "Other, please specify" makes a choice valued `other` common, and the card's overflow bucket was
 * keyed `other` too — two bars with one Vue key beside each other. A bar's key now says which kind of bar it is.
 */
function question(overrides: Partial<QuestionAggregate> = {}): QuestionAggregate {
    return {
        key: 'occupation',
        label: 'Occupation',
        refused: false,
        reason: null,
        message: null,
        indexed_data_type: 'text',
        first_indexed_version: 1,
        type_changed_at_version: null,
        coverage: { indexed: 12, countable: 12 },
        indexed_column: true,
        rows: [
            { key: 'farmer', count: 5 },
            { key: 'other', count: 4 },
            { key: null, count: 1 },
        ],
        other: { count: 2, categories: 3 },
        summary: null,
        ...overrides,
    };
}

describe('QuestionResultCard — bar keys (M177)', () => {
    it('keys a real choice valued other apart from the overflow bucket', () => {
        const bars = mount(QuestionResultCard, { props: { question: question() } }).findComponent(MdsBarChart).props('data') as { key: string; label: string }[];

        const keys = bars.map((bar) => bar.key);
        expect(new Set(keys).size).toBe(keys.length);
        expect(bars.map((bar) => bar.label)).toEqual(['farmer', 'other', 'No answer', 'Other (3 values)']);
    });
});
