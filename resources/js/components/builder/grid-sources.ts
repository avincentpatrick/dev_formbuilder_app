/**
 * Where a grid's rows and shared cell choices come from (M185, `R-bb79aec0`, `D161`) — the builder's half of
 * `App\Services\Forms\GridSources`.
 *
 * A slot keeps three keys — `rows` / `rows_list` / `rows_source`, `cells` / `cells_list` / `cells_source` — named as a
 * choice question's `options` / `options_list` / `options_source` are. {@link asChoiceConfig} renames them to those, so the
 * one chooser (`LinkedChoicesEditor.vue`) and `choiceSourceOf()` read a grid's rows exactly as they read a question's
 * choices; {@link withChoiceConfig} writes what the chooser emits back under the slot's own names. A column needs neither:
 * its keys already ARE `options` / `options_list` / `options_source`.
 *
 * ⚠️ A KEY'S PRESENCE IS THE SOURCE. The chooser declares a source by setting its key (null while removed) and clears the
 * other by deleting it; the rename keeps both moves, so switching from a form to a list never leaves the form behind.
 */
import { choiceSourceOf } from './choice-source';

export type GridSlot = 'rows' | 'cells';

/** The slot under a choice question's key names — each present only where the grid holds it. */
export function asChoiceConfig(config: Record<string, unknown>, slot: GridSlot): Record<string, unknown> {
    const items = config[slot];
    const view: Record<string, unknown> = { options: Array.isArray(items) ? items : [] };
    if (`${slot}_list` in config) view.options_list = config[`${slot}_list`];
    if (`${slot}_source` in config) view.options_source = config[`${slot}_source`];

    return view;
}

/** The grid's config with the chooser's answer written back under the slot's own names. */
export function withChoiceConfig(config: Record<string, unknown>, slot: GridSlot, view: Record<string, unknown>): Record<string, unknown> {
    const next: Record<string, unknown> = { ...config, [slot]: Array.isArray(view.options) ? view.options : [] };
    const renames: [string, string][] = [
        ['options_list', `${slot}_list`],
        ['options_source', `${slot}_source`],
    ];
    for (const [from, to] of renames) {
        if (from in view) next[to] = view[from];
        else delete next[to];
    }

    return next;
}

/** Whether the slot's items come from a list file or another form rather than being typed here. */
export function slotIsSourced(config: Record<string, unknown>, slot: GridSlot): boolean {
    return choiceSourceOf(asChoiceConfig(config, slot)) !== 'typed';
}
