<?php

declare(strict_types=1);

use App\Enums\AuditEvent;
use App\Models\Audit;
use App\Models\User;
use App\Services\Forms\FormService;
use App\Support\Audit\AuditFilterQuery;
use App\Support\Audit\AuditLogger;
use App\Support\Search\SearchTerms;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| `?q` on the audit log (Increment J1e).
|
| ══════════════════════════════════════════════════════════════════════════════════════════════════════
| ⚠️ THE KEYWORD NARROWS TO TARGET AND ACTOR. IT IS NEVER A TEXT SEARCH OVER THE REDACTED jsonb DIFF.
| ══════════════════════════════════════════════════════════════════════════════════════════════════════
| `AuditRedactor` placeholders every SECRETS and PII key on BOTH sides of every diff, and
| `AuditableTypes::label()` fails open — so a newly registered alias is un-redacted until someone extends
| the catalog. A `::text ILIKE` over `old_values`/`new_values` would therefore be an oracle over exactly the
| values redaction exists to remove: the placeholder hides a secret from the screen, while a search that
| matched it would confirm it, one guess at a time, to the same viewer.
|
| The pinning case seeds a distinctive token into `new_values` and requires that it be UNFINDABLE while the
| same row remains findable by its target's title. Both halves matter: an "unfindable" assertion alone
| would pass against a `q` that was quietly ignored.
|
| The second thing this file exists for is the EXPORT. `AuditLogController` hands ONE `filters()` array to
| both the page and `AuditExporter`, and `audit/Index.vue` builds the download URL from the same params it
| navigates with — so "I exported what I was looking at" is a compliance guarantee. Until J1e each side
| spelled the filter chain out for itself and nothing compared them.
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    (new RolePermissionSeeder)->run();

    $this->tenant = inboxTenant();
    $this->owner = User::factory()->create(['name' => 'Rosa Delgado']);
    enterTenant($this->tenant->id, $this->owner->id);
    makeActiveMember($this->owner, 'owner');

    $this->colleague = User::factory()->create(['name' => 'Tomas Bautista']);
    makeActiveMember($this->colleague, 'admin');

    $this->actingAs($this->owner);

    // Two form-creation rows, one per actor, so the target branch and the actor branch are separable.
    $this->clinic = app(FormService::class)->create($this->tenant, $this->owner, 'Clinic Intake');
    $this->household = app(FormService::class)->create($this->tenant, $this->colleague, 'Household Survey');
});

afterEach(fn () => app(PermissionRegistrar::class)->setPermissionsTeamId(null));

/** @return list<string> the auditable ids the filter returns for $keyword */
function auditedTargets(?string $keyword): array
{
    $ids = AuditFilterQuery::apply(
        Audit::query(),
        ['q' => $keyword === null ? null : SearchTerms::parse($keyword)],
    )->pluck('auditable_id')->all();

    sort($ids);

    return array_values(array_map(strval(...), $ids));
}

it('finds a row by its TARGET’s title', function (): void {
    expect(auditedTargets('clinic'))->toBe([$this->clinic->id]);
});

it('finds a row by its ACTOR’s name, on the default connection', function (): void {
    // The actors subquery runs where `users_visibility` is the tenant boundary — never on `pgsql_auth`,
    // where `users_auth_select ... USING (true)` means no boundary of any kind. J1c's standing rule.
    expect(auditedTargets('bautista'))->toBe([$this->household->id]);
});

it('returns everything when no keyword is given', function (): void {
    expect(auditedTargets(null))->toHaveCount(2);
});

it('NEVER matches a token that exists only inside the redacted diff', function (): void {
    // The channel this design refuses to open. `hunter2secrettoken` appears nowhere but `new_values`.
    app(AuditLogger::class)->record(
        AuditEvent::Updated,
        'settings',
        (string) $this->tenant->id,
        old: [],
        new: ['smtp_password' => 'hunter2secrettoken'],
        actorId: (string) $this->owner->getKey(),
    );

    expect(Audit::query()->count())->toBe(3);

    // Unfindable by the secret...
    expect(auditedTargets('hunter2secrettoken'))->toBe([]);

    // ...while `q` demonstrably still works, so this is a refusal rather than a dead parameter.
    expect(auditedTargets('clinic'))->toBe([$this->clinic->id]);
});

it('does not match a form title against a NON-form row that happens to share the id', function (): void {
    // The `auditable_type = 'form'` conjunct inside the target branch. `auditable_id` is a bare uuid with
    // no type discriminator, so without it a submission row whose id collided with a matching form's would
    // come back under a form's name. Constructed here rather than waited for.
    app(AuditLogger::class)->record(
        AuditEvent::Updated,
        'submission',
        (string) $this->clinic->id,
        old: [],
        new: ['status' => 'validated'],
        actorId: (string) $this->colleague->getKey(),
    );

    // The keyword must return the FORM row only — one row, not the two sharing that auditable_id.
    $rows = AuditFilterQuery::apply(Audit::query(), ['q' => SearchTerms::parse('clinic')])->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->auditable_type)->toBe('form');
});

it('ANDs the keyword with the other filters rather than replacing them', function (): void {
    $filters = [
        'q' => SearchTerms::parse('clinic'),
        'user_id' => (string) $this->colleague->getKey(),
    ];

    // Clinic was created by the OWNER, so a keyword AND an actor who did not touch it is legitimately zero.
    // Replace-instead-of-AND in either direction returns one row.
    expect(AuditFilterQuery::apply(Audit::query(), $filters)->count())->toBe(0);
});

it('reports hasAnyFilter for a keyword, which is what drives empty_reason', function (): void {
    expect(AuditFilterQuery::hasAnyFilter(['q' => SearchTerms::parse('clinic')]))->toBeTrue()
        // A whitespace-only query parses to empty terms and must NOT count as a filter — otherwise a bare
        // ledger would render "no matches" instead of "nothing recorded yet".
        ->and(AuditFilterQuery::hasAnyFilter(['q' => SearchTerms::parse('   ')]))->toBeFalse()
        ->and(AuditFilterQuery::hasAnyFilter([]))->toBeFalse();
});

it('runs both branches against `audits` with leakproof quals only — measured, not asserted in prose', function (): void {
    // J1b's central finding was that a REASONED-ABOUT plan was wrong: PostgreSQL refuses to promote a
    // non-leakproof clause to an index qual on a relation carrying RLS quals, which is what made the two
    // GIN indexes unreachable. The shape here keeps `@@` and `ILIKE` inside subqueries against `forms` and
    // `users`, so every predicate ON `audits` stays `=`/`IN` over plain columns.
    //
    // What this case pins is the property that survives a refactor: the plan must contain NO scan of the
    // jsonb columns, and the audits-side quals must be the indexed ones.
    $sql = AuditFilterQuery::apply(Audit::query(), ['q' => SearchTerms::parse('clinic')])->toRawSql();

    expect($sql)
        ->not->toContain('old_values')
        ->not->toContain('new_values')
        ->toContain('auditable_type')
        ->toContain('auditable_id')
        ->toContain('user_id');

    $plan = collect(DB::select(
        'EXPLAIN '.AuditFilterQuery::apply(Audit::query(), ['q' => SearchTerms::parse('clinic')])->toSql(),
        AuditFilterQuery::apply(Audit::query(), ['q' => SearchTerms::parse('clinic')])->getBindings(),
    ))->pluck('QUERY PLAN')->implode("\n");

    // The plan is recorded rather than pattern-matched on an index NAME: on a three-row fixture the
    // planner will legitimately choose a sequential scan regardless of what is available, and asserting
    // otherwise would be asserting the fixture size. What must never appear is a jsonb read.
    expect($plan)->not->toContain('old_values')->not->toContain('new_values');
});

it('serves the page with the keyword echoed and the right empty_reason', function (): void {
    $this->withoutVite();

    $this->get('http://acme.meridian.test/audit-log?q=clinic')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('audit/Index', false)
            ->where('filters.applied.q', 'clinic')
            ->where('empty_reason', null)
            ->has('data', 1));

    $this->get('http://acme.meridian.test/audit-log?q=zzzznothing')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('empty_reason', 'no_matches')
            ->has('data', 0));
});

it('carries the keyword into the EXPORT, so the file matches the screen', function (): void {
    // The defect this whole extraction exists to prevent: page shows one row, CSV carries every row.
    $csv = $this->get('http://acme.meridian.test/audit-log/export?format=csv&q=clinic')
        ->assertOk()
        ->streamedContent();

    expect($csv)->toContain('Clinic Intake')->not->toContain('Household Survey');
});

it('records the keyword on the export’s own audit row', function (): void {
    $this->get('http://acme.meridian.test/audit-log/export?format=csv&q=clinic')->assertOk()->streamedContent();

    $row = Audit::query()->where('auditable_type', 'audit_log')->latest('id')->firstOrFail();

    // A ledger entry claiming a far wider export than happened is the failure mode. The CLAMPED raw string
    // is what is recorded — not the `foo & bar:*` tsquery, which nobody auditing this in a year should
    // have to decode.
    expect($row->new_values['q'] ?? null)->toBe('clinic');
});

it('clamps rather than refuses a hostile query on a GET', function (): void {
    $this->withoutVite();

    foreach (['&', 'foo &', "'; drop--", '(clinic', '   ', str_repeat('z', 300)] as $hostile) {
        $this->get('http://acme.meridian.test/audit-log?q='.urlencode($hostile))->assertOk();
    }

    // `nullable` beside `sometimes` is what keeps the whitespace case a 200: `TrimStrings` +
    // `ConvertEmptyStringsToNull` run BEFORE validation, so `?q=%20%20` arrives as a PRESENT key with a
    // null value, which `string` alone rejects. A unit-level `validator()` call cannot see this.
    $this->get('http://acme.meridian.test/audit-log?q=%20%20')->assertOk();
});
