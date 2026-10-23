/**
 * M185 (`R-7418e6bd`) — what the builder's Preview does when an author adds or renames a grid's row or column: one
 * immediate write through the store's `setFieldConfig` (one PATCH, one undo entry), a new entry opened for its name, a
 * rename that changes a label only, and nothing at all for a grid whose rows come from a list or a form.
 */
import { flushPromises } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { defineComponent } from 'vue';

import { createPreviewGridAuthoring } from './preview-grid-authoring';
import type { LocalField } from './types';

const Editor = defineComponent({ render: () => null });

function gridField(config: Record<string, unknown>, fieldType = 'matrix'): LocalField {
    return { uid: 'u1', key: 'check', field_type: fieldType, label: 'Check', config } as unknown as LocalField;
}

function authoringFor(field: LocalField | null, options: { reordering?: boolean; root?: HTMLElement | null } = {}) {
    const setFieldConfig = vi.fn();
    const authoring = createPreviewGridAuthoring({
        store: { setFieldConfig },
        fieldByKey: (key) => (field !== null && key === field.key ? field : null),
        isReordering: () => options.reordering === true,
        root: () => options.root ?? null,
        editor: Editor,
    });

    return { authoring, setFieldConfig };
}

const ROWS = [{ value: 'row_1', label: 'Row 1' }];

describe('createPreviewGridAuthoring', () => {
    it('adds a row now, numbered as the Grid tab numbers it, and opens it for its name', () => {
        const { authoring, setFieldConfig } = authoringFor(gridField({ rows: ROWS, columns: [] }));

        authoring.add('check', 'rows');

        expect(setFieldConfig).toHaveBeenCalledWith('u1', { rows: [...ROWS, { value: 'row_2', label: 'Row 2' }] }, { now: true });
        expect(authoring.editing.value).toEqual({ key: 'check', part: 'rows', value: 'row_2' });
    });

    it('adds a Likert matrix’s scale point as a Point, and a Grid’s column as a Column', () => {
        const likert = authoringFor(gridField({ rows: ROWS, columns: [] }, 'likert_matrix'));
        likert.authoring.add('check', 'columns');
        expect(likert.setFieldConfig).toHaveBeenCalledWith('u1', { columns: [{ value: 'col_1', label: 'Point 1' }] }, { now: true });

        const matrix = authoringFor(gridField({ rows: ROWS, columns: [] }));
        matrix.authoring.add('check', 'columns');
        expect(matrix.setFieldConfig).toHaveBeenCalledWith('u1', { columns: [{ value: 'col_1', label: 'Column 1' }] }, { now: true });
    });

    it('renames a label now and keeps the value; a blank or unchanged name writes nothing; either way the header closes', () => {
        const columns = [{ value: 'col_1', label: 'Column 1', type: 'integer' }];
        const { authoring, setFieldConfig } = authoringFor(gridField({ rows: ROWS, columns }));
        const edit = { key: 'check', part: 'columns' as const, value: 'col_1' };

        authoring.open(edit);
        authoring.commit(edit, '  Staff on duty ', 'blur');
        expect(setFieldConfig).toHaveBeenCalledWith('u1', { columns: [{ value: 'col_1', label: 'Staff on duty', type: 'integer' }] }, { now: true });
        expect(authoring.editing.value).toBeNull();

        authoring.commit(edit, '   ', 'blur');
        authoring.commit(edit, 'Column 1', 'blur');
        expect(setFieldConfig).toHaveBeenCalledTimes(1);
    });

    it('edits nothing in a grid whose rows come from elsewhere, and says where they come from', () => {
        expect(authoringFor(gridField({ rows: [], rows_list: 'wards' })).authoring.rowsFrom('check')).toBe('The rows come from a list file. Change them on the Grid tab.');
        expect(authoringFor(gridField({ rows: [], rows_source: { form_id: null, field_key: null } })).authoring.rowsFrom('check')).toBe(
            'The rows come from another form’s answers. Change them on the Grid tab.',
        );
        expect(authoringFor(gridField({ rows: ROWS })).authoring.rowsFrom('check')).toBeNull();
    });

    it('starts no edit while a question is being moved', () => {
        const { authoring, setFieldConfig } = authoringFor(gridField({ rows: ROWS, columns: [] }), { reordering: true });

        authoring.add('check', 'rows');
        authoring.open({ key: 'check', part: 'rows', value: 'row_1' });

        expect(setFieldConfig).not.toHaveBeenCalled();
        expect(authoring.editing.value).toBeNull();
    });

    it('puts the keyboard back on the header’s rename after Enter, found by its value read back — a copied row’s value is text', async () => {
        const root = document.createElement('div');
        root.innerHTML = '<div data-preview-field="check"><button data-grid-rename-row="San Jose &quot;RHU&quot;"></button></div>';
        document.body.append(root);
        const { authoring } = authoringFor(gridField({ rows: [{ value: 'San Jose "RHU"', label: 'San Jose "RHU"' }] }), { root });
        const edit = { key: 'check', part: 'rows' as const, value: 'San Jose "RHU"' };

        authoring.open(edit);
        authoring.commit(edit, 'San Jose', 'key');
        await flushPromises();

        expect(document.activeElement).toBe(root.querySelector('button'));
        root.remove();
    });
});
