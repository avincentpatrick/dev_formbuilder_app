<?php

declare(strict_types=1);

use App\Enums\PlanTier;
use App\Enums\PointRule;
use App\Events\MemberJoined;
use App\Models\PointAward;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Gamification\PointsRecorder;
use App\Services\Tenancy\TenantMembershipService;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Row-level security on `point_awards` (K1a, ADR-0002 / ADR-0020).
|
| Raw `DB::` throughout, deliberately, for the reason SsoConnectionRlsTest gives: `BelongsToTenant` adds
| `where tenant_id = …` to every model query, so an ORM-only test would pass identically with RLS switched
| off — and RLS is the entire isolation here.
|
| Two things this file pins that no other test can:
|
|  1. THE TABLE IS `append_only`, NOT `strict`. That is the enforcement behind PointRule's claim that
|     re-weighting a rule cannot rewrite history and behind the Modules card's promise that switching the
|     module off deletes nothing. A docblock cannot be broken by a future UPDATE; a missing policy can.
|  2. AN RLS **INSERT** REFUSAL RAISES RATHER THAN SILENTLY AFFECTING ZERO ROWS. PointsRecorder's whole
|     safety argument rests on that asymmetry — it writes raw SQL and passes tenant_id itself, and the
|     reason that is acceptable is that getting it wrong is loud. If this ever became silent, the recorder's
|     swallow-and-log would hide every lost award.
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->acme = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'default_locale' => 'en']);
    $this->acme->domains()->create(['domain' => 'acme']);
    $this->globex = Tenant::create(['name' => 'Globex', 'slug' => 'globex', 'default_locale' => 'en']);
    $this->globex->domains()->create(['domain' => 'globex']);

    $this->acmeUser = User::factory()->create();
    enterTenant($this->acme->id, $this->acmeUser->id);
    makeActiveMember($this->acmeUser, 'owner');
    assignPlanTier(PlanTier::Free);
    PointAward::factory()->forRule(PointRule::FormPublished)->create(['user_id' => $this->acmeUser->id]);
});

afterEach(fn () => app(PermissionRegistrar::class)->setPermissionsTeamId(null));

it('has row-level security enabled AND forced on point_awards', function (): void {
    // FORCE is the half that matters: without it the table's OWNER — the application role — bypasses every
    // policy, and this whole file would pass while isolating nothing.
    $flags = DB::selectOne(
        'SELECT relrowsecurity, relforcerowsecurity FROM pg_class WHERE relname = ?',
        ['point_awards'],
    );

    expect($flags->relrowsecurity)->toBeTrue()
        ->and($flags->relforcerowsecurity)->toBeTrue();
});

it('carries SELECT and INSERT policies and deliberately NO update or delete policy', function (): void {
    /** @var list<object{cmd: string}> $policies */
    $policies = DB::select('SELECT cmd FROM pg_policies WHERE tablename = ?', ['point_awards']);
    $commands = array_map(static fn (object $row): string => strtoupper($row->cmd), $policies);

    // The append-only shape (the `audits` precedent). An UPDATE or DELETE policy appearing here later is
    // not a refinement — it is the ledger becoming rewritable, so this asserts absence rather than presence.
    expect($commands)->toContain('SELECT')
        ->and($commands)->toContain('INSERT')
        ->and($commands)->not->toContain('UPDATE')
        ->and($commands)->not->toContain('DELETE')
        ->and($commands)->not->toContain('ALL');
});

it('hides one tenant\'s awards from another through the database, not the ORM', function (): void {
    enterTenant($this->globex->id);

    $rows = DB::select('SELECT id FROM point_awards');

    expect($rows)->toBeEmpty();

    enterTenant($this->acme->id, $this->acmeUser->id);

    expect(DB::select('SELECT id FROM point_awards'))->toHaveCount(1);
});

it('returns zero rows with NO tenant context at all, rather than everything', function (): void {
    TenantContext::applyLocal(null);

    // Fail-closed: `current_setting('app.current_tenant_id', true)` is NULL and no row matches. The read
    // side of the same GUC the recorder depends on for its write.
    expect(DB::select('SELECT id FROM point_awards'))->toBeEmpty();
});

it('RAISES rather than silently writing nothing when an insert is mis-scoped', function (): void {
    // The asymmetry PointsRecorder's design rests on. A filtered UPDATE affects zero rows and reports
    // success; an INSERT that fails the WITH CHECK throws 42501. If this ever inverts, every lost award
    // would vanish into the recorder's swallow-and-log with nothing in the ledger and nothing in the log.
    enterTenant($this->globex->id);

    $insert = fn (): int => DB::affectingStatement(
        'INSERT INTO point_awards '
        .'(tenant_id, user_id, rule, points, subject_type, subject_id, awarded_at, created_at, updated_at) '
        .'VALUES (?, ?, ?, ?, ?, ?, now(), now(), now())',
        [
            $this->acme->id, // NOT the tenant in context — this is the mis-scope
            $this->acmeUser->id,
            PointRule::FormCreated->value,
            PointRule::FormCreated->points(),
            'form',
            (string) Str::uuid7(),
        ],
    );

    expect($insert)->toThrow(QueryException::class);
});

it('credits a member who self-registers, through the real join path inside its own transaction', function (): void {
    // ⚠️ THE ONE LISTENER THAT RUNS INSIDE ITS EMITTER'S TRANSACTION, driven end-to-end rather than by a
    // hand-fired event — because only the real path exercises the two things that could break it:
    // `joinOpenTenant()` borrows tenant context via `applyLocal()` (SET LOCAL, dies at commit), and the
    // scoped EntitlementService may already have memoized a plan from before that context existed. Either
    // failing would produce NO award and NO error.
    $joiner = User::factory()->create();

    app(TenantMembershipService::class)->joinOpenTenant($this->acme, $joiner, 'viewer');

    enterTenant($this->acme->id, $joiner->id);

    $awards = PointAward::query()
        ->where('user_id', $joiner->id)
        ->where('rule', PointRule::MemberJoined->value)
        ->get();

    expect($awards)->toHaveCount(1)
        ->and($awards->first()->points)->toBe(PointRule::MemberJoined->points())
        ->and($awards->first()->subject_type)->toBe('member')
        ->and((string) $awards->first()->tenant_id)->toBe($this->acme->id);
});

it('cannot award the same member joining twice', function (): void {
    $joiner = User::factory()->create();

    app(TenantMembershipService::class)->joinOpenTenant($this->acme, $joiner, 'viewer');
    app(TenantMembershipService::class)->joinOpenTenant($this->acme, $joiner, 'viewer');

    enterTenant($this->acme->id, $joiner->id);

    // `member.joined` is the one rule bounded to once per membership, and it is bounded by keying on the
    // member rather than by any PHP check — the second `joinOpenTenant()` returns the existing membership
    // rather than raising, so the unique index is the only thing holding the line.
    expect(PointAward::query()->where('user_id', $joiner->id)->count())->toBe(1);
});

it('awards a REPEATED join event only once, because the subject is the member', function (): void {
    // ⚠️ THE MUTATION PASS'S ONE SURVIVOR, AND THE TEST ABOVE IS EXACTLY WHY IT SURVIVED.
    // Driving `joinOpenTenant()` twice proves the EMITTER's early return — the second call finds an
    // active membership and never raises the event at all (`MemberJoined`'s docblock: "BOTH returns sit
    // above the emission point"). So it says nothing about what the LISTENER keys on, and replacing the
    // subject with a fresh uuid per call stayed green through the whole suite. Firing the event directly
    // is the only thing that exercises the key, and the key is the entire bound on this rule: it is the
    // one award that must never repeat, and no PHP branch enforces that.
    $joiner = User::factory()->create();

    event(MemberJoined::for($this->acme, $joiner, 'viewer'));
    event(MemberJoined::for($this->acme, $joiner, 'viewer'));

    expect(
        PointAward::query()
            ->where('user_id', $joiner->id)
            ->where('rule', PointRule::MemberJoined->value)
            ->count()
    )->toBe(1);
});

it('leaves the caller\'s transaction usable after a duplicate award', function (): void {
    // ⚠️ THE MUTATION PASS FOUND THAT NOTHING PINNED THIS, AND IT IS ADR-0020 §D3b's ENTIRE ARGUMENT.
    // Swapping `ON CONFLICT DO NOTHING` for a plain INSERT survived every other test in this increment:
    // the duplicate raises 23505, the recorder swallows it, the row count is still 1, and all the
    // idempotency assertions stay green. What changes is invisible to them — in PostgreSQL a constraint
    // violation aborts the WHOLE transaction, and because the recorder catches it, nothing rolls back to a
    // savepoint. The caller's transaction is left poisoned and every later statement in it fails with
    // "current transaction is aborted". That is not theoretical: `TemplateService::instantiate()` wraps
    // `FormService::create()`, so it would take out a template instantiation over a scoreboard row.
    $recorder = app(PointsRecorder::class);
    $subject = (string) Str::uuid7();

    $recorder->award(PointRule::FormCreated, $this->acmeUser->id, 'form', $subject);

    $survived = DB::transaction(function () use ($recorder, $subject): bool {
        $recorder->award(PointRule::FormCreated, $this->acmeUser->id, 'form', $subject); // the duplicate

        // The statement that a poisoned transaction cannot execute. It is the assertion.
        return DB::select('SELECT 1 AS ok')[0]->ok === 1;
    });

    expect($survived)->toBeTrue()
        ->and(PointAward::query()->where('subject_id', $subject)->count())->toBe(1);
});

it('records the recorder\'s own successful write as exactly one row', function (): void {
    // PointsRecorder returns true only when a row was GENUINELY created — the distinction K1b's badge
    // evaluation and K1c's backfill counter both depend on. Asserting the boolean without asserting the
    // row (or the reverse) would let a recorder that always returned true pass.
    $recorder = app(PointsRecorder::class);
    $subject = (string) Str::uuid7();

    $first = $recorder->award(PointRule::FormCreated, $this->acmeUser->id, 'form', $subject);
    $second = $recorder->award(PointRule::FormCreated, $this->acmeUser->id, 'form', $subject);

    expect($first)->toBeTrue()
        ->and($second)->toBeFalse()
        ->and(PointAward::query()->where('subject_id', $subject)->count())->toBe(1);
});
