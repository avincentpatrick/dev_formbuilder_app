/**
 * The id convention `MdsPasswordStrength` publishes, and the one-line composition its four call sites
 * need (J3b).
 *
 * ── WHY THIS IS A MODULE AND NOT FOUR COPIES OF A TEMPLATE EXPRESSION ──────────────────────────────────
 * `MdsFormField` composes `aria-describedby` from help + error only, deliberately — widening it for one
 * consumer would put a live checklist into the description of every field in the product. So the
 * concatenation belongs at the CALL SITE. But there are four call sites (register, reset, invitation
 * accept, settings), and DSR §1.3 is explicit that three-plus repetitions of the same need signal a
 * missing shared thing rather than acceptable practice. More concretely: the `-strength` suffix is a
 * contract between the component and whatever points at it, and a fourth hand-typed copy is how one of
 * them comes to be subtly different — at which point the input simply describes nothing and no test
 * anywhere notices, because a dangling `aria-describedby` idref is silent in every engine.
 */

/** The id the checklist exposes for a given input. The component itself uses this, so it cannot drift. */
export function passwordStrengthId(inputId: string): string {
    return `${inputId}-strength`;
}

/**
 * `MdsFormField`'s `describedby` slot value with the checklist appended.
 *
 * Order matters and is deliberate: help and error come first, so a screen reader reaches the field's own
 * validation message before a five-item requirement list. `filter(Boolean)` because FormField yields
 * `undefined` when there is neither help nor error, and a leading space would produce an empty idref.
 */
export function describedByWithStrength(inputId: string, describedby?: string): string {
    return [describedby, passwordStrengthId(inputId)].filter(Boolean).join(' ');
}
