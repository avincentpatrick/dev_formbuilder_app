<?php

declare(strict_types=1);

use App\Enums\BadgeKey;
use App\Enums\NotificationType;
use App\Enums\PlanTier;
use App\Enums\PointRule;
use App\Enums\SubmissionSource;
use App\Enums\SubmissionStatus;
use App\Enums\TenantUserStatus;
use App\Models\Audit;
use App\Models\BadgeAward;
use App\Models\Notification;
use App\Models\PointAward;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\Entitlements\EntitlementService;
use App\Services\Gamification\BackfillTally;
use App\Services\Gamification\GamificationBackfill;
use App\Services\Gamification\PointsRecorder;
use App\Services\Settings\TenantSettingRegistry;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| K1c — replaying a workspace's real history into the award ledger.
|
| WHAT CLASS OF BUG THIS FILE EXISTS TO CATCH, in the order the bugs would actually happen:
|
|  1. THE ANNOUNCEMENT STORM. A replay earns badges FOR REAL, which is wanted. Announcing them is not: a
|     long-standing member would be told about most of the catalog at once for things they did last year.
|     Every badge assertion here is paired with a NOTIFICATION assertion, because the badge rows are
|     identical either way and only the notification count discriminates.
|  2. THE INSTALL-DAY COLLAPSE. Both ledgers date rows from the ACT. A replay that stamped now() would be
|     invisible in every "the award exists" assertion and would destroy the one fact the tables hold.
|  3. THE SECOND AUTHORITY ON A SUBJECT. A replay that keyed on a different subject than the live listener
|     does not collide with it — the subject is part of the idempotency index — so it writes a SECOND row
|     and doubles the score with no error. `member.joined` is the sharp case: the audit row's auditable_id
|     is the MEMBERSHIP uuid where the listener keys on the USER id.
|  4. THE SILENT ZERO. With the module off, award() returns false using the same value it returns for
|     "already awarded" — so a disabled workspace would report a long list of rows it had never scored.
|
| A plan MUST be seeded (assignPlanTier): with no catalog at all EntitlementService::feature() resolves
| null ⇒ false and every case here would pass whatever the backfill did.
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->tenant = inboxTenant();
    $this->owner = User::factory()->create();
    enterTenant($this->tenant->id, $this->owner->id);
    makeActiveMember($this->owner, 'owner');
    assignPlanTier(PlanTier::Free);
});

afterEach(fn () => app(PermissionRegistrar::class)->setPermissionsTeamId(null));

/**
 * Write one audit row exactly as the real writers do, but back-dated.
 *
 * `AuditLogger::record()` takes no `occurredAt` and both seeders forbid adding one, so a back-dated row is
 * built through the model — the idiom `DemoSeeder::seedDemoAudits()` already established.
 *
 * @param  array<string, mixed>|null  $new
 */
function replayAudit(
    string $auditableType,
    string $event,
    string $auditableId,
    ?string $actorId,
    Carbon $at,
    ?array $new = null,
): Audit {
    /** @var Audit $audit */
    $audit = Audit::query()->forceCreate([
        'auditable_type' => $auditableType,
        'auditable_id' => $auditableId,
        'event' => $event,
        'old_values' => null,
        'new_values' => $new,
        'redacted_fields' => null,
        'user_id' => $actorId,
        'acting_as_user_id' => null,
        'is_system_action' => false,
        'ip_address' => null,
        'user_agent' => null,
        'created_at' => $at,
    ]);

    return $audit;
}

/** The badge announcements this tenant holds — the only thing that discriminates a silent replay. */
function earnedBadgeRows(): int
{
    return Notification::query()->where('type', NotificationType::BadgeEarned->value)->count();
}

function runBackfill(): BackfillTally
{
    return app(GamificationBackfill::class)->replayTenant((string) test()->tenant->id);
}

it('replays a created form and a published version, each dated when it happened', function (): void {
    $created = Carbon::now()->subMonths(7);
    $published = Carbon::now()->subMonths(6);

    replayAudit('form', 'created', (string) Str::uuid7(), (string) $this->owner->id, $created, ['title' => 'Intake']);
    $versionId = (string) Str::uuid7();
    replayAudit('form_version', 'published', $versionId, (string) $this->owner->id, $published, ['status' => 'published']);

    $tally = runBackfill();

    $publish = PointAward::query()->where('rule', PointRule::FormPublished->value)->firstOrFail();

    // THREE, not two: the owner's own membership is backfilled too, from `tenant_users`. Spelled out
    // rather than filtered away, because the membership half is exactly the part `audits` cannot supply.
    expect($tally->created)->toBe(3)
        ->and($publish->points)->toBe(25)
        ->and($publish->subject_type)->toBe('form_version')
        ->and($publish->subject_id)->toBe($versionId)
        // ⚠️ THE INVARIANT THE WHOLE FEATURE RESTS ON. A replay stamping now() satisfies every assertion
        // above and destroys the only fact these tables exist to hold.
        ->and($publish->awarded_at->isSameDay($published))->toBeTrue()
        ->and($publish->created_at?->isToday())->toBeTrue();
});

it('credits a collected submission to the respondent and a guest response to nobody', function (): void {
    $form = publishedInboxForm($this->tenant, $this->owner);
    $mine = seedInboxSubmission($form, $this->owner, SubmissionStatus::Submitted, ['full_name' => 'Ada']);
    $theirs = seedInboxSubmission($form, null, SubmissionStatus::Submitted, ['full_name' => 'Bea'], SubmissionSource::Guest);

    // ⚠️ THE ACTOR ON BOTH ROWS IS THE OWNER, WHICH IS WHAT MAKES THIS DISCRIMINATE. Reading the audit's
    // own actor would credit the owner for the guest response too — ADR-0020 §D8 inverted, with the ladder
    // silently turned into a popularity contest.
    replayAudit('submission', 'created', (string) $mine->getKey(), (string) $this->owner->id, Carbon::now()->subMonth());
    replayAudit('submission', 'created', (string) $theirs->getKey(), (string) $this->owner->id, Carbon::now()->subMonth());

    $tally = runBackfill();

    $collected = PointAward::query()->where('rule', PointRule::SubmissionCollected->value)->get();

    expect($collected)->toHaveCount(1)
        ->and($collected->first()->subject_id)->toBe((string) $mine->getKey())
        ->and($tally->uncredited)->toBe(1);
});

it('tells a review apart from an edit, and prices them differently', function (): void {
    $form = publishedInboxForm($this->tenant, $this->owner);
    $one = seedInboxSubmission($form, $this->owner, SubmissionStatus::Submitted, ['full_name' => 'Ada']);
    $two = seedInboxSubmission($form, $this->owner, SubmissionStatus::Submitted, ['full_name' => 'Bea']);

    // Verbatim payload shapes: SubmissionReviewService::snapshot() vs statusSnapshot() + answerDiff().
    replayAudit('submission', 'updated', (string) $one->getKey(), (string) $this->owner->id, Carbon::now()->subWeek(), [
        'status' => 'approved', 'validated_by' => (string) $this->owner->id, 'validated_at' => 'x',
        'finalized_at' => 'y', 'returned_reason' => null, 'remarks' => '[REDACTED]',
    ]);
    replayAudit('submission', 'updated', (string) $two->getKey(), (string) $this->owner->id, Carbon::now()->subDays(3), [
        'status' => 'submitted', 'validated_by' => null, 'validated_at' => null,
        'finalized_at' => 'y', 'answers.full_name' => 'Beatrix',
    ]);

    runBackfill();

    $reviewed = PointAward::query()->where('rule', PointRule::SubmissionReviewed->value)->firstOrFail();
    $edited = PointAward::query()->where('rule', PointRule::SubmissionEdited->value)->firstOrFail();

    expect($reviewed->points)->toBe(3)
        ->and($reviewed->subject_id)->toBe((string) $one->getKey())
        ->and($edited->points)->toBe(1)
        ->and($edited->subject_id)->toBe((string) $two->getKey());
});

it('counts a submission update that carries neither marker rather than guessing at it', function (): void {
    $form = publishedInboxForm($this->tenant, $this->owner);
    $one = seedInboxSubmission($form, $this->owner, SubmissionStatus::Submitted, ['full_name' => 'Ada']);

    replayAudit('submission', 'updated', (string) $one->getKey(), (string) $this->owner->id, Carbon::now()->subWeek(), [
        'status' => 'submitted', 'validated_by' => null, 'validated_at' => null, 'finalized_at' => 'y',
    ]);

    $tally = runBackfill();

    expect($tally->unmapped)->toBe(1)
        ->and(PointAward::query()->whereIn('rule', [
            PointRule::SubmissionReviewed->value, PointRule::SubmissionEdited->value,
        ])->count())->toBe(0);
});

it('earns badges for real and announces not one of them', function (): void {
    // ⚠️ THE ASSERTION PAIR IS THE POINT. Badge ROWS are identical whether or not the replay suppressed the
    // announcement, so a `toHaveCount(1)` on badge_awards would pass against a replay that had just sent a
    // member several hundred notifications. Only the notification count discriminates.
    $form = publishedInboxForm($this->tenant, $this->owner);
    $before = earnedBadgeRows();

    foreach (range(1, 3) as $i) {
        $submission = seedInboxSubmission($form, $this->owner, SubmissionStatus::Submitted, ['full_name' => "P{$i}"]);
        replayAudit('submission', 'created', (string) $submission->getKey(), (string) $this->owner->id, Carbon::now()->subMonths(4)->addDays($i));
    }

    runBackfill();

    $badge = BadgeAward::query()->where('badge', BadgeKey::FirstResponse->value)->firstOrFail();

    expect($badge)->not->toBeNull()
        ->and(earnedBadgeRows())->toBe($before)
        // The badge is dated the act that crossed the threshold — the FIRST of the three, four months ago.
        ->and($badge->awarded_at->isSameDay(Carbon::now()->subMonths(4)->addDay()))->toBeTrue();
});

it('awards the membership rules from tenant_users, which is where the evidence actually is', function (): void {
    // ⚠️ NOT FROM `audits`: invite() writes no audit row at all and accept() writes neither a row nor an
    // event, so a replay that read only the ledger would miss both rules for most workspaces.
    $joined = Carbon::now()->subYear();
    TenantUser::query()->where('user_id', $this->owner->id)->update(['joined_at' => $joined]);

    $tally = runBackfill();

    $award = PointAward::query()->where('rule', PointRule::MemberJoined->value)->firstOrFail();

    expect($award->subject_type)->toBe('member')
        // ⚠️ THE USER ID, NOT THE MEMBERSHIP UUID. The audit row for a self-serve join carries the
        // membership's own key, so a replay routed through `audits` would key on a different subject — and
        // the unique index would NOT catch it, because the subject is part of the key. A doubled score.
        ->and($award->subject_id)->toBe((string) $this->owner->id)
        ->and($award->awarded_at->isSameDay($joined))->toBeTrue()
        ->and($tally->created)->toBe(1);
});

it('keys a backfilled invitation on the same digest the live listener would', function (): void {
    $invitee = User::factory()->create(['email' => 'Sam@Example.test']);
    makeActiveMember($invitee, 'viewer');
    TenantUser::query()->where('user_id', $invitee->id)->update([
        'invited_by' => $this->owner->id,
        'invited_at' => Carbon::now()->subMonths(3),
    ]);

    runBackfill();

    $award = PointAward::query()->where('rule', PointRule::MemberInvited->value)->firstOrFail();

    // ⚠️ Pinned against `emailSubject()` deliberately — it is the ONE place the two paths must agree, and
    // the digest is the idempotency key, so a second implementation would let one invitation score twice.
    // Normalization is part of the agreement: the address here is mixed case.
    expect($award->subject_type)->toBe('invite')
        ->and($award->subject_id)->toBe(PointsRecorder::emailSubject((string) $this->tenant->id, 'sam@example.test'))
        ->and($award->points)->toBe(15);
});

it('cannot credit an invitation whose invitee it is not allowed to read, and says so', function (): void {
    // An outstanding invitation: `users` admits a row only if it is you or an ACTIVE co-member, and a
    // replay runs with no current_user_id — so the address needed for the digest is invisible from in here.
    // Reported as uncredited rather than dropped by an inner join, which would have made the count short.
    $pending = User::factory()->create();
    TenantUser::create([
        'user_id' => $pending->id,
        'status' => TenantUserStatus::Invited,
        'invited_by' => $this->owner->id,
        'invited_at' => Carbon::now()->subMonth(),
        'invited_role_id' => catalogRole('viewer'),
    ]);

    $tally = runBackfill();

    expect(PointAward::query()->where('rule', PointRule::MemberInvited->value)->count())->toBe(0)
        ->and($tally->uncredited)->toBe(1);
});

it('creates nothing on a second run, and reports every row as already held', function (): void {
    $form = publishedInboxForm($this->tenant, $this->owner);
    $submission = seedInboxSubmission($form, $this->owner, SubmissionStatus::Submitted, ['full_name' => 'Ada']);
    replayAudit('submission', 'created', (string) $submission->getKey(), (string) $this->owner->id, Carbon::now()->subMonth());

    $first = runBackfill();
    $rows = PointAward::query()->count();
    $second = runBackfill();

    expect($first->created)->toBeGreaterThan(0)
        ->and($second->created)->toBe(0)
        ->and($second->existing)->toBe($first->scanned)
        ->and(PointAward::query()->count())->toBe($rows);
});

it('accounts for every row it saw in exactly one bucket', function (): void {
    // No form fixture on purpose: `publishedInboxForm()` drives the REAL services, which write their own
    // audit rows, and this case is about the arithmetic rather than about the services.
    replayAudit('form', 'created', (string) Str::uuid7(), (string) $this->owner->id, Carbon::now()->subMonths(2));
    replayAudit('form', 'archived', (string) Str::uuid7(), (string) $this->owner->id, Carbon::now()->subMonths(2));
    // A submission row whose submission no longer exists: the LEFT JOIN yields no respondent, so there is
    // nobody to credit — the same bucket a guest response lands in, reached a different way.
    replayAudit('submission', 'created', (string) Str::uuid7(), (string) $this->owner->id, Carbon::now()->subMonth());

    $tally = runBackfill();

    // `archived` is never fetched — the query narrows on the map's own constants — so `scanned` counts the
    // owner's membership plus the two rows that reached the map, not the three audit rows that exist.
    expect($tally->balances())->toBeTrue()
        ->and($tally->scanned)->toBe(3)
        ->and($tally->created)->toBe(2)
        ->and($tally->uncredited)->toBe(1)
        ->and($tally->unmapped)->toBe(0);
});

it('lands the same awards however small the chunk, and chains its own cursor', function (): void {
    $form = publishedInboxForm($this->tenant, $this->owner);

    foreach (range(1, 5) as $i) {
        $submission = seedInboxSubmission($form, $this->owner, SubmissionStatus::Submitted, ['full_name' => "P{$i}"]);
        replayAudit('submission', 'created', (string) $submission->getKey(), (string) $this->owner->id, Carbon::now()->subDays(10 - $i));
    }

    // One row per chunk: five pages plus the short final page. If the cursor were wrong this either loops
    // forever or stops after the first page — and a chunk size equal to the row count hides both.
    $tally = app(GamificationBackfill::class)->replayTenant((string) $this->tenant->id, 1);

    // The per-rule count rather than the total, because `publishedInboxForm()` drives the real services and
    // so contributes its own audit rows to the same walk — a total would be measuring the fixture.
    expect(PointAward::query()->where('rule', PointRule::SubmissionCollected->value)->count())->toBe(5)
        ->and($tally->balances())->toBeTrue();
});

it('writes nothing at all while the module is switched off', function (): void {
    app(TenantSettingRegistry::class)->put($this->tenant, ['modules.gamification' => false], $this->owner);
    app(TenantSettingRegistry::class)->forget();
    app(EntitlementService::class)->forget($this->tenant->id);

    $form = publishedInboxForm($this->tenant, $this->owner);
    $submission = seedInboxSubmission($form, $this->owner, SubmissionStatus::Submitted, ['full_name' => 'Ada']);
    replayAudit('submission', 'created', (string) $submission->getKey(), (string) $this->owner->id, Carbon::now()->subMonth());

    expect(app(GamificationBackfill::class)->moduleEnabled((string) $this->tenant->id))->toBeFalse()
        ->and(PointAward::query()->count())->toBe(0);
});

it('never replays a neighbouring workspace into this one', function (): void {
    $other = inboxTenant('northwind');
    enterTenant($other->id, $this->owner->id);
    makeActiveMember($this->owner, 'owner');
    assignPlanTier(PlanTier::Free);
    replayAudit('form', 'created', (string) Str::uuid7(), (string) $this->owner->id, Carbon::now()->subMonth());

    enterTenant($this->tenant->id, $this->owner->id);
    $tally = runBackfill();

    // The neighbour's audit row is invisible under RLS AND excluded by the explicit tenant predicate. Both
    // layers agree, which is the only state in which the number can be trusted. One row IS scanned — this
    // workspace's own membership — and asserting that rather than zero is what distinguishes "the walk ran
    // and found nothing of the neighbour's" from "the walk never ran at all".
    expect($tally->scanned)->toBe(1)
        ->and(PointAward::query()->where('rule', PointRule::FormCreated->value)->count())->toBe(0);
});
