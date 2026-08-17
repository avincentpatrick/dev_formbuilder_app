<?php

declare(strict_types=1);

use App\Enums\BadgeKey;
use App\Enums\PlanTier;
use App\Enums\PointRule;
use App\Enums\SubmissionSource;
use App\Enums\SubmissionStatus;
use App\Events\SubmissionApproved;
use App\Events\SubmissionCreated;
use App\Events\SubmissionReturned;
use App\Events\SubmissionUpdated;
use App\Models\BadgeAward;
use App\Models\FormTemplate;
use App\Models\PointAward;
use App\Models\User;
use App\Services\Entitlements\EntitlementService;
use App\Services\Forms\TemplateService;
use App\Services\Gamification\PointsRecorder;
use App\Services\Settings\TenantSettingRegistry;
use App\Services\Tenancy\TenantMembershipService;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| K1a — the gamification award ledger and the eight listeners that feed it.
|
| WHAT CLASS OF BUG THIS FILE EXISTS TO CATCH, in the order the bugs would actually happen:
|
|  1. THE SILENT ZERO-ROW WRITE. PointsRecorder writes raw SQL, so it bypasses BelongsToTenant and passes
|     tenant_id itself. Every awarding test therefore asserts a row EXISTS rather than asserting the
|     listener was called — a mis-scoped insert would satisfy the second and produce nothing.
|  2. FARMING. Four of the seven rules are unbounded acts (review, edit, invite, publish). The idempotency
|     index is the only thing standing between them and an infinite score, and NULLs in a Postgres unique
|     index are DISTINCT — which is why no subject is nullable and why these tests repeat every act.
|  3. THE GUEST ATTRIBUTION. `submission.collected` must credit the member who collected, and nobody at
|     all on the public channel. Crediting the form owner is the plausible wrong answer.
|  4. THE MODULE GATE reading the wrong layer. Gamification is granted on EVERY tier, so the plan can
|     never be what switches it off — only the tenant's own toggle can.
|
| A plan MUST be seeded (assignPlanTier). With no plan catalog at all EntitlementService::feature()
| resolves null ⇒ false, so a test written without one would pass whatever the engine did.
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
    assignPlanTier(PlanTier::Free); // the LOWEST tier — gamification must work there (user decision)
});

afterEach(fn () => app(PermissionRegistrar::class)->setPermissionsTeamId(null));

/** Every award the current tenant holds for one member, newest irrelevant — order is never asserted. */
function awardsFor(User $user, ?PointRule $rule = null): Collection
{
    $query = PointAward::query()->where('user_id', $user->id);

    if ($rule instanceof PointRule) {
        $query->where('rule', $rule->value);
    }

    return $query->get();
}

it('credits the collector of a manually encoded submission, with the weight copied at award time', function (): void {
    $form = publishedInboxForm($this->tenant, $this->owner);
    $submission = seedInboxSubmission($form, $this->owner, SubmissionStatus::Submitted, ['full_name' => 'Ada']);

    event(SubmissionCreated::for($submission));

    $awards = awardsFor($this->owner, PointRule::SubmissionCollected);

    expect($awards)->toHaveCount(1)
        ->and($awards->first()->points)->toBe(PointRule::SubmissionCollected->points())
        ->and($awards->first()->subject_type)->toBe('submission')
        ->and($awards->first()->subject_id)->toBe((string) $submission->getKey())
        ->and($awards->first()->awarded_at)->not->toBeNull()
        ->and($awards->first()->tenant_id)->toBe($this->tenant->id);
});

it('credits nobody for a guest submission', function (): void {
    $form = publishedInboxForm($this->tenant, $this->owner);
    $submission = seedInboxSubmission(
        $form,
        $this->owner,
        SubmissionStatus::Submitted,
        ['full_name' => 'Ada'],
        SubmissionSource::Guest,
    );

    event(SubmissionCreated::for($submission));

    // Not "the owner has no award" — NOBODY does. Crediting the form's owner is the plausible wrong answer
    // and it would satisfy a test that only checked the respondent.
    expect(PointAward::query()->where('rule', PointRule::SubmissionCollected->value)->count())->toBe(0);
});

it('scores a collected submission once however many times the event is replayed', function (): void {
    $form = publishedInboxForm($this->tenant, $this->owner);
    $submission = seedInboxSubmission($form, $this->owner, SubmissionStatus::Submitted, ['full_name' => 'Ada']);

    event(SubmissionCreated::for($submission));
    event(SubmissionCreated::for($submission));
    event(SubmissionCreated::for($submission));

    expect(awardsFor($this->owner, PointRule::SubmissionCollected))->toHaveCount(1);
});

it('credits the creator and the publisher through the real form services', function (): void {
    // publishedInboxForm() drives FormService::create() and PublishService::publish() for real, so this
    // exercises the post-commit emission point rather than a hand-fired event.
    $form = publishedInboxForm($this->tenant, $this->owner);

    $created = awardsFor($this->owner, PointRule::FormCreated);
    $published = awardsFor($this->owner, PointRule::FormPublished);

    expect($created)->toHaveCount(1)
        ->and($created->first()->subject_type)->toBe('form')
        ->and($created->first()->subject_id)->toBe((string) $form->getKey())
        ->and($published)->toHaveCount(1)
        ->and($published->first()->subject_type)->toBe('form_version')
        ->and($published->first()->subject_id)->toBe((string) $form->current_published_version_id);
});

it('credits the creator when a form arrives from a template, inside that outer transaction', function (): void {
    // ⚠️ THE ADVERSARIAL CASE, AND THE REASON THE SWEEP WAS DONE AT ALL. `FormService::create()` has TWO
    // callers, not one: `TemplateService::instantiate()` wraps it in its OWN `DB::transaction`, so the
    // post-commit emission this increment added fires while an outer transaction is still open. Two things
    // had to be true rather than assumed — the award must land (tenant context is session-scoped, so it
    // survives the inner commit), and a duplicate must not RAISE, because a raised 23505 here would abort
    // the outer transaction and take out a template instantiation that has nothing to do with scoring.
    $template = FormTemplate::create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Contact form',
        'schema_blueprint' => ['version' => 1, 'sections' => [], 'fields' => []],
        'is_public' => false,
        'usage_count' => 0,
    ]);

    $form = app(TemplateService::class)->instantiate($template, $this->tenant, $this->owner);

    $awards = awardsFor($this->owner, PointRule::FormCreated);

    expect($awards)->toHaveCount(1)
        ->and($awards->first()->subject_id)->toBe((string) $form->getKey());
});

it('scores a review once across both outcomes for one reviewer, and once more for a second', function (): void {
    $form = publishedInboxForm($this->tenant, $this->owner);
    $submission = seedInboxSubmission($form, $this->owner, SubmissionStatus::Submitted, ['full_name' => 'Ada']);

    $second = User::factory()->create();
    enterTenant($this->tenant->id, $second->id);
    makeActiveMember($second, 'reviewer');
    enterTenant($this->tenant->id, $this->owner->id);

    // The approve/return loop is the farming shape: unbounded, and the single most efficient way to top the
    // ladder if each pass scored. One reviewer, one submission, one award — whatever the outcomes were.
    event(SubmissionApproved::for($submission, $this->owner));
    event(SubmissionReturned::for($submission, $this->owner, SubmissionStatus::Submitted->value));
    event(SubmissionApproved::for($submission, $this->owner));

    // A DIFFERENT reviewer did their own work and earns their own award — user_id is in the key.
    event(SubmissionApproved::for($submission, $second));

    expect(awardsFor($this->owner, PointRule::SubmissionReviewed))->toHaveCount(1)
        ->and(awardsFor($second, PointRule::SubmissionReviewed))->toHaveCount(1);
});

it('scores a RETURN on its own as a review, not as something else', function (): void {
    // ⚠️ WRITTEN BECAUSE THE MUTATION PASS FOUND THE GAP. The test above drives approve→return→approve, so
    // the first approve alone already satisfies "one `submission.reviewed`" — which means changing the
    // RETURN listener's rule to anything else survived it untouched. Returning work is reviewing it, and
    // this is the only case that says so: a return with no approve anywhere near it.
    $form = publishedInboxForm($this->tenant, $this->owner);
    $submission = seedInboxSubmission($form, $this->owner, SubmissionStatus::Submitted, ['full_name' => 'Ada']);

    event(SubmissionReturned::for($submission, $this->owner, SubmissionStatus::Submitted->value));

    expect(awardsFor($this->owner, PointRule::SubmissionReviewed))->toHaveCount(1)
        ->and(awardsFor($this->owner))->toHaveCount(3); // + form.created + form.published, and nothing else
});

it('pins the published weights literally, not against the enum that produces them', function (): void {
    // ⚠️ ALSO A MUTATION-PASS FINDING, and the sharper of the two. Every other assertion in this file
    // compares a stored `points` against `PointRule::points()` — the same source the code read — so
    // swapping two weights in that match arm changes the code and the expectation together and survives
    // every one of them. The weights are a product statement (see the enum's docblock); a change to one
    // should have to be made twice, deliberately, here as well as there.
    expect(PointRule::FormPublished->points())->toBe(25)
        ->and(PointRule::MemberInvited->points())->toBe(15)
        ->and(PointRule::FormCreated->points())->toBe(10)
        ->and(PointRule::MemberJoined->points())->toBe(10)
        ->and(PointRule::SubmissionCollected->points())->toBe(5)
        ->and(PointRule::SubmissionReviewed->points())->toBe(3)
        ->and(PointRule::SubmissionEdited->points())->toBe(1);

    $form = publishedInboxForm($this->tenant, $this->owner);

    // …and one of them proved end-to-end against a real stored row, so the pin above cannot drift away
    // from what the ledger actually records.
    expect(awardsFor($this->owner, PointRule::FormPublished)->first()->points)->toBe(25);
});

it('scores an editor once however many edits they make', function (): void {
    $form = publishedInboxForm($this->tenant, $this->owner);
    $submission = seedInboxSubmission($form, $this->owner, SubmissionStatus::Submitted, ['full_name' => 'Ada']);

    event(SubmissionUpdated::for($submission, $this->owner, false));
    event(SubmissionUpdated::for($submission, $this->owner, true));

    expect(awardsFor($this->owner, PointRule::SubmissionEdited))->toHaveCount(1);
});

it('keys an invite on a hash of the email, never the address, and re-inviting cannot farm a second', function (): void {
    // ⚠️ THE INVITEE MUST BE A **COMMITTED** IDENTITY, AND THAT IS THE TEST ENVIRONMENT, NOT THE PRODUCT.
    // `invite()` resolves the global identity through `resolveUserByEmail()` on the SEPARATE `pgsql_auth`
    // connection, which cannot see rows written inside `RefreshDatabase`'s uncommitted outer transaction.
    // Invite an address with no committed row and the first call creates the user on the default
    // connection; the second call's lookup comes back empty and dies on `users_email_unique`. Nothing about
    // that is reachable in production, where the first invite has long since committed.
    $email = 'sam-'.Str::lower(Str::random(10)).'@identity.test';
    committedTenantIdentity('Sam', email: $email);

    // Re-inviting the SAME address is the real repeat path (an invite lapses, an Owner sends it again).
    app(TenantMembershipService::class)->invite($this->tenant, $email, 'viewer', $this->owner);
    app(TenantMembershipService::class)->invite($this->tenant, $email, 'viewer', $this->owner);

    $awards = awardsFor($this->owner, PointRule::MemberInvited);

    expect($awards)->toHaveCount(1)
        ->and($awards->first()->subject_type)->toBe('invite')
        ->and($awards->first()->subject_id)->toBe(PointsRecorder::emailSubject($this->tenant->id, $email))
        ->and($awards->first()->subject_id)->not->toContain('@')
        ->and($awards->first()->subject_id)->toHaveLength(64);
});

it('credits an invited member for joining once they accept, which earned them nothing until K1c', function (): void {
    // 🔴 A LIVE DEFECT K1a SHIPPED, FOUND BY K1c WHILE VERIFYING WHETHER THE BACKFILL COULD READ THE
    // MEMBERSHIP RULES OUT OF `audits`. `MemberJoined` was raised only by the three SELF-SERVE doors, so
    // the commonest door of all — being invited and accepting — earned no `member.joined` points and no
    // `welcome` badge, the one badge whose whole job is to keep a new member's page from being blank.
    //
    // It is fixed rather than filed because the backfill grants every HISTORICAL invited member their join
    // points from `tenant_users.joined_at`: without this, the very next acceptance would grant none, and
    // the scoreboard would permanently disagree with itself.
    $email = 'joiner-'.Str::lower(Str::random(10)).'@identity.test';
    $invitee = committedTenantIdentity('Jo', email: $email);

    $invite = app(TenantMembershipService::class)->invite($this->tenant, $email, 'viewer', $this->owner);
    app(TenantMembershipService::class)->accept($invite->refresh(), $invitee);

    $joined = awardsFor($invitee, PointRule::MemberJoined);

    expect($joined)->toHaveCount(1)
        ->and($joined->first()->subject_type)->toBe('member')
        // The USER id, matching the listener — NOT the membership uuid the audit row for a self-serve join
        // carries. Keying on that instead would not collide with this award; it would write a second one.
        ->and($joined->first()->subject_id)->toBe((string) $invitee->getKey())
        ->and($joined->first()->points)->toBe(10)
        // And the badge, which is the half a member actually sees.
        ->and(BadgeAward::query()->where('user_id', $invitee->id)->where('badge', BadgeKey::Welcome->value)->count())
        ->toBe(1);
});

it('scores an acceptance once, however many times the invitation flow is re-entered', function (): void {
    // `member.joined` keys on the member, so the unique index bounds it at one — but the emission is now
    // raised from TWO methods, and a member who self-registered into one workspace and accepted an
    // invitation to it could otherwise be scored twice.
    $email = 'twice-'.Str::lower(Str::random(10)).'@identity.test';
    $invitee = committedTenantIdentity('Twice', email: $email);

    $invite = app(TenantMembershipService::class)->invite($this->tenant, $email, 'viewer', $this->owner);
    app(TenantMembershipService::class)->accept($invite->refresh(), $invitee);
    app(TenantMembershipService::class)->joinOpenTenant($this->tenant, $invitee);

    expect(awardsFor($invitee, PointRule::MemberJoined))->toHaveCount(1);
});

it('normalizes case and surrounding space before hashing an invite subject', function (): void {
    // Pinned on the function rather than through two invites, because the engine must not depend on its
    // caller having normalized: `member.invited` is the one rule whose subject is an address rather than a
    // row id, so if `Sam@Example.com` and `sam@example.com` hashed differently they would be two awards for
    // one person — a farming hole opened by nothing more than the shift key.
    expect(PointsRecorder::emailSubject($this->tenant->id, '  Sam@Example.COM '))
        ->toBe(PointsRecorder::emailSubject($this->tenant->id, 'sam@example.com'));
});

it('salts the invite digest with the tenant, so two extracts cannot be joined on it', function (): void {
    // ⚠️ FOUND BY THE ADVERSARIAL PASS, NOT BY A FAILING TEST. `point_awards` is an EXTRACTED table
    // (TenantScopedTables), and an unsalted digest of an email is a globally stable join key — two tenants
    // each holding an extract could join on it and prove they invited the same person. That is verbatim
    // the fact TenantExtractColumns withholds `users.google_id` for, and it calls it "the one cross-tenant
    // fact this architecture exists to withhold". Salting is what lets the whole row be extracted rather
    // than censored, so this asserts the salt directly: same address, two workspaces, different digests.
    expect(PointsRecorder::emailSubject('tenant-a', 'sam@example.com'))
        ->not->toBe(PointsRecorder::emailSubject('tenant-b', 'sam@example.com'));
});

it('awards nothing at all while the module is switched off', function (): void {
    app(TenantSettingRegistry::class)->put($this->tenant, ['modules.gamification' => false], $this->owner);
    app(TenantSettingRegistry::class)->forget();
    app(EntitlementService::class)->forget($this->tenant->id);

    $form = publishedInboxForm($this->tenant, $this->owner);
    $submission = seedInboxSubmission($form, $this->owner, SubmissionStatus::Submitted, ['full_name' => 'Ada']);
    event(SubmissionCreated::for($submission));

    // The whole ledger, not one rule: a gate that only covered the listener it was tested through is the
    // shape that leaves three of eight still writing.
    expect(PointAward::query()->count())->toBe(0);
});

it('keeps gamification on for a FREE tenant, because no tier gates it', function (): void {
    // The inverse of the test above, and the one that would catch `gamification` being added to
    // ToggleableModules::KEYS but forgotten on the Free tier's own feature list.
    expect(app(EntitlementService::class)->feature(PointsRecorder::FEATURE))->toBeTrue();

    publishedInboxForm($this->tenant, $this->owner);

    expect(awardsFor($this->owner, PointRule::FormCreated))->toHaveCount(1);
});
