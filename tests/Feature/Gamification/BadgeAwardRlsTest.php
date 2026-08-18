<?php

declare(strict_types=1);

use App\Enums\BadgeKey;
use App\Enums\PlanTier;
use App\Models\BadgeAward;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Row-level security on `badge_awards` (K1b, ADR-0002 / ADR-0020).
|
| Raw `DB::` throughout, deliberately, for the reason SsoConnectionRlsTest gives: `BelongsToTenant` adds
| `where tenant_id = …` to every model query, so an ORM-only test would pass identically with RLS switched
| off — and RLS is the entire isolation here.
|
| Three things this file pins that no other test can:
|
|  1. THE TABLE IS `append_only`, NOT `strict`. That is the enforcement behind the claim that re-thresholding
|     a badge cannot un-earn one, and behind the Modules card's promise that switching gamification off
|     deletes nothing. A docblock cannot be broken by a future UPDATE; a missing policy can.
|  2. AN RLS **INSERT** REFUSAL RAISES RATHER THAN SILENTLY AFFECTING ZERO ROWS. BadgeAwarder's whole safety
|     argument rests on that asymmetry — it writes raw SQL and passes tenant_id itself, and the reason that
|     is acceptable is that getting it wrong is loud. If it ever became silent, the awarder's swallow-and-log
|     would hide every lost badge.
|  3. THE CHECK CONSTRAINT WAS GENERATED FROM THE ENUM AT ALL. Nothing else in the suite reads it, and the
|     migration that widens it for a new case cannot be validated by a `migrate:fresh` suite — the create
|     migration already builds it from `values()`. This is the only guard on that pair.
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
    BadgeAward::factory()->forBadge(BadgeKey::FirstPublish)->create(['user_id' => $this->acmeUser->id]);
});

afterEach(fn () => app(PermissionRegistrar::class)->setPermissionsTeamId(null));

it('has row-level security enabled AND forced on badge_awards', function (): void {
    // FORCE is the half that matters: without it the table's OWNER — the application role — bypasses every
    // policy, and this whole file would pass while isolating nothing.
    $flags = DB::selectOne(
        'SELECT relrowsecurity, relforcerowsecurity FROM pg_class WHERE relname = ?',
        ['badge_awards'],
    );

    expect($flags->relrowsecurity)->toBeTrue()
        ->and($flags->relforcerowsecurity)->toBeTrue();
});

it('carries SELECT and INSERT policies and deliberately NO update or delete policy', function (): void {
    /** @var list<object{cmd: string}> $policies */
    $policies = DB::select('SELECT cmd FROM pg_policies WHERE tablename = ?', ['badge_awards']);
    $commands = array_map(static fn (object $row): string => strtoupper($row->cmd), $policies);

    // The append-only shape (the `audits` and `point_awards` precedent). An UPDATE or DELETE policy
    // appearing here later is not a refinement — it is an earned achievement becoming editable, which is
    // the one property the whole "persisted, not derived" argument rests on. So this asserts ABSENCE.
    expect($commands)->toContain('SELECT')
        ->and($commands)->toContain('INSERT')
        ->and($commands)->not->toContain('UPDATE')
        ->and($commands)->not->toContain('DELETE')
        ->and($commands)->not->toContain('ALL');
});

it('hides one workspace’s badges from another through the database, not the ORM', function (): void {
    enterTenant($this->globex->id, $this->acmeUser->id);

    // Raw SQL with no predicate at all: whatever comes back is what RLS alone permits.
    expect(DB::select('SELECT id FROM badge_awards'))->toBeEmpty();

    enterTenant($this->acme->id, $this->acmeUser->id);

    expect(DB::select('SELECT id FROM badge_awards'))->toHaveCount(1);
});

it('returns zero rows with NO tenant context at all, rather than everything', function (): void {
    TenantContext::applyLocal(null);

    // Fail-closed: `current_setting('app.current_tenant_id', true)` is NULL and no row matches. ⚠️ This is
    // also the read the evaluator's COUNT makes, and it is why that count is taken on the same connection
    // immediately after a successful INSERT — a mis-scoped read here would grant no badge and log nothing.
    expect(DB::select('SELECT id FROM badge_awards'))->toBeEmpty();
});

it('RAISES rather than silently writing nothing when an insert is mis-scoped', function (): void {
    // ⚠️ THE ASYMMETRY THE AWARDER'S RAW-SQL PATH RESTS ON. A filtered UPDATE that matches no policy affects
    // zero rows and returns cleanly; an INSERT that matches no WITH CHECK policy RAISES 42501. That is what
    // makes "pass tenant_id explicitly" safe rather than reckless — getting it wrong is loud, so it lands in
    // the awarder's log instead of disappearing. If this ever inverted, every lost badge would be silent.
    expect(fn () => DB::insert(
        'INSERT INTO badge_awards (tenant_id, user_id, badge, awarded_at, created_at, updated_at) '
        .'VALUES (?, ?, ?, now(), now(), now())',
        [$this->globex->id, $this->acmeUser->id, BadgeKey::Collector->value],
    ))->toThrow(QueryException::class);
});

it('refuses a second row for the same member and badge at the index', function (): void {
    // Fired directly at the table rather than through an act, on the K1a lesson: driving the emitter twice
    // proves the EMITTER's early return, which says nothing about what the writer keys on.
    expect(fn () => DB::insert(
        'INSERT INTO badge_awards (tenant_id, user_id, badge, awarded_at, created_at, updated_at) '
        .'VALUES (?, ?, ?, now(), now(), now())',
        [$this->acme->id, $this->acmeUser->id, BadgeKey::FirstPublish->value],
    ))->toThrow(QueryException::class);
});

it('refuses a badge the catalog does not contain', function (): void {
    // The enum↔constraint drift guard, and the ONLY thing in the suite that proves the CHECK was generated
    // from `BadgeKey::values()` rather than hand-written or forgotten. A retired-then-reused key, or a
    // migration that shipped a stale list, both surface here and nowhere else.
    expect(fn () => DB::insert(
        'INSERT INTO badge_awards (tenant_id, user_id, badge, awarded_at, created_at, updated_at) '
        .'VALUES (?, ?, ?, now(), now(), now())',
        [$this->acme->id, $this->acmeUser->id, 'collector_9000'],
    ))->toThrow(QueryException::class);
});

it('pins the CHECK against the catalog, name for name', function (): void {
    // Stronger than the refusal above, which only proves SOME constraint exists. This proves it admits
    // EXACTLY the catalog — a case added to the enum without its widening migration is invisible until a
    // real member earns it, and then it raises 23514 inside whatever transaction was scoring them.
    // `point_awards` has no equivalent test; this is a K1b improvement rather than a copy.
    $definition = DB::selectOne(
        'SELECT pg_get_constraintdef(oid) AS def FROM pg_constraint WHERE conname = ?',
        ['badge_awards_badge_check'],
    );

    expect($definition)->not->toBeNull();

    foreach (BadgeKey::values() as $value) {
        expect($definition->def)->toContain("'{$value}'");
    }

    // Anti-vacuity in the other direction: the constraint must not admit anything the catalog dropped.
    expect(substr_count($definition->def, "'"))->toBe(count(BadgeKey::values()) * 2);
});

it('cannot be updated or deleted, even by the role that owns the table', function (): void {
    // The append-only claim exercised rather than only inspected. FORCE RLS with no UPDATE/DELETE policy
    // means both commands match nothing — so they affect zero rows rather than raising, which is exactly
    // why the policy-absence assertion above exists as well: this alone could not tell "denied" from
    // "matched nothing".
    $before = DB::selectOne('SELECT count(*) AS n FROM badge_awards');

    expect(DB::update('UPDATE badge_awards SET badge = ?', [BadgeKey::Collector->value]))->toBe(0)
        ->and(DB::delete('DELETE FROM badge_awards'))->toBe(0)
        ->and(DB::selectOne('SELECT count(*) AS n FROM badge_awards')->n)->toBe($before->n);
});
