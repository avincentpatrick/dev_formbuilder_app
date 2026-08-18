<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Submission review lifecycle (data-dictionary §7). `draft` = started but not yet finalized (the
 * Phase-1 in-session save path and the resting state of a new row); `submitted` = the respondent
 * finalized it and it entered the review queue; `screened_out` = they finalized it having been shown no
 * questions to answer, so it consumes no `max_responses` slot; `under_review`/`approved`/`returned` are
 * the reviewer transitions (renaming legacy's "Pending Validation" to `under_review`); `archived` is a
 * terminal retention state. Every channel funnels into one `SubmissionPipeline`, so this enum is
 * source-agnostic.
 */
/*
 * ⚠️ THE REST OF THIS FILE'S REASONING IS DELIBERATELY IN A PLAIN BLOCK COMMENT, NOT A DOCBLOCK.
 * Scramble publishes the `/** *​/` above verbatim as the `SubmissionStatus` schema `description` in
 * `openapi.json`, which is a customer-facing contract under a byte-identity CI gate. Internal argument,
 * `{@see}` tags and box-drawing rules all end up in a tenant's API reference — and each edit to them moves
 * the spec file. Keep the docblock to the one paragraph a reader of the API needs; keep the rest here.
 *
 * ── `screened_out` MEANS "SHOWN NO QUESTIONS", NOT "DISQUALIFIED" (I9a) ──────────────────────────────
 * It is derived server-side by App\Support\Submissions\FinalizedStatus, from
 * App\Support\Forms\StepProjection::isEmpty() over the answers being finalized — byte-for-byte the question
 * the guest runtime already asks to decide it is rendering H21b's terminal panel
 * (`visibleSteps.length === 0`). So the state means exactly what the respondent SAW, and nothing else.
 *
 * That is NARROWER than the word "screened out" suggests, and the difference is worth stating because
 * it is not what a reader assumes. An ordinary screener — `age` in the lead block, later sections gated
 * on `${age} >= 18` — keeps its own step visible whatever the answer, so a respondent who answers 15
 * is `submitted`, not `screened_out`. The empty projection is reached by the H7 URL-router form arrived
 * at with no parameter, by a section that hides itself, and by a form with no renderable fields.
 *
 * WHY NOT the narrower "the answers CAUSED the emptiness" (`isEmpty(now) && ! isEmpty(at-open)`): it
 * inverts the flagship case. An H7 router form is empty-at-open BY CONSTRUCTION — which is precisely why
 * `StepGraphInspector::emptyAtOpen()` suppresses its publish-time notice for `PrefillSource::Url` — so
 * the no-parameter arrival, the exact respondent doc #27 §4.1 describes, would be classified `submitted`
 * and would burn the capacity slot this state exists to protect.
 *
 * ── IT IS TERMINAL, AND THAT IS A CAPACITY INVARIANT RATHER THAN A WORKFLOW PREFERENCE ───────────────
 * `status` is the sole carrier of "did this consume a paid slot" (App\Models\Submission::scopeConsumesCapacity()).
 * `screened_out` is deliberately absent from all four `$from` lists in `SubmissionReviewService` — including
 * `archive()`, whose target state DOES consume. Widening any of them would silently convert a
 * non-consuming row into a consuming one and retroactively overfill a cap that was already at its limit.
 *
 * ── CASE ORDER IS A WIRE CONTRACT ────────────────────────────────────────────────────────────────────
 * `ScreenedOut` sits beside `Submitted` because it is a FINALIZE outcome, not a review one. The position
 * leaks into every `cases()`-derived catalog (the inbox filter, the analytics filter) and into the
 * `openapi.json` enum array, so it is chosen rather than appended, and moving it later is a spec diff.
 */
enum SubmissionStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case ScreenedOut = 'screened_out';
    case UnderReview = 'under_review';
    case Approved = 'approved';
    case Returned = 'returned';
    case Archived = 'archived';

    /** Human label for the inbox filter, detail header, and export metadata column (single source). */
    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Submitted => 'Submitted',
            self::ScreenedOut => 'Screened out',
            self::UnderReview => 'Under review',
            self::Approved => 'Approved',
            self::Returned => 'Returned',
            self::Archived => 'Archived',
        };
    }
}
