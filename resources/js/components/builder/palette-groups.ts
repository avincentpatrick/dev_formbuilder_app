import type { PaletteGroup } from './types';

/**
 * The palette's groups as an author picks from them — shared by the left pane's palette and the "Add a question here"
 * list (M176, `R-5354b4b9`), so the two never offer different types.
 *
 * Increment M125 (`R-a367bf9e`): ONE entry per variant group — "Text", "Number" — at its primary member's place,
 * adding that member. The other members are a setting in the Basics tab, not a kind of question, so they are not
 * listed here. Which types are variants is transmitted (`PaletteType.variant`); a type without one is listed as before.
 *
 * M177 (`R-0b7db001`, `R-22011569`, `D140`): a RETIRED type is not listed either — Yes/No and Duration — and a group left
 * with nothing to add is dropped rather than shown as a heading over an empty list.
 */
export function pickableGroups(palette: readonly PaletteGroup[]): PaletteGroup[] {
    return palette
        .map((group) => ({
            ...group,
            types: group.types
                .filter((type) => !type.retired && (!type.variant || type.variant.primary))
                .map((type) => (type.variant ? { ...type, label: type.variant.label } : type)),
        }))
        .filter((group) => group.types.length > 0);
}
