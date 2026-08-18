<?php

declare(strict_types=1);

use App\Enums\FeedbackStatus;
use App\Models\FeedbackReport;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\SuperAdminContext;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Increment I7a — `feedback_reports` at the DB level, with the carve-out installed.
|--------------------------------------------------------------------------
| The bypass migration adds a permissive SELECT policy scoped `TO meridian_superadmin` and gated on the
| `app.is_superadmin_context` GUC. Three separate things have to be true, and only one of them is
| obvious:
|
|   1. The elevated role CAN read across tenants while the context is open.
|   2. It CANNOT when the context is closed (the gate is `= 'true'`, and an unset GUC is NULL, so it
|      fails closed with no cast hazard).
|   3. **Nothing changed for anyone else.** The policy is role-scoped, so an ordinary tenant connection
|      is untouched — setting the GUC on it grants nothing, and a tenant still cannot see another
|      tenant's reports. This is the assertion that would catch a bypass written without the `TO` clause,
|      which is the mistake that turns a support tool into a cross-tenant leak.
|
| It also pins the DELIBERATE ABSENCES: no INSERT/UPDATE/DELETE bypass on `feedback_reports` (writes go
| through adopt-context), and no bypass of ANY kind on `attachments` (the console reaches a screenshot
| the same way). Both are decisions, not omissions, so a later "for symmetry" addition fails here first.
|
| Fixtures are committed on the privileged connection for the reason FeedbackConsoleTest states: the
| elevated connection cannot see RefreshDatabase's uncommitted rows.
|
| ⚠️ **EVERY QUERY BELOW DROPS THE ORM TENANT SCOPE, AND THAT IS NOT A SHORTCUT.** `BelongsToTenant` adds
| its own `where tenant_id = <context ?? NO_TENANT_SENTINEL>` predicate. Leaving it on would make these
| assertions pass whether the database policies exist or not — the ORM would be doing the hiding, this
| file would be green, and a bypass written without its `TO meridian_superadmin` clause would sail
| through. Dropping it is what puts the questions to POSTGRES, which is the only layer ADR-0002 treats as
| the guarantee.
*/

/**
 * ⚠️ The query builder, not `Tenant::on('pgsql_privileged')`: stancl's `CentralConnection` trait makes
 * `getConnectionName()` ignore any override, so the model version silently writes to the default
 * connection inside RefreshDatabase's transaction and the committed FK insert then fails elsewhere.
 * FeedbackConsoleTest carries the full note.
 */
function rlsTenant(string $slug): Tenant
{
    $id = Uuid::uuid7()->toString();

    DB::connection('pgsql_privileged')->table('tenants')->insert([
        'id' => $id,
        'name' => 'RLS '.$slug,
        'slug' => $slug,
        'status' => 'active',
        'data' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    /** @var Tenant $tenant */
    $tenant = Tenant::query()->findOrFail($id);

    return $tenant;
}

function rlsUser(): User
{
    /** @var User $user */
    $user = User::on('pgsql_privileged')->forceCreate([
        'name' => 'RLS Reporter',
        'email' => Str::random(10).'@feedbackrlstest.local',
        'password' => Hash::make(Str::random(40)),
        'email_verified_at' => now(),
    ]);

    return $user;
}

function rlsReport(Tenant $tenant, User $user, string $remarks): string
{
    $id = Uuid::uuid7()->toString();

    FeedbackReport::on('pgsql_privileged')->forceCreate([
        'id' => $id,
        'tenant_id' => $tenant->id,
        'user_id' => $user->id,
        'route' => '/dashboard',
        'remarks' => $remarks,
        'browser_info' => [],
        'status' => FeedbackStatus::New->value,
        'submitted_at' => now(),
    ]);

    return $id;
}

beforeEach(function (): void {
    TenantContext::flush();

    // Committed fixtures are purged after the test transaction rolls back — see the long note in
    // FeedbackConsoleTest for why leaving committed TENANTS behind is not the same trade
    // SuperAdminBypassTest made with committed users.
    $this->beforeApplicationDestroyed(purgeCommittedFeedbackFixtures(...));
});

it('lets the elevated role read reports from every tenant while the context is open', function (): void {
    $a = rlsReport(rlsTenant('rls-a-'.Str::random(6)), rlsUser(), 'A '.Str::random(8));
    $b = rlsReport(rlsTenant('rls-b-'.Str::random(6)), rlsUser(), 'B '.Str::random(8));

    // MUST be inside the transaction: applyLocal() is is_local = true and is discarded outside it.
    DB::connection(SuperAdminContext::CONNECTION)->transaction(function () use ($a, $b): void {
        SuperAdminContext::applyLocal();

        expect(
            FeedbackReport::on(SuperAdminContext::CONNECTION)
                ->withoutGlobalScope(FeedbackReport::$tenantScope)
                ->whereIn('id', [$a, $b])
                ->count()
        )->toBe(2);
    });
});

it('fails closed for the elevated role when the context is not open', function (): void {
    $id = rlsReport(rlsTenant('rls-closed-'.Str::random(6)), rlsUser(), 'Closed '.Str::random(8));

    DB::connection(SuperAdminContext::CONNECTION)->transaction(function () use ($id): void {
        // No applyLocal(). `current_setting(..., true)` is NULL and `NULL = 'true'` is NULL, not true.
        expect(
            FeedbackReport::on(SuperAdminContext::CONNECTION)
                ->withoutGlobalScope(FeedbackReport::$tenantScope)
                ->whereKey($id)
                ->count()
        )->toBe(0);
    });
});

it('grants nothing when the GUC is set on the ordinary application connection', function (): void {
    $tenantA = rlsTenant('rls-role-a-'.Str::random(6));
    $tenantB = rlsTenant('rls-role-b-'.Str::random(6));
    $userA = rlsUser();
    $foreign = rlsReport($tenantB, rlsUser(), 'Other tenant '.Str::random(8));

    enterTenant($tenantA->id, $userA->id);

    // The carve-out is scoped `TO meridian_superadmin`. Opening the GUC on the app connection — which is
    // what an attacker with SQL execution inside the app would try — changes nothing at all.
    DB::statement("SELECT set_config('app.is_superadmin_context', 'true', false)");

    try {
        // Asked of the DATABASE: the ORM scope is dropped and the row is named directly, so the only thing
        // that can be hiding tenant B's report from tenant A's connection is the strict RLS policy.
        expect(
            FeedbackReport::withoutTenantScope()->whereKey($foreign)->count()
        )->toBe(0);
    } finally {
        DB::statement("SELECT set_config('app.is_superadmin_context', NULL, false)");
    }
});

it('installs a SELECT bypass and nothing more on feedback_reports', function (): void {
    /** @var list<object{policyname: string, cmd: string}> $policies */
    $policies = DB::select(
        "SELECT policyname, cmd FROM pg_policies WHERE tablename = 'feedback_reports' AND policyname LIKE 'feedback_reports_superadmin_%'"
    );

    // Exactly one, and it is SELECT. An INSERT/UPDATE/DELETE bypass added "for symmetry" would mean the
    // console could write outside RLS and its audit row would land with tenant_id = NULL — invisible to
    // the very workspace RBAC §9 says must be able to see how its report was handled.
    expect($policies)->toHaveCount(1)
        ->and($policies[0]->policyname)->toBe('feedback_reports_superadmin_select')
        ->and($policies[0]->cmd)->toBe('SELECT');
});

it('installs no super-admin bypass at all on attachments', function (): void {
    /** @var list<object{policyname: string}> $policies */
    $policies = DB::select(
        "SELECT policyname FROM pg_policies WHERE tablename = 'attachments' AND policyname LIKE 'attachments_superadmin_%'"
    );

    // The console reads a feedback screenshot by ADOPTING the report's tenant context, never by elevation.
    // A bypass here would have handed the platform operator every respondent's uploaded file and every OCR
    // source scan in the deployment — bought to display one image.
    expect($policies)->toBe([]);
});
