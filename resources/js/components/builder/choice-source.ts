/**
 * Where a choice question's choices come from (M184, T3): typed here, a list file of this form (`options_list`, the
 * file's name), or another form's answers (`options_source`, M133). One answer for every place that used to ask
 * "is it linked?" — the Options tab, the default-value choices, "Other, please specify" and the preview's empty-list
 * note — so a list-bound question is treated as a linked one is: it has no choices typed here, by design.
 *
 * ⚠️ A DECLARED SOURCE COUNTS WHILE IT IS STILL BEING PICKED. The editor holds `{form_id: null, field_key: null}` or
 * `options_list: ''` until the author chooses, and the question is not "typed" in between — the server's
 * `ChoiceSource::of()` reads the same keys the same way, and publish says what is unfinished.
 */
export type ChoiceSourceKind = 'typed' | 'list' | 'form';

export function choiceSourceOf(config: Record<string, unknown>): ChoiceSourceKind {
    if (config.options_source != null) return 'form';
    if (config.options_list != null) return 'list';

    return 'typed';
}
