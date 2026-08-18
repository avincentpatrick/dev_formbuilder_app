<?php

declare(strict_types=1);

use App\Enums\NotificationType;
use Database\Seeders\RolePermissionSeeder;

/**
 * The `NotificationType` catalog facts (Increment I3). Unit, so no container and no database — everything
 * asserted here is meant to be answerable without either, which is the test that the enum has not quietly
 * grown a dependency.
 */
it('pins the case order, because the DB CHECKs are generated from it', function (): void {
    // Both `notifications.type` and `notification_preferences.notification_type` get a CHECK built from
    // values() at migration time. A reorder or a dropped case silently changes those constraints on the
    // next migrate:fresh, and nothing else in the suite would notice.
    expect(NotificationType::values())->toBe([
        'submission_received',
        'submission_returned',
        'submission_approved',
        'review_requested',
        'export_ready',
        'member_invited',
        'webhook_failed',
        // I11b — APPENDED, never inserted. The two CHECK-recreate migrations that widen the constraints
        // are generated from this list, so a case slotted in beside a thematic sibling would rewrite the
        // vocabulary of every case after it.
        'impersonation_started',
        // J3a — appended for the same reason, and NOT beside `member_invited` where it thematically belongs.
        'member_joined',
        // K1b — the third append in a row. This one had no thematic sibling to be tempted by, which makes
        // it the easy case; the rule is the same either way.
        'badge_earned',
    ]);
});

it('gives every case a label and an action label', function (NotificationType $type): void {
    expect($type->label())->toBeString()->not->toBe('')
        ->and($type->actionLabel())->toBeString()->not->toBe('');
})->with(NotificationType::cases());

it('defaults every type to the bell', function (NotificationType $type): void {
    expect($type->defaultInApp())->toBeTrue();
})->with(NotificationType::cases());

it('defaults email OFF for exactly two types, each for its own stated reason', function (): void {
    // Phrased as an EXACT LIST rather than as "everything except X", which is what it used to be, so that a
    // third departure has to be argued rather than slipped in — the `honorsEmailPreference()` idiom below.
    //
    // `submission_received` is the PRD's own rule: actionable events default to both channels, "a submission
    // on a busy public form" defaults to in-app only "so a lead-gen owner isn't emailed per response unless
    // they opt in". `badge_earned` (K1b) is a DIFFERENT argument and not the same one again: you cannot earn
    // a badge without being in the app at that moment, so the email is structurally redundant rather than
    // merely high-volume.
    $quiet = array_values(array_filter(
        NotificationType::cases(),
        static fn (NotificationType $t): bool => ! $t->defaultEmail(),
    ));

    expect($quiet)->toBe([NotificationType::SubmissionReceived, NotificationType::BadgeEarned]);

    // Both are defaults, not locks: the recipient can still switch the email on.
    expect(NotificationType::BadgeEarned->honorsEmailPreference())->toBeTrue();
});

it('honours the email preference for every type except the two the emitting site owns', function (): void {
    $siteOwned = array_values(array_filter(
        NotificationType::cases(),
        static fn (NotificationType $t): bool => ! $t->honorsEmailPreference(),
    ));

    // Exactly these two, and for a stated reason: their branded emails predate this table and are sent by
    // GeneratePdfJob / DeliverWebhookJob. If a third appears, it is either a mistake or a decision that
    // needs writing down.
    expect($siteOwned)->toBe([NotificationType::ExportReady, NotificationType::WebhookFailed]);
});

it('narrows exactly the two submission fan-outs by per-form visibility', function (): void {
    $scoped = array_values(array_filter(
        NotificationType::cases(),
        static fn (NotificationType $t): bool => $t->isFormScoped(),
    ));

    expect($scoped)->toBe([NotificationType::SubmissionReceived, NotificationType::ReviewRequested]);
});

it('names only real roles, and only for the types that address a role', function (): void {
    foreach (NotificationType::cases() as $type) {
        foreach ($type->recipientRoles() as $role) {
            expect(RolePermissionSeeder::ROLES)->toContain($role);
        }
    }

    // I11b — Owner only, narrower than member_invited's owner+admin. Pinned because widening it later is
    // additive but narrowing it after Admins have come to expect the notice is not.
    expect(NotificationType::ImpersonationStarted->recipientRoles())->toBe(['owner']);

    // J3a — owner+admin, matching `member_invited` rather than `impersonation_started`. The two member
    // events are the two doors into a workspace and answer the same governance question, so an Admin told
    // that a seat was OFFERED and not that one was TAKEN has half a ledger. Pinned so the pair cannot drift
    // apart on a scoping decision that was made about them together.
    expect(NotificationType::MemberJoined->recipientRoles())
        ->toBe(NotificationType::MemberInvited->recipientRoles())
        ->toBe(['owner', 'admin']);

    // The five types whose recipient is a specific person the emitting site already knows. K1b's
    // `badge_earned` is the strongest member of the set: the other four are addressed because of a
    // relationship to a RECORD, while an achievement is about the recipient themselves, so no role set
    // could express it even in principle.
    foreach ([
        NotificationType::SubmissionReturned,
        NotificationType::SubmissionApproved,
        NotificationType::ExportReady,
        NotificationType::WebhookFailed,
        NotificationType::BadgeEarned,
    ] as $addressed) {
        expect($addressed->recipientRoles())->toBe([]);
    }
});

it('only names roles that can actually see a submission', function (): void {
    // NotificationRecipientResolver drops `submissions.view` from SubmissionPolicy::view()'s conjunction,
    // on the grounds that every role holds it. If a future role loses it, the resolver would over-notify
    // and this is the only place that would say so.
    foreach ([NotificationType::SubmissionReceived, NotificationType::ReviewRequested] as $type) {
        foreach ($type->recipientRoles() as $role) {
            expect(RolePermissionSeeder::MATRIX[$role])->toContain('submissions.view');
        }
    }
});

it('points each type at one destination, shared by the bell and the email', function (): void {
    $submission = ['submission_id' => 'sub-1', 'form_id' => 'form-1'];

    expect(NotificationType::SubmissionReceived->pathFor($submission))->toBe('submissions/sub-1')
        ->and(NotificationType::ReviewRequested->pathFor($submission))->toBe('submissions/sub-1')
        ->and(NotificationType::SubmissionApproved->pathFor($submission))->toBe('submissions/sub-1')
        ->and(NotificationType::SubmissionReturned->pathFor($submission))->toBe('submissions/sub-1')
        ->and(NotificationType::ExportReady->pathFor($submission))->toBe('submissions/sub-1')
        ->and(NotificationType::MemberInvited->pathFor([]))->toBe('members')
        ->and(NotificationType::WebhookFailed->pathFor(['webhook_endpoint_id' => 'wh-1']))->toBe('webhooks/wh-1')
        // I11b — unconditional, and asserted with an EMPTY payload on purpose: this is the only type whose
        // destination does not depend on an id, so a future "return null when the payload is thin" tidy-up
        // would silently break the one link §9 requires to work.
        ->and(NotificationType::ImpersonationStarted->pathFor([]))->toBe('audit-log')
        // J3a — the same destination `member_invited` points at, and likewise unconditional: the members
        // page is where a new arrival is acted on. Asserted with an EMPTY payload for the reason above.
        ->and(NotificationType::MemberJoined->pathFor([]))->toBe('members');
});

it('returns no path rather than a broken one when the payload lacks its identifier', function (): void {
    expect(NotificationType::SubmissionApproved->pathFor([]))->toBeNull()
        ->and(NotificationType::WebhookFailed->pathFor([]))->toBeNull()
        // A non-string id (a malformed row, a hand-edited payload) is treated as absent, not cast.
        ->and(NotificationType::SubmissionReceived->pathFor(['submission_id' => 42]))->toBeNull();
});

it('points a badge at the achievements surface, unconditionally', function (): void {
    // ⚠️ THIS TEST USED TO PIN THE OPPOSITE, AND THE FLIP IS THE POINT. K1b left `pathFor()` returning null
    // for this case and wrote "K1e replaces this arm" into both the enum and this test, precisely so that
    // re-pointing it would be a deliberate edit against a failing test rather than a silent change. K1e then
    // shipped `/achievements` and never came back, so the arm survived the increment that was supposed to
    // discharge it — every badge-earned bell row unclickable, every badge email without its action button —
    // until the final integration review found it. M1 makes the edit K1e owed.
    //
    // UNCONDITIONAL, like `members`: the destination is a surface, not a row, so there is no payload id that
    // could be missing. Asserted with a full payload AND an empty one so both readings are pinned.
    expect(NotificationType::BadgeEarned->pathFor(['badge' => 'collector']))->toBe('achievements')
        ->and(NotificationType::BadgeEarned->pathFor([]))->toBe('achievements');

    // The action label was written to survive being given a destination; now that it has one, it must not be
    // empty — the email renders a button around these words.
    expect(NotificationType::BadgeEarned->actionLabel())->not->toBe('');
});
