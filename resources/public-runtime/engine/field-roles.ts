/**
 * Which field types render NOTHING to the person filling a form (Increment H7, relocated into the engine by
 * H21a).
 *
 * WHY IT LIVES IN `engine/` RATHER THAN `lib/schema-mapping.ts`, WHERE H7 PUT IT
 * -----------------------------------------------------------------------------
 * H21a gave the semantic validator a second consumer of this set: `min_instances` is now enforced only on a
 * repeatable section the respondent's step list would actually SHOW (Doc #27 §4.3, amendment A4), and that
 * predicate has to hold identically in both engines or the client and the server disagree about whether
 * Submit is blocked — an R3 break with no golden vector that can see it, because every repeat vector in the
 * corpus uses an ordinary `short_text` member.
 *
 * The engine must not import from `lib/` (the engine is the golden-parity core and `lib/` is the SPA's
 * mapping layer, so the dependency runs one way only), and a second copy inside `engine/` would be a THIRD
 * definition of the same set. So the definition moved down here and `lib/schema-mapping.ts` re-exports it.
 *
 * THE SET IS A CONTRACT, NOT A CONVENIENCE
 * ----------------------------------------
 * `tests/Unit/Forms/PdfFieldRoleTest.php` regex-parses the literal below out of this file and asserts it
 * equals `PdfFieldRole::Omitted` in both directions — a hand-copied list keeps passing forever after someone
 * edits the `.ts`, which is the exact drift that test exists to catch. Keep it a single
 * `new Set<string>([...])` literal of single-quoted `[a-z_]+` members; a rename or a shape change (a frozen
 * array, a union type, a computed set) must FAIL that parse loudly rather than yield an empty list.
 *
 * The set deliberately does NOT mirror `EncodeFormPresenter::OMITTED`, and the divergence is the point: H7
 * makes the two channels asymmetric, because a keyer SHOULD see a hidden field (they are staff, and may be
 * the only source of an externally-sourced value on a paper form) while a respondent must never see one.
 *
 * Dropping these from the step model is only SAFE because neither engine can produce an error on a hidden or
 * calculated field (`collectFieldErrors`) — otherwise the error-summary banner could offer a jump link to a
 * row that does not exist. `page_break` carries no answer at all.
 */
const RENDERS_NOTHING = new Set<string>(['hidden', 'calculated', 'page_break']);

/** Whether a field type is rendered to a respondent at all (Increment H7). */
export function rendersNothing(fieldType: string): boolean {
    return RENDERS_NOTHING.has(fieldType);
}
