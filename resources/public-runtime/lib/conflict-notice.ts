/**
 * Increment M14 — the one place that turns a `409` envelope code into the words a respondent reads.
 *
 * ⚠️ IT EXISTS BECAUSE THE TWO SURFACES HAD ALREADY DRIFTED APART IN PRODUCTION. `App.vue`'s `resolveNotice`
 * branched on `conflict_code` and told a content conflict "This response conflicts with a copy already
 * saved", while `outbox-status.ts` rendered the SAME row as "the form changed after this was saved" — a
 * sentence about a different event entirely. Two copies of one decision, in two files, disagreeing about one
 * row. Rather than fix the second copy, M14 deletes the duplication: both surfaces read this module, so the
 * next cause added here reaches the banner and the list together or reaches neither.
 *
 * The two fields are NOT interchangeable phrasings of one sentence. `resolveNotice` sits above a re-mounted
 * fill session the respondent is about to act in; `listDetail` sits under a badge in a cross-form list of
 * rows they may never open. They differ in length and in what they can assume the reader is looking at.
 *
 * ⛔ THE `form_updated` FALLBACK STRING IS FROZEN AND MUST NOT BE REWORDED.
 * `tests/e2e/public-runtime-offline.spec.ts:247` asserts `/this form was updated/i` against it, that suite
 * cannot run on the development host, and it is merge-blocking. Adding a cause here is safe; editing the
 * default is discovered at merge.
 */

export interface ConflictCopy {
    /** The banner above the re-mounted session while the respondent resolves a PARKED row (`App.vue`). */
    resolveNotice: string;
    /** The sentence under the badge in "My submissions on this device" (`outbox-status.ts`). */
    listDetail: string;
}

/**
 * Increment G8c's original resolve-mode banner, kept verbatim as the default arm.
 *
 * It is the right sentence for `form_updated` / `submission_version_superseded` and the wrong one for every
 * other cause — which is the defect M14 closes. It stays the DEFAULT deliberately: a code this build has
 * never heard of gets the recovery that is safe whatever the cause was, and says the one thing that is
 * always true of a parked row, namely that the answers were kept and want reviewing.
 */
const DRIFT_RESOLVE_NOTICE =
    'This form was updated after this response was saved. Your answers were kept where possible — please review and resubmit.';

const COPY: Record<string, ConflictCopy> = {
    // `form_updated` — a genuine republish. The pre-M14 behaviour, unchanged, and the only cause for which
    // the sentence above was ever true.
    form_updated: {
        resolveNotice: DRIFT_RESOLVE_NOTICE,
        listDetail: 'We found a conflict — the form changed after this was saved.',
    },
    submission_version_superseded: {
        resolveNotice: DRIFT_RESOLVE_NOTICE,
        listDetail: 'We found a conflict — the form changed after this was saved.',
    },

    // `submission_conflict` — Increment G8c's content conflict: the same submission id was already stored
    // with materially different answers. Nothing was republished, so the drift sentence is false here.
    submission_conflict: {
        resolveNotice: 'This response conflicts with a copy already saved. Please review your answers and submit again.',
        listDetail: 'We found a conflict — a copy of this response was already saved.',
    },

    // `draft_conflict` — Increment P3a's lost-update guard (and, since M12, the promote door too). Another
    // DEVICE wrote this draft, which is the cause respondents were most often mis-told about: they were shown
    // a form-was-updated banner for something their own phone had done. The remedy the server names is to
    // re-read, so the copy names re-reading rather than republishing.
    draft_conflict: {
        resolveNotice:
            'This response was also filled in on another device, and that copy is newer. Please review your answers and submit again.',
        listDetail: 'We found a conflict — this response was also filled in on another device.',
    },

    // `submission_uuid_claimed` — Increment M11's cause, given its own code by M14. It is about ENTITLEMENT
    // rather than content or timing, and the remedy is a fresh identifier, which the re-mount already mints.
    // The respondent is not told about identifiers: what is true FOR THEM is that this one could not be
    // matched up and the answers in front of them still need sending.
    submission_uuid_claimed: {
        resolveNotice:
            'This response could not be matched to the one saved on this device. Please review your answers and submit again.',
        listDetail: 'We found a conflict — this response could not be matched to the one saved here.',
    },
};

/**
 * The copy for one parked row, keyed by the `409` envelope code `markConflict()` stored with it.
 *
 * `null` is a real input rather than a defensive one: a row parked by a build older than G8c has no
 * `conflict_code` at all (`outbox.ts` defaults it to null), and so does a 409 that arrived with no body.
 */
export function conflictCopy(code: string | null): ConflictCopy {
    return (code !== null ? COPY[code] : undefined) ?? COPY.form_updated;
}
