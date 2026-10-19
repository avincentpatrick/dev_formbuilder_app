import { describe, expect, it } from 'vitest';

import { PALETTE } from './builder-store-fixtures';
import { pickableGroups } from './palette-groups';
import type { PaletteGroup, PaletteType } from './types';

/**
 * M177 (`R-0b7db001`, `R-22011569`, `D140`): Yes/No and Duration leave the palette — and with it the "Add a question
 * here" list, which shares this one filter. The payload still carries every type (labels and lookups key off it), so
 * retiring is a transmitted flag, never a missing entry.
 */
const choice = (overrides: Partial<PaletteType>): PaletteType => ({
    value: 'single_select',
    label: 'Single choice',
    advanced: false,
    has_options: true,
    config_editor: 'choices',
    value_shape: 'choice',
    operand_kind: 'value',
    ...overrides,
});

const WITH_RETIRED: PaletteGroup[] = [
    ...PALETTE,
    {
        category: 'choice',
        label: 'Choice',
        icon: 'list',
        types: [
            choice({}),
            choice({ value: 'yes_no', label: 'Yes / No', has_options: false, config_editor: null, value_shape: 'boolean', retired: true }),
        ],
    },
    {
        category: 'datetime',
        label: 'Date & time',
        icon: 'calendar',
        types: [choice({ value: 'duration', label: 'Duration', has_options: false, config_editor: null, value_shape: 'duration', retired: true })],
    },
];

const offered = (groups: PaletteGroup[]): string[] => groups.flatMap((group) => group.types.map((type) => type.value));

describe('pickableGroups — retired types (M177)', () => {
    it('leaves out a type the server marks retired, and keeps its neighbours', () => {
        const values = offered(pickableGroups(WITH_RETIRED));

        expect(values).toContain('single_select');
        expect(values).not.toContain('yes_no');
        expect(values).not.toContain('duration');
    });

    it('drops a group left with nothing to add, so no heading stands over an empty list', () => {
        expect(pickableGroups(WITH_RETIRED).map((group) => group.category)).not.toContain('datetime');
    });

    it('lists every type when the palette carries no retired flag', () => {
        expect(offered(pickableGroups(PALETTE))).toEqual(['short_text', 'email', 'phone', 'integer', 'calculated', 'note', 'hidden', 'page_break']);
    });
});
