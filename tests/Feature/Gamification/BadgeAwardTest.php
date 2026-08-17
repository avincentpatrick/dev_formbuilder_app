<?php

declare(strict_types=1);

use App\Enums\BadgeKey;
use App\Enums\NotificationType;
use App\Enums\PlanTier;
use App\Enums\PointRule;
use App\Enums\SubmissionSource;
use App\Enums\SubmissionStatus;
use App\Events\SubmissionCreated;
use App\Models\BadgeAward;
use App\Models\Notification;
use App\Models\PointAward;
use App\Models\User;
use App\Services\Entitlements\EntitlementService;
use App\Services\Gamification\BadgeAwarder;
use App\Services\Gamification\PointsRecorder;
use App\Services\Settings\TenantSettingRegistry;
use App\Services\Tenancy\TenantMembershipService;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| K1b — the badge catalog, its evaluator, and the seam into PointsRecorder.
|
| WHAT CLASS OF BUG THIS FILE EXISTS TO CATCH, in the order the bugs would actually happen:
|
|  1. THE EVALUATION THAT RAN WHEN IT SHOULD NOT HAVE. Badges hang off `award()` returning true, and the
|     unique index absorbs the difference — a badge row count is IDENTICAL whether the evaluator ran once or
|     a thousand times. Only the NOTIFICATION discriminates, which is why the replay case asserts on
|     `notifications` and not on `badge_awards`.
|  2. THE COUNT THAT LOOKED AT THE WRONG LEDGER. The evaluator writes raw SQL and passes tenant_id itself,
|     so a COUNT that dropped `tenant_id`, `user_id` or `rule` passes every single-actor test in this file.
|     Three cases exist for exactly those three predicates.
|  3. `awarded_at` COLLAPSING INTO `created_at`. They are identical in every live-path test, and the whole
|     reason the column exists is K1c's replay of acts months old. Only a back-dated award sees it.
|  4. THE SEAM EATING ITSELF. A badge failure must not turn a genuinely-created point award into `false` —
|     K1c counts that boolean — and must not poison the caller's transaction.
|
| A plan MUST be seeded (assignPlanTier). With no plan catalog at all EntitlementService::feature() resolves
| null ⇒ false, so a test written without one would pass whatever the engine did.
|--------------------------------------------------------------------------
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
    assignPlanTier(PlanTier::Free); // the LOWEST tier — badges must work there (user decision)
});

afterEach(fn () => app(PermissionRegistrar::class)->setPermissionsTeamId(null));

/** Every badge the current tenant holds for one member. */
function badgesOf(User $user): Collection
{
    return BadgeAward::query()->where('user_id', $user->id)->get();
}

/** Award `$rule` `$times` times against fresh subjects, as the recorder really would. */
function awardTimes(User $user, PointRule $rule, int $times, ?Carbon $at = null): void
{
    $recorder = app(PointsRecorder::class);

    for ($i = 0; $i < $times; $i++) {
        $recorder->award($rule, $user->id, 'submission', (string) Str::uuid7(), $at);
    }
}

/** The `badge_earned` rows the current tenant holds, for anybody. */
function badgeNotifications(): Collection
{
    return Notification::query()->where('type', NotificationType::BadgeEarned->value)->get();
}

it('earns a badge through the real publish path and stamps it from the crossing award', function (): void {
    // publishedInboxForm() drives FormService::create() and PublishService::publish() for real, so this
    // exercises the post-commit emission point rather than a hand-fired event.
    publishedInboxForm($this->tenant, $this->owner);

    $badges = badgesOf($this->owner);
    $crossing = PointAward::query()
        ->where('user_id', $this->owner->id)
        ->where('rule', PointRule::FormPublished->value)
        ->firstOrFail();

    // One publish, so `first_publish` (threshold 1) lands and `publisher` (threshold 3) does not — the
    // ladder proved rather than assumed.
    expect($badges->pluck('badge')->all())->toContain(BadgeKey::FirstPublish)
        ->and($badges->pluck('badge')->all())->not->toContain(BadgeKey::Publisher)
        ->and($badges->firstWhere('badge', BadgeKey::FirstPublish)->tenant_id)->toBe($this->tenant->id)
        // ⚠️ THE STAMP COMES FROM THE AWARD, NOT THE CLOCK. Identical here, which is exactly why the
        // back-dated case below exists — this assertion alone cannot tell the two apart.
        ->and($badges->firstWhere('badge', BadgeKey::FirstPublish)->awarded_at->timestamp)
        ->toBe($crossing->awarded_at->timestamp);
});

it('stamps a replayed act with the date it happened, not the date it was replayed', function (): void {
    // ⚠️ THE K1c SHAPE, AND THE ONLY TEST THAT CAN SEE IT. Every other case in this file awards "now", where
    // `awarded_at` and `created_at` are indistinguishable — so a badge stamped `now()` would pass all of
    // them and would date every historical achievement in every workspace to install day, destroying the
    // one fact this ledger exists to hold.
    $sixMonthsAgo = Carbon::now()->subMonths(6);

    awardTimes($this->owner, PointRule::SubmissionCollected, 1, $sixMonthsAgo);

    $badge = badgesOf($this->owner)->firstWhere('badge', BadgeKey::FirstResponse);

    expect($badge)->not->toBeNull()
        ->and($badge->awarded_at->isSameDay($sixMonthsAgo))->toBeTrue()
        // Both halves, or the assertion passes with the two columns collapsed into one.
        ->and($badge->created_at->isToday())->toBeTrue();
});

it('crosses a threshold exactly on the Nth act, and not before or twice after', function (): void {
    // The off-by-one, end to end, and the case that connects the literal thresholds in BadgeCatalogTest to
    // a real stored row. `collector` is 25.
    awardTimes($this->owner, PointRule::SubmissionCollected, 24);
    expect(badgesOf($this->owner)->pluck('badge')->all())->not->toContain(BadgeKey::Collector);

    awardTimes($this->owner, PointRule::SubmissionCollected, 1);
    expect(badgesOf($this->owner)->where('badge', BadgeKey::Collector))->toHaveCount(1);

    awardTimes($this->owner, PointRule::SubmissionCollected, 1);
    expect(badgesOf($this->owner)->where('badge', BadgeKey::Collector))->toHaveCount(1);
});

it('awards a tier that was stepped over rather than skipping it', function (): void {
    // ⚠️ `<=` RATHER THAN `==`, WHICH IS A REPAIR MECHANISM AND NOT A STYLE CHOICE. Seed past the threshold
    // with the factory (bypassing the recorder, so no evaluation ran), then award ONE through the real
    // path: the count is 26 and was never observed at exactly 25. With `==` the badge would be lost forever.
    PointAward::factory()
        ->count(25)
        ->forRule(PointRule::SubmissionCollected)
        ->create(['user_id' => $this->owner->id]);

    awardTimes($this->owner, PointRule::SubmissionCollected, 1);

    expect(badgesOf($this->owner)->pluck('badge')->all())
        ->toContain(BadgeKey::Collector)
        ->toContain(BadgeKey::FirstResponse);
});

it('does not evaluate at all when the award was not genuinely new', function (): void {
    $form = publishedInboxForm($this->tenant, $this->owner);
    $submission = seedInboxSubmission($form, $this->owner, SubmissionStatus::Submitted, ['full_name' => 'Ada']);

    event(SubmissionCreated::for($submission));
    $afterFirst = badgeNotifications()->count();

    event(SubmissionCreated::for($submission));
    event(SubmissionCreated::for($submission));

    // ⚠️ THE ASSERTION IS ON NOTIFICATIONS, NOT ON BADGE ROWS, AND THAT IS THE POINT. The unique index
    // absorbs a re-evaluation completely — `badge_awards` holds one row whether the evaluator ran once or
    // three times — so a badge-count assertion here would be green for both the correct implementation and
    // the one that evaluates on every award. Only the announcement can tell them apart.
    expect(badgeNotifications()->count())->toBe($afterFirst)
        ->and(badgesOf($this->owner)->where('badge', BadgeKey::FirstResponse))->toHaveCount(1);
});

it('counts only this member’s own awards toward a threshold', function (): void {
    $other = User::factory()->create();
    makeActiveMember($other, 'form_editor');

    awardTimes($this->owner, PointRule::SubmissionCollected, 24);
    awardTimes($other, PointRule::SubmissionCollected, 1);

    // A COUNT that dropped `user_id` would see 25 and hand the newcomer the Collector badge on their first
    // response.
    expect(badgesOf($other)->pluck('badge')->all())
        ->toContain(BadgeKey::FirstResponse)
        ->not->toContain(BadgeKey::Collector);
});

it('counts only this rule’s awards toward a threshold', function (): void {
    awardTimes($this->owner, PointRule::SubmissionReviewed, 24);
    awardTimes($this->owner, PointRule::SubmissionCollected, 1);

    // A COUNT that dropped `rule` would total 25 across two vocabularies and award Collector for a single
    // collected response backed by two dozen reviews.
    expect(badgesOf($this->owner)->pluck('badge')->all())->not->toContain(BadgeKey::Collector);
});

it('counts only this workspace’s awards toward a threshold', function (): void {
    // ⚠️ THE CROSS-TENANT CASE, AND NOTHING ELSE IN THIS FILE CAN CATCH IT. The same person is a member of
    // two workspaces; 24 collections in one must not carry into the other. RLS confines the read, but the
    // evaluator also passes `tenant_id` explicitly, and a bug in either would be invisible to every
    // single-tenant test above.
    $second = inboxTenant('globex');

    awardTimes($this->owner, PointRule::SubmissionCollected, 24);

    enterTenant($second->id, $this->owner->id);
    makeActiveMember($this->owner, 'owner');
    assignPlanTier(PlanTier::Free);
    awardTimes($this->owner, PointRule::SubmissionCollected, 1);

    expect(badgesOf($this->owner)->pluck('badge')->all())
        ->toContain(BadgeKey::FirstResponse)
        ->not->toContain(BadgeKey::Collector);
});

it('tells the earner and nobody else', function (): void {
    $other = User::factory()->create();
    makeActiveMember($other, 'form_editor');

    awardTimes($other, PointRule::SubmissionCollected, 1);

    $rows = badgeNotifications();

    // `recipientRoles()` is empty for this type, so a fan-out to owner+admin would mean the emitting site
    // was addressing by role after all — and the owner would be told about somebody else's achievement.
    expect($rows)->toHaveCount(1)
        ->and($rows->first()->user_id)->toBe($other->id)
        ->and($rows->first()->data['badge'])->toBe(BadgeKey::FirstResponse->value);
});

it('awards the welcome badge on a real join and deliberately says nothing about it', function (): void {
    // Driven through the REAL join path rather than a hand-fired event: joinOpenTenant() is the one
    // membership write with no ambient context — it uses applyLocal(), whose SET LOCAL GUC dies at commit —
    // so it is the sharpest case for the evaluator running inside its emitter's transaction.
    $identity = committedTenantIdentity('Newcomer', true, 'newcomer@example.test');

    app(TenantMembershipService::class)->joinOpenTenant($this->tenant, $identity);

    $joiner = User::query()->where('email', 'newcomer@example.test')->firstOrFail();

    expect(badgesOf($joiner)->pluck('badge')->all())->toContain(BadgeKey::Welcome)
        // ⚠️ AWARDED, NOT ANNOUNCED. The badge lands in the same request that creates the membership, so a
        // bell row would tell a person who just joined that they have joined — in the noisiest minute of a
        // new member's life. `BadgeKey::announces()` is the branch, and this is the end-to-end proof that
        // the evaluator honours it rather than the enum merely declaring it.
        ->and(badgeNotifications()->where('user_id', $joiner->id))->toHaveCount(0);
});

it('suppresses the announcement when the caller asks it to, without suppressing the badge', function (): void {
    // The K1c replay shape: history is earned, nobody is emailed about things they did last year.
    app(PointsRecorder::class)->award(
        PointRule::SubmissionCollected,
        $this->owner->id,
        'submission',
        (string) Str::uuid7(),
        null,
        announceBadges: false,
    );

    expect(badgesOf($this->owner)->pluck('badge')->all())->toContain(BadgeKey::FirstResponse)
        ->and(badgeNotifications())->toHaveCount(0);
});

it('announces a badge once, however many further awards of that rule arrive', function (): void {
    // ⚠️ FOUND BY THE MUTATION PASS, NOT BY REASONING — AND IT IS THE K1a SURVIVOR'S EXACT SHAPE.
    // Swapping the awarder's `$affected === 1` for `$affected >= 0` survived every other test in this file.
    // The replay case above cannot see it: on a replay `award()` returns false and the evaluator is never
    // reached at all. The mutation only bites on a SECOND GENUINELY-NEW award of a rule the member already
    // holds a badge for — where the INSERT legitimately affects zero rows and must be read as "already
    // earned" rather than as "earned again".
    //
    // And the badge-row count is USELESS here, exactly as it was in K1a: the unique index holds it at one
    // either way. Only the notification count discriminates.
    awardTimes($this->owner, PointRule::SubmissionCollected, 5);

    expect(badgeNotifications())->toHaveCount(1)
        ->and(badgesOf($this->owner)->where('badge', BadgeKey::FirstResponse))->toHaveCount(1);
});

it('announces by default, so a replay has to opt out rather than opt in', function (): void {
    // The other half of the argument above: if the default ever flipped, K1c would look correct and every
    // ordinary act would go silent.
    awardTimes($this->owner, PointRule::SubmissionCollected, 1);

    expect(badgeNotifications())->toHaveCount(1);
});

it('credits nobody with a badge for a guest submission', function (): void {
    $form = publishedInboxForm($this->tenant, $this->owner);
    $submission = seedInboxSubmission(
        $form,
        $this->owner,
        SubmissionStatus::Submitted,
        ['full_name' => 'Ada'],
        SubmissionSource::Guest,
    );

    event(SubmissionCreated::for($submission));

    // §D8's half restated one layer up: not "the owner has no badge" but nobody does. The whole table.
    expect(BadgeAward::query()->where('badge', BadgeKey::FirstResponse->value)->count())->toBe(0);
});

it('awards no badge at all while the module is switched off', function (): void {
    app(TenantSettingRegistry::class)->put($this->tenant, ['modules.gamification' => false], $this->owner);
    app(TenantSettingRegistry::class)->forget();
    app(EntitlementService::class)->forget($this->tenant->id);

    awardTimes($this->owner, PointRule::SubmissionCollected, 30);

    // The whole table, not one badge — a gate tested only through the badge it happened to cover is the
    // shape that leaves the rest of the catalog writing.
    expect(BadgeAward::query()->count())->toBe(0);
});

it('keeps badges on for a FREE tenant, because no tier gates them', function (): void {
    expect(app(EntitlementService::class)->feature(PointsRecorder::FEATURE))->toBeTrue();

    awardTimes($this->owner, PointRule::SubmissionCollected, 1);

    expect(badgesOf($this->owner))->toHaveCount(1);
});

it('survives a badge failure with the caller’s transaction AND the award’s answer both intact', function (): void {
    // ⚠️ THIS ONE TEST KILLS TWO MUTATIONS THAT SURVIVE EVERYTHING ELSE IN THE SUITE, AND BOTH ARE ABOUT
    // WHAT HAPPENS WHEN A BADGE FAILS RATHER THAN WHEN IT SUCCEEDS.
    //
    //  1. DELETE THE SAVEPOINT. A `BadgeKey` case added without widening `badge_awards_badge_check` raises
    //     23514, and in PostgreSQL a constraint violation aborts the WHOLE transaction — so a bare catch
    //     would swallow the error and hand the caller a transaction that is already dead. Not exotic:
    //     `FormService::create()` runs inside `TemplateService::instantiate()`, so a one-line enum edit
    //     would take out template instantiation over a scoreboard row.
    //  2. MOVE THE EVALUATOR INSIDE THE RECORDER'S `try`. Then this failure returns `false` for a point
    //     award that genuinely landed — and K1c's backfill COUNTS that boolean, so a correct ledger would
    //     report itself as incomplete.
    //
    // The hostile CHECK is how the failure is staged, because it is the one failure a test can force
    // deterministically. DDL is transactional in PostgreSQL, so the narrowing rolls back with
    // RefreshDatabase.
    DB::statement('ALTER TABLE badge_awards DROP CONSTRAINT badge_awards_badge_check');
    DB::statement("ALTER TABLE badge_awards ADD CONSTRAINT badge_awards_badge_check CHECK (badge = 'nothing')");

    $result = DB::transaction(function (): array {
        $created = app(PointsRecorder::class)->award(
            PointRule::SubmissionCollected,
            $this->owner->id,
            'submission',
            (string) Str::uuid7(),
        );

        // The statement a poisoned transaction cannot execute. It IS the assertion.
        return ['created' => $created, 'alive' => DB::select('SELECT 1 AS ok')[0]->ok === 1];
    });

    expect($result['alive'])->toBeTrue()
        ->and($result['created'])->toBeTrue()
        // The point award still landed; only the badge was refused.
        ->and(PointAward::query()->where('user_id', $this->owner->id)->count())->toBe(1)
        ->and(BadgeAward::query()->count())->toBe(0);
});

it('fails closed when the tenant it is told and the tenant it is in disagree', function (): void {
    // ⚠️ FOUND BY THE ADVERSARIAL PASS, AND IT IS AN API HAZARD THE ROW NEVER NAMED. `evaluate()` is public
    // and takes an EXPLICIT `$tenantId`, but the notification it raises is written through Eloquent and so
    // picks up the AMBIENT tenant from `BelongsToTenant`. Those are the same value for every caller today —
    // `PointsRecorder` passes what it just read — but nothing in the signature says so, and K1c's backfill
    // is about to call this per-tenant from inside a job.
    //
    // The good news is that it fails CLOSED rather than writing a badge into one workspace and announcing it
    // in another: the INSERT's `WITH CHECK` compares against the GUC, not against the argument, so a
    // mismatch raises 42501, the guard swallows it, `$earned` is empty and nothing is announced. That is a
    // real property of the design rather than luck, so it is pinned rather than left to be rediscovered.
    $other = inboxTenant('globex');

    $earned = app(BadgeAwarder::class)->evaluate(
        $other->id,              // the tenant we CLAIM
        $this->owner->id,
        PointRule::SubmissionCollected,
        Carbon::now(),
    );                           // ...while still inside $this->tenant's context

    expect($earned)->toBe([])
        ->and(BadgeAward::query()->count())->toBe(0)
        ->and(badgeNotifications())->toHaveCount(0);

    // ...and nothing landed in the CLAIMED workspace either. Checked from inside that tenant's own context
    // rather than with `withoutTenantScope()`, because dropping the ORM scope would still leave RLS
    // confining the read — the assertion would pass without proving anything about the other side.
    enterTenant($other->id, $this->owner->id);

    expect(BadgeAward::query()->count())->toBe(0);
});

it('never lets the evaluator surface an exception into the act it is scoring', function (): void {
    // The totality contract stated directly rather than only through the recorder: evaluate() returns an
    // empty list instead of throwing, whatever the database does to it.
    DB::statement('ALTER TABLE badge_awards DROP CONSTRAINT badge_awards_badge_check');
    DB::statement("ALTER TABLE badge_awards ADD CONSTRAINT badge_awards_badge_check CHECK (badge = 'nothing')");

    $earned = DB::transaction(fn (): array => app(BadgeAwarder::class)->evaluate(
        $this->tenant->id,
        $this->owner->id,
        PointRule::SubmissionCollected,
        Carbon::now(),
    ));

    expect($earned)->toBe([]);
});
