/**
 * How a list question's choices are arranged (M130, `R-048a3286`) — read from the question's `appearance`.
 *
 * The author chooses from `App\Enums\FieldAppearance` (`columns-pack`, `columns`), which the builder receives
 * on the palette. This module only READS the stored value, and it reads it the way ODK does: appearances are
 * space-separated tokens, so `columns-pack no-buttons` still lays the choices out side by side. A token it
 * does not know is ignored — published snapshots may hold any imported string, and a renderer that refused
 * one would break a form nobody can republish in time.
 *
 * `ChoiceAppearance` is pinned to the PHP enum by `DocumentedEnumMirrorDriftTest`, and declared HERE, in a
 * `.ts`, rather than in `FieldInput.vue`: that test's property grammar is first-match-wins across an SFC's
 * template and style, and `appearance` is also a CSS property name.
 */

/** The layouts an author may choose — `FieldAppearance`'s values, mirrored and pinned. */
export type ChoiceAppearance = 'columns-pack' | 'columns';

/** What the renderer draws: the default single column, a wrapping row, or a grid of equal columns. */
export type ChoiceLayout = 'stacked' | 'pack' | 'columns';

/** The field types whose choices take a layout — `FieldAppearance::for()`'s non-empty arm. */
const LAYOUT_TYPES = new Set<string>(['single_select', 'multi_select']);

// A Map, not an object literal: `token in {...}` is also true for inherited names, so an imported appearance
// spelled `toString` or `constructor` would have matched.
const LAYOUT_BY_APPEARANCE = new Map<string, ChoiceLayout>([
    ['columns-pack' satisfies ChoiceAppearance, 'pack'],
    ['columns' satisfies ChoiceAppearance, 'columns'],
]);

/** The layout to draw a question's choices in. Anything unknown, absent or on another type is `stacked`. */
export function choiceLayoutFor(fieldType: string, appearance: string | null | undefined): ChoiceLayout {
    if (!LAYOUT_TYPES.has(fieldType) || typeof appearance !== 'string') {
        return 'stacked';
    }

    for (const token of appearance.trim().toLowerCase().split(/\s+/)) {
        const layout = LAYOUT_BY_APPEARANCE.get(token);
        if (layout !== undefined) {
            return layout;
        }
    }

    return 'stacked';
}
