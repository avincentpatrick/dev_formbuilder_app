import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it } from 'vitest';
import { nextTick } from 'vue';

import ConflictDialog from './ConflictDialog.vue';
import type { BuilderEnums, LocalField, ServerField } from './types';
import type { ConflictState } from './useBuilderStore';

/**
 * M176 (`R-164579e8`) — the dialog says what differs, and closing it never chooses. Rendered in place (`teleport: false`
 * falls through to the `MdsModal` root), as `ConvertFieldDialog.test.ts` does.
 */

const ENUMS: BuilderEnums = {
    required_modes: [
        { value: 'optional', label: 'Optional' },
        { value: 'required', label: 'Required' },
    ],
    indexed_data_types: [],
    validation_rule_types: [],
    comparison_operators: [],
};

function server(overrides: Partial<ServerField> = {}): ServerField {
    return {
        id: 'f1', form_section_id: null, key: 'occupation', field_type: 'short_text', label: 'Occupation', hint: null,
        placeholder: null, is_required: 'optional', relevant_expression: null, appearance: null, config: {}, default_value: null,
        is_pii: false, is_sensitive: false, is_queryable: false, indexed_data_type: null, sequence: 1, section_sequence: 1,
        version: 'v2', validations: [], ...overrides,
    } as ServerField;
}

function conflictOver(mine: Partial<ServerField>): ConflictState {
    return { kind: 'field', uid: 'u1', mine: { ...server({ version: 'v1', ...mine }), uid: 'u1' } as LocalField, theirs: server() };
}

function mountDialog(conflict: ConflictState | null, deferred = false) {
    return mount(ConflictDialog, {
        props: { conflict, deferred, enums: ENUMS },
        attrs: { teleport: false },
        attachTo: document.body,
    });
}

afterEach(() => {
    document.body.innerHTML = '';
});

describe('ConflictDialog', () => {
    it('lists the setting that differs, side by side — not two identical labels', () => {
        const wrapper = mountDialog(conflictOver({ is_required: 'required' }));

        const rows = wrapper.findAll('[data-conflict-difference]');
        expect(rows).toHaveLength(1);
        expect(rows[0].findAll('th, td').map((cell) => cell.text())).toEqual(['Required', 'Required', 'Optional']);
        expect(wrapper.text()).toContain('Your version');
        expect(wrapper.text()).toContain('Saved version');
    });

    it('says nothing is lost by keeping yours when only the save time differs', () => {
        const wrapper = mountDialog(conflictOver({}));

        expect(wrapper.find('[data-conflict-difference]').exists()).toBe(false);
        expect(wrapper.get('[data-conflict-same]').text()).toContain('Keeping yours loses nothing');
    });

    it('defers when it is closed, and never chooses the saved version for the author', async () => {
        const wrapper = mountDialog(conflictOver({ is_required: 'required' }));

        await wrapper.get('button[aria-label="Decide later"]').trigger('click');
        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
        await nextTick();

        expect(wrapper.emitted('defer')?.length ?? 0).toBeGreaterThan(0);
        expect(wrapper.emitted('resolve')).toBeUndefined();
    });

    it('chooses only through its two buttons', async () => {
        const wrapper = mountDialog(conflictOver({ is_required: 'required' }));
        const buttons = wrapper.findAll('button').filter((button) => ['Keep mine', 'Use the saved version'].includes(button.text()));

        await buttons.find((button) => button.text() === 'Use the saved version')!.trigger('click');
        await buttons.find((button) => button.text() === 'Keep mine')!.trigger('click');

        expect(wrapper.emitted('resolve')).toEqual([['theirs'], ['mine']]);
    });

    it('quotes a long question by its first words, not in full', () => {
        const long = 'How many people usually sleep in this house, including children, older relatives and seasonal guests?';
        const lead = mountDialog(conflictOver({ label: long, is_required: 'required' })).get('.conflict__lead').text();

        expect(lead).toContain('“How many people usually sleep in this house, including…”');
        expect(lead).not.toContain('seasonal guests');
    });

    it('stays closed while the choice is deferred', () => {
        const wrapper = mountDialog(conflictOver({ is_required: 'required' }), true);

        expect(wrapper.find('[role="dialog"]').exists()).toBe(false);
    });
});
