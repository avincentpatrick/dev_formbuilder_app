/**
 * The builder Preview's editing of a grid's rows and columns (M185, `R-7418e6bd`) — "+ Add row", "+ Add column" and a
 * rename on every row and column header, in the grid the Preview draws.
 *
 * `MatrixGrid.vue` ships in the guest form and on the encode page as well as in the Preview, so it imports nothing of the
 * builder's: the Preview PROVIDES this object — its writes, its edit state and the in-place editor component itself — and
 * the grid only `inject`s it. Nothing is provided anywhere else, so every other surface draws the grid it always drew. The
 * edit state lives with the provider, not in the grid, because the Preview rebuilds (and remounts) the grid as rows change.
 *
 * Type-only imports: this module adds nothing to a bundle but a symbol.
 */
import type { Component, InjectionKey, Ref } from 'vue';

/** One header open for renaming: the question's key, its rows or its columns, and the entry's value. */
export interface GridEdit {
    key: string;
    part: 'rows' | 'columns';
    value: string;
}

export interface GridAuthoring {
    /** The header open for renaming, if any. */
    editing: Readonly<Ref<GridEdit | null>>;
    /** Where a grid's rows come from when they are not typed here — they are not edited in the Preview then — else null. */
    rowsFrom(key: string): string | null;
    /** Add a row or a column (a Likert matrix's scale point) to the grid, and open its rename. */
    add(key: string, part: 'rows' | 'columns'): void;
    open(edit: GridEdit): void;
    /** Save a header's new label — Enter keeps the keyboard on the header's rename button, a blur leaves it where it went. */
    commit(edit: GridEdit, label: string, via: 'key' | 'blur'): void;
    cancel(): void;
    /** The in-place editor: `value` and `label` props, `commit(value, via)` and `cancel` events. */
    editor: Component;
}

export const GridAuthoringKey: InjectionKey<GridAuthoring> = Symbol('grid-authoring');
