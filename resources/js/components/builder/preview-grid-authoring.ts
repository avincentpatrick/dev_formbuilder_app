/**
 * The builder Preview's editing of a grid's rows and columns (M185, `R-7418e6bd`) — what `PreviewPane.vue` provides
 * under `GridAuthoringKey` for `MatrixGrid.vue` to draw.
 *
 * Every write goes through the store's one config write, `setFieldConfig`, saved NOW: one PATCH and one "Edit <label>"
 * undo entry per finished edit, exactly as an in-place question label (`renameField`) and as the Grid tab's lists, whose
 * add and rename rules (`grid-options.ts`) these share. A rename changes a label only, never the value answers are saved
 * under. A grid whose rows come from a list or a form is not edited here: it says where they come from.
 *
 * The open header is held here, not in the grid, because adding a row rebuilds — remounts — the grid; the pane holds that
 * rebuild until the header closes, then puts the keyboard back on the header's rename button.
 */
import { nextTick, ref, type Component, type Ref } from 'vue';

import type { GridAuthoring, GridEdit } from '../submissions/grid-authoring';
import { addGridEntry, renameGridEntry, type GridEntry } from './grid-options';
import { slotIsSourced } from './grid-sources';
import type { LocalField } from './types';
import type { BuilderStore } from './useBuilderStore';

export interface PreviewGridAuthoring extends GridAuthoring {
    editing: Ref<GridEdit | null>;
}

interface Context {
    store: Pick<BuilderStore, 'setFieldConfig'>;
    /** The draft question a rendered key belongs to. */
    fieldByKey: (key: string) => LocalField | null;
    /** No edit starts while a question is being moved. */
    isReordering: () => boolean;
    /** The pane, where the rename button is found again after the edit (and any rebuild it held). */
    root: () => HTMLElement | null;
    editor: Component;
}

function listOf(field: LocalField, part: GridEdit['part']): GridEntry[] {
    const list = field.config[part];

    return Array.isArray(list) ? (list as GridEntry[]) : [];
}

export function createPreviewGridAuthoring(context: Context): PreviewGridAuthoring {
    const editing = ref<GridEdit | null>(null);

    function end(edit: GridEdit, refocus: boolean): void {
        editing.value = null;
        if (!refocus) return;
        const attr = edit.part === 'rows' ? 'data-grid-rename-row' : 'data-grid-rename-column';
        void nextTick(() => {
            const buttons = context.root()?.querySelectorAll<HTMLElement>(`[data-preview-field="${edit.key}"] [${attr}]`) ?? [];
            // By attribute value read back, never built into a selector: a copied row's value is an answer's text.
            Array.from(buttons).find((button) => button.getAttribute(attr) === edit.value)?.focus();
        });
    }

    return {
        editing,
        rowsFrom(key) {
            const field = context.fieldByKey(key);
            if (field === null || !slotIsSourced(field.config, 'rows')) return null;

            return field.config.rows_source != null
                ? 'The rows come from another form’s answers. Change them on the Grid tab.'
                : 'The rows come from a list file. Change them on the Grid tab.';
        },
        add(key, part) {
            const field = context.fieldByKey(key);
            if (field === null || context.isReordering()) return;
            const singular = part === 'rows' ? 'Row' : field.field_type === 'likert_matrix' ? 'Point' : 'Column';
            const next = addGridEntry(listOf(field, part), part === 'rows' ? 'row' : 'col', singular);
            context.store.setFieldConfig(field.uid, { [part]: next }, { now: true });
            editing.value = { key, part, value: next[next.length - 1].value };
        },
        open(edit) {
            if (!context.isReordering()) editing.value = edit;
        },
        commit(edit, label, via) {
            const field = context.fieldByKey(edit.key);
            const list = field === null ? [] : listOf(field, edit.part);
            const index = list.findIndex((entry) => entry.value === edit.value);
            const next = label.trim();
            if (field !== null && index >= 0 && next !== '' && next !== list[index].label) {
                context.store.setFieldConfig(field.uid, { [edit.part]: renameGridEntry(list, index, next) }, { now: true });
            }
            // Enter keeps the keyboard on the header; a blur went somewhere the author chose.
            end(edit, via === 'key');
        },
        cancel() {
            if (editing.value !== null) end(editing.value, true);
        },
        editor: context.editor,
    };
}
