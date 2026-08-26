<?php

declare(strict_types=1);

use App\Enums\AuditEvent;
use App\Enums\PointRule;
use App\Enums\SubmissionStatus;
use App\Services\Gamification\AuditReplayMap;
use App\Services\Gamification\ReplayableAudit;

/**
 * The pure `audits` → `PointRule` map (Increment K1c) — the branch this whole increment turns on.
 *
 * Unit, so no container and no database: the review-vs-edit discriminator is a decision about the SHAPE of
 * a payload, and testing it through a fixture would prove only that one fixture happened to be shaped
 * right. Here every key set is written out literally.
 *
 * ⚠️ **WHAT GOING WRONG LOOKS LIKE, AND WHY IT IS WORSE THAN A CRASH.** Every mistake this file guards is
 * SILENT. Mis-reading an edit as a review scores 3 where 1 was earned and nothing raises. Crediting the
 * audit's actor instead of the submission's respondent hands a guest response to whoever finalized it —
 * inverting ADR-0020 §D8 while every "the ladder has rows" assertion stays green. And keying on a subject
 * the live listener does not use writes a SECOND row rather than colliding, because the subject is part of
 * the idempotency index: a doubled score, with no error anywhere.
 */

/** @param  list<string>  $newValueKeys */
function replayRow(
    string $auditableType,
    string $event,
    ?string $actorUserId = 'actor-1',
    ?string $respondentUserId = null,
    array $newValueKeys = [],
    string $auditableId = 'subject-1',
    ?string $newStatus = null,
): ReplayableAudit {
    return new ReplayableAudit(
        auditId: 'audit-1',
        auditableType: $auditableType,
        event: $event,
        auditableId: $auditableId,
        actorUserId: $actorUserId,
        respondentUserId: $respondentUserId,
        newValueKeys: $newValueKeys,
        newStatus: $newStatus,
    );
}

function replayMap(): AuditReplayMap
{
    return new AuditReplayMap;
}

it('maps a created form to the creator, keyed the way the listener keys it', function (): void {
    $candidate = replayMap()->candidate(replayRow('form', 'created', auditableId: 'form-9'));

    // ⚠️ The subject vocabulary is pinned LITERALLY rather than against the listener's own constant, which
    // would compare the code with itself. `AwardPointsForFormCreated` writes ('form', $event->formId).
    expect($candidate?->rule)->toBe(PointRule::FormCreated)
        ->and($candidate?->userId)->toBe('actor-1')
        ->and($candidate?->subjectType)->toBe('form')
        ->and($candidate?->subjectId)->toBe('form-9');
});

it('maps a published version to the publisher, keyed on the VERSION and not the form', function (): void {
    // ⚠️ `AwardPointsForFormPublished` keys on ('form_version', $event->formVersionId), and the audit row's
    // auditable_id IS that version id — so the two agree. Keying on the form would let one form be
    // published twice for two awards.
    $candidate = replayMap()->candidate(replayRow('form_version', 'published', auditableId: 'version-4'));

    expect($candidate?->rule)->toBe(PointRule::FormPublished)
        ->and($candidate?->subjectType)->toBe('form_version')
        ->and($candidate?->subjectId)->toBe('version-4');
});

it('credits a collected submission to the RESPONDENT, never to the audits own actor', function (): void {
    // ⚠️ THE TWO IDS ARE DELIBERATELY DIFFERENT. They hold the same value in production today, because
    // SubmissionFinalizer passes the respondent as the actor — so a map that read the wrong one would pass
    // against every real row and only diverge on a hand-authored fixture or a half-fired nullOnDelete.
    $candidate = replayMap()->candidate(replayRow(
        'submission',
        'created',
        actorUserId: 'someone-else',
        respondentUserId: 'collector-7',
    ));

    expect($candidate?->rule)->toBe(PointRule::SubmissionCollected)
        ->and($candidate?->userId)->toBe('collector-7')
        ->and($candidate?->subjectType)->toBe('submission');
});

it('credits nobody for a guest submission, even with an actor on the row', function (): void {
    // ADR-0020 §D8. Crediting the finalizer is the plausible wrong answer, and it would satisfy any
    // assertion that only checked "an award exists".
    $candidate = replayMap()->candidate(replayRow(
        'submission',
        'created',
        actorUserId: 'staffer-2',
        respondentUserId: null,
    ));

    expect($candidate)->toBeNull();
});

it('reads an APPROVED review payload as a review', function (): void {
    // Verbatim from SubmissionReviewService::snapshot(). `remarks` survives redaction as a KEY — the
    // redactor replaces the value in place and never unsets it — so this marker holds on redacted rows too.
    // ⚠️ M24 ADDED THE STATUS TO THIS CASE, AND ITS ABSENCE WAS THE BUG. The six keys below are emitted
    // identically by all four review verbs, so this fixture used to be simultaneously the correct-behaviour
    // test and a passing test for the defect — it asserted "a review scores" against a payload that is
    // equally a real `archive`. Without a status it now proves nothing about which verb ran, which is why
    // it is named for the verb it actually pins.
    $candidate = replayMap()->candidate(replayRow('submission', 'updated', newValueKeys: [
        'status', 'validated_by', 'validated_at', 'finalized_at', 'returned_reason', 'remarks',
    ], newStatus: 'approved'));

    expect($candidate?->rule)->toBe(PointRule::SubmissionReviewed);
});

it('reads a RETURNED review payload as a review too, because the live engine scores both', function (): void {
    // ⚠️ THE CASE THE REPORTING ROW WOULD HAVE LOST. It is titled "two verbs the live engine never scores",
    // which reads as "score approval only" — and `AwardPointsForSubmissionReturned` awards the SAME
    // PointRule::SubmissionReviewed on the SAME subject as `AwardPointsForSubmissionApproved`. A fix that
    // kept only `approved` would silently stop crediting every returned submission in every backfill.
    $candidate = replayMap()->candidate(replayRow('submission', 'updated', newValueKeys: [
        'status', 'validated_by', 'validated_at', 'finalized_at', 'returned_reason', 'remarks',
    ], newStatus: 'returned'));

    expect($candidate?->rule)->toBe(PointRule::SubmissionReviewed);
});

it('scores nothing for a review verb the live engine does not score', function (string $status): void {
    // ⛔ THE DEFECT M24 FIXES, ASSERTED IN BOTH DIRECTIONS ABOVE AND HERE. `markUnderReview` and `archive`
    // funnel through the same `apply()` and emit the same six keys as `approve` — `snapshot()` is one fixed
    // literal and `applyRemarks()` decides only the VALUE — so the review marker fires for them too and
    // this map used to award 3 points apiece. Neither raises a domain event, so no live listener has ever
    // scored them: a replay that did would invent history the engine itself never wrote.
    //
    // ⚠️ These land in the `unmapped` bucket rather than at a cheaper rule. Scoring a claim as an EDIT to
    // "not lose the row" would be inventing a different act that also never happened.
    $candidate = replayMap()->candidate(replayRow('submission', 'updated', newValueKeys: [
        'status', 'validated_by', 'validated_at', 'finalized_at', 'returned_reason', 'remarks',
    ], newStatus: $status));

    expect($candidate)->toBeNull();
})->with(['under_review', 'archived']);

it('refuses a review payload that carries no status at all rather than guessing at it', function (): void {
    // Reachable on a hand-authored or pre-K1c row whose `new_values` has no `status` key — `->>` yields SQL
    // NULL there. The honest answer is the same one the map gives every other ambiguous row: nothing.
    // ⚠️ This is also the case that makes the fix fail CLOSED. If `newStatus` were ever silently dropped in
    // plumbing — a renamed SELECT alias, a constructor argument lost — every review would stop scoring and
    // this test would say so, where a fail-open default of 'approved' would restore the original defect.
    $candidate = replayMap()->candidate(replayRow('submission', 'updated', newValueKeys: [
        'status', 'validated_by', 'validated_at', 'finalized_at', 'returned_reason', 'remarks',
    ], newStatus: null));

    expect($candidate)->toBeNull();
});

it('refuses a scoring STATUS that arrives without the review marker, which is a shape a real database holds today', function (): void {
    // ⛔ THE CASE THAT STOPS THE NEXT SIMPLIFICATION, AND IT IS NOT HYPOTHETICAL. The two checks below the
    // `answers.` loop read as redundant — "if it has `remarks` and the status scores, score it" invites
    // somebody to drop the marker test and keep the status test, since a status of `approved` looks like
    // proof enough on its own. It is not.
    //
    // `DemoSeeder` and `E2eSeeder` both write `('submission','updated')` rows whose payload is
    // `['status' => 'approved', 'guest_contact_email' => …]` — **status-bearing and marker-less**. That is
    // a third and fourth writer of this tuple, invisible to a grep of `app/` (which is why this class's own
    // docblock and ADR-0020 §D10(a) both say "two services" and both are right about the wrong scope), and
    // it is the ONLY shape of this tuple present in the seeded dev database. It is fully creditable — a
    // real actor, a real submission, no existing award — so the unique index would not refuse it either.
    //
    // Dropping the marker test would therefore not merely relax a guard: the very first
    // `gamification:backfill` on a demo tenant would mint a brand-new false `submission.reviewed` award,
    // plus the `first_review` badge at threshold 1, and ADR-0020 §D4 means neither can ever be removed.
    // A seeded row is evidence of nothing anybody did.
    $candidate = replayMap()->candidate(replayRow('submission', 'updated', newValueKeys: [
        'status', 'guest_contact_email',
    ], newStatus: 'approved'));

    expect($candidate)->toBeNull();
});

it('still reads an edit of an APPROVED submission as an edit, never as a review', function (): void {
    // ⛔ THE TRAP THE ORIGINAL FILE WARNED ABOUT AT THE EDIT CASE ABOVE, NOW LIVE. `statusSnapshot()` emits
    // `status` too, so an edit to an already-approved submission carries exactly the status the review
    // branch scores on. The `answers.` loop runs FIRST and returns FIRST, which is what keeps a 1-point
    // correction from being priced as a 3-point review. Before M24 that ordering settled a payload that
    // could not occur; it is load-bearing now, and this is the case that holds it there.
    $candidate = replayMap()->candidate(replayRow('submission', 'updated', newValueKeys: [
        'status', 'validated_by', 'validated_at', 'finalized_at', 'answers.full_name',
    ], newStatus: 'approved'));

    expect($candidate?->rule)->toBe(PointRule::SubmissionEdited);
});

it('names the scoring statuses exactly, and they are the ones SubmissionStatus still spells that way', function (): void {
    // ⚠️ NOT A CONSTANT ASSERTED AGAINST ITSELF, WHICH IS THE THING THIS FILE REFUSES ELSEWHERE. The left
    // side is a literal pinned to the ledger's historical contents; the right side is the enum's CURRENT
    // vocabulary. They agree today. If somebody renames a case, `audits` keeps the old string forever —
    // both tables are append-only — so this assertion goes red and forces the question "what about the rows
    // already written?" to be answered deliberately rather than by a silent behaviour change in which every
    // historical review quietly stops scoring.
    expect(AuditReplayMap::SCORED_REVIEW_STATUSES)->toBe(['approved', 'returned'])
        ->and(AuditReplayMap::SCORED_REVIEW_STATUSES)->toBe([
            SubmissionStatus::Approved->value,
            SubmissionStatus::Returned->value,
        ]);

    // And the two that must NOT be there — stated positively, because a set is as much what it excludes.
    expect(AuditReplayMap::SCORED_REVIEW_STATUSES)
        ->not->toContain(SubmissionStatus::UnderReview->value)
        ->not->toContain(SubmissionStatus::Archived->value);
});

it('reads a real edit payload as an edit', function (): void {
    // Verbatim from SubmissionAnswerEditService: statusSnapshot()'s four keys plus one flattened
    // `answers.<key>` per CHANGED answer. Note it carries `status` too, so a discriminator that keyed on
    // `status` alone would call this a review.
    $candidate = replayMap()->candidate(replayRow('submission', 'updated', newValueKeys: [
        'status', 'validated_by', 'validated_at', 'finalized_at', 'answers.full_name', 'answers.age',
    ]));

    expect($candidate?->rule)->toBe(PointRule::SubmissionEdited);
});

it('refuses to guess when a submission update carries neither marker', function (): void {
    // ⚠️ REACHABLE, NOT HYPOTHETICAL: an edit that changed no answers emits statusSnapshot() alone. There
    // is no honest way to read that as a review, so it is skipped and counted rather than mis-scored — and
    // that count is what makes a future THIRD writer of this tuple visible instead of silently wrong.
    $candidate = replayMap()->candidate(replayRow('submission', 'updated', newValueKeys: [
        'status', 'validated_by', 'validated_at', 'finalized_at',
    ]));

    expect($candidate)->toBeNull();
});

it('prefers the edit marker when a payload somehow carries both', function (): void {
    // Cannot occur as built — the two writers' key sets are disjoint on these markers. Pinned so the
    // precedence is a decision rather than an accident of statement order if a writer ever changes.
    $candidate = replayMap()->candidate(replayRow('submission', 'updated', newValueKeys: [
        'remarks', 'answers.full_name',
    ]));

    expect($candidate?->rule)->toBe(PointRule::SubmissionEdited);
});

it('credits nobody when the actor has been deleted out of the ledger', function (): void {
    // `audits.user_id` is nullOnDelete, so a departed member's history stops being creditable. Accepted and
    // unavoidable; what matters is that it produces no candidate rather than a candidate with a null member.
    expect(replayMap()->candidate(replayRow('form', 'created', actorUserId: null)))->toBeNull();
});

it('ignores every audit event that evidences no earnable act', function (string $event): void {
    expect(replayMap()->candidate(replayRow('submission', $event)))->toBeNull()
        ->and(replayMap()->candidate(replayRow('form', $event)))->toBeNull();
})->with([
    AuditEvent::Deleted->value,
    AuditEvent::Restored->value,
    AuditEvent::Archived->value,
    AuditEvent::Exported->value,
    AuditEvent::PermissionChanged->value,
    // ⚠️ These two carry `user_id` = the IMPERSONATED TARGET and `acting_as_user_id` = the operator, so a
    // naive replay on `user_id` would credit a member for a platform operator's session.
    AuditEvent::ImpersonationStarted->value,
    AuditEvent::ImpersonationEnded->value,
]);

it('ignores an update to anything that is not a submission', function (string $type): void {
    // Sixteen services write AuditEvent::Updated. Only one auditable_type among them scores.
    expect(replayMap()->candidate(replayRow($type, 'updated', newValueKeys: ['remarks'])))->toBeNull();
})->with(['form', 'tenant', 'settings', 'subscription', 'connection', 'webhook_endpoint', 'domain', 'sso_connection', 'users', 'feedback_reports']);

it('ignores the membership row, because the membership rules do not come from audits', function (): void {
    // ⚠️ NOT AN OMISSION — see AuditReplayMap's docblock and ADR-0020 §D10. `invite()` writes no audit row
    // at all and `accept()` writes none either, so `tenant_users` is the authority for both member rules.
    // Reading this row here as well would put a second authority on `member.joined` — and it would key on
    // the MEMBERSHIP uuid, where the listener keys on the USER id, so the unique index would not catch it.
    expect(replayMap()->candidate(replayRow('tenant_users', 'created', newValueKeys: ['user_id', 'via'])))
        ->toBeNull();
});

it('narrows its query on exactly the tuples it can score, and on no others', function (): void {
    // The enumerator's WHERE clause is built from this constant, so BOTH directions matter. A tuple that
    // maps to a rule but is missing here is never FETCHED — a whole rule silently absent from every
    // backfill, with every unit test above still green. And a tuple that is listed but scores nothing is
    // fetched, mapped to null, and counted as `unmapped` — the bucket the operator report describes as
    // "audits has grown a writer nobody told this map about", which would then be noise.
    expect(AuditReplayMap::SCORED_PAIRS)->toBe([
        ['form', 'created'],
        ['form_version', 'published'],
        ['submission', 'created'],
        ['submission', 'updated'],
    ]);

    foreach (AuditReplayMap::SCORED_PAIRS as [$type, $event]) {
        // ⚠️ EVERY listed tuple must be able to score. `('submission','updated')` is the one that needs a
        // payload to do so, which is why it is probed with an edit marker rather than bare.
        $keys = $type === 'submission' && $event === 'updated' ? ['answers.x'] : [];

        expect(replayMap()->rule(replayRow($type, $event, newValueKeys: $keys)))->not->toBeNull();
    }

    // The tuple that made this test worth writing: `FormService` writes it from three methods and it scores
    // nothing, so a cross-product filter would have dragged every ordinary form edit into `unmapped`.
    expect(replayMap()->rule(replayRow('form', 'updated')))->toBeNull()
        ->and(collect(AuditReplayMap::SCORED_PAIRS)->contains(['form', 'updated']))->toBeFalse();
});

it('states its two markers literally, because they mirror another services private methods', function (): void {
    // Changing either of these is changing an agreement with a class that cannot enforce it, so the value
    // is pinned rather than derived — the K1a rule about never asserting a constant against itself.
    expect(AuditReplayMap::EDIT_MARKER_PREFIX)->toBe('answers.')
        ->and(AuditReplayMap::REVIEW_MARKER)->toBe('remarks');
});
