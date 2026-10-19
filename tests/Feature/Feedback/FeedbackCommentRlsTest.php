<?php

declare(strict_types=1);

use App\Enums\FeedbackParty;
use App\Enums\FeedbackStatus;
use App\Models\FeedbackComment;
use App\Models\FeedbackReport;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\SuperAdminContext;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| M179 (`R-129c44c3`, `D151`) — `feedback_comments` at the DB level: a thread per report, tenant-isolated.
|--------------------------------------------------------------------------
| The thread is the reports' sibling and carries exactly their arrangement (`FeedbackRlsTest` holds the
| reasoning in full): strict tenant isolation for every ordinary connection, plus a SELECT-ONLY carve-out for
| the platform support console, gated on the `app.is_superadmin_context` GUC and scoped `TO
| meridian_superadmin`. The support team's own replies are written through adopt-context, never through the
| carve-out, so it carries no INSERT/UPDATE/DELETE — pinned below, so a later "for symmetry" addition fails
| here first.
|
| And one thing the reports do not have: the comment's key to its report is COMPOSITE —
| `(tenant_id, feedback_report_id)` → `feedback_reports (tenant_id, id)` — so a comment can never be written
| against another workspace's report, even by a connection RLS does not confine (PostgreSQL checks foreign
| keys without row security).
|
| Fixtures are committed on the privileged connection (the elevated connection cannot see RefreshDatabase's
| uncommitted rows), every query drops the ORM tenant scope so the questions go to POSTGRES, and the purge
| removes the committed tenants — whose delete cascades to their reports and the reports' threads.
*/

/** See `FeedbackRlsTest::rlsTenant()` for why this is the query builder and not the model. */
function commentRlsTenant(string $slug): Tenant
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

function commentRlsUser(): User
{
    /** @var User $user */
    $user = User::on('pgsql_privileged')->forceCreate([
        'name' => 'RLS Commenter',
        'email' => Str::random(10).'@feedbackrlstest.local',
        'password' => Hash::make(Str::random(40)),
        'email_verified_at' => now(),
    ]);

    return $user;
}

/** A committed report and one committed comment on it; returns [report id, comment id]. */
function commentRlsThread(Tenant $tenant, User $user, string $body): array
{
    $reportId = Uuid::uuid7()->toString();

    FeedbackReport::on('pgsql_privileged')->forceCreate([
        'id' => $reportId,
        'tenant_id' => $tenant->id,
        'user_id' => $user->id,
        'route' => '/dashboard',
        'remarks' => 'A report.',
        'browser_info' => [],
        'status' => FeedbackStatus::Resolved->value,
        'submitted_at' => now(),
    ]);

    $commentId = Uuid::uuid7()->toString();

    FeedbackComment::on('pgsql_privileged')->forceCreate([
        'id' => $commentId,
        'tenant_id' => $tenant->id,
        'feedback_report_id' => $reportId,
        'user_id' => $user->id,
        'author_kind' => FeedbackParty::Member->value,
        'body' => $body,
        'created_at' => now(),
    ]);

    return [$reportId, $commentId];
}

beforeEach(function (): void {
    TenantContext::flush();
    $this->beforeApplicationDestroyed(purgeCommittedFeedbackFixtures(...));
});

it('lets the elevated role read every workspace\'s threads while the context is open', function (): void {
    [, $a] = commentRlsThread(commentRlsTenant('crls-a-'.Str::random(6)), commentRlsUser(), 'A '.Str::random(8));
    [, $b] = commentRlsThread(commentRlsTenant('crls-b-'.Str::random(6)), commentRlsUser(), 'B '.Str::random(8));

    DB::connection(SuperAdminContext::CONNECTION)->transaction(function () use ($a, $b): void {
        SuperAdminContext::applyLocal();

        expect(
            FeedbackComment::on(SuperAdminContext::CONNECTION)
                ->withoutGlobalScope(FeedbackComment::$tenantScope)
                ->whereIn('id', [$a, $b])
                ->count()
        )->toBe(2);
    });
});

it('fails closed for the elevated role when the context is not open', function (): void {
    [, $id] = commentRlsThread(commentRlsTenant('crls-closed-'.Str::random(6)), commentRlsUser(), 'Closed '.Str::random(8));

    DB::connection(SuperAdminContext::CONNECTION)->transaction(function () use ($id): void {
        expect(
            FeedbackComment::on(SuperAdminContext::CONNECTION)
                ->withoutGlobalScope(FeedbackComment::$tenantScope)
                ->whereKey($id)
                ->count()
        )->toBe(0);
    });
});

it('keeps one workspace\'s thread from another, and the GUC grants the app connection nothing', function (): void {
    $tenantA = commentRlsTenant('crls-role-a-'.Str::random(6));
    $tenantB = commentRlsTenant('crls-role-b-'.Str::random(6));
    $userA = commentRlsUser();
    [, $foreign] = commentRlsThread($tenantB, commentRlsUser(), 'Other workspace '.Str::random(8));

    enterTenant($tenantA->id, $userA->id);

    expect(FeedbackComment::withoutTenantScope()->whereKey($foreign)->count())->toBe(0);

    DB::statement("SELECT set_config('app.is_superadmin_context', 'true', false)");

    try {
        expect(FeedbackComment::withoutTenantScope()->whereKey($foreign)->count())->toBe(0);
    } finally {
        DB::statement("SELECT set_config('app.is_superadmin_context', NULL, false)");
    }
});

it('installs a SELECT bypass and nothing more on feedback_comments', function (): void {
    /** @var list<object{policyname: string, cmd: string}> $policies */
    $policies = DB::select(
        "SELECT policyname, cmd FROM pg_policies WHERE tablename = 'feedback_comments' AND policyname LIKE 'feedback_comments_superadmin_%'"
    );

    expect($policies)->toHaveCount(1)
        ->and($policies[0]->policyname)->toBe('feedback_comments_superadmin_select')
        ->and($policies[0]->cmd)->toBe('SELECT');
});

it('refuses a comment that names another workspace\'s report, even on a connection RLS does not confine', function (): void {
    $tenantA = commentRlsTenant('crls-fk-a-'.Str::random(6));
    $tenantB = commentRlsTenant('crls-fk-b-'.Str::random(6));
    $user = commentRlsUser();
    [$reportInB] = commentRlsThread($tenantB, $user, 'B '.Str::random(8));

    $state = null;

    try {
        DB::connection('pgsql_privileged')->table('feedback_comments')->insert([
            'id' => Uuid::uuid7()->toString(),
            'tenant_id' => $tenantA->id,
            'feedback_report_id' => $reportInB,
            'user_id' => $user->id,
            'author_kind' => FeedbackParty::Member->value,
            'body' => 'Planted across the boundary.',
            'created_at' => now(),
        ]);
    } catch (QueryException $e) {
        $state = (string) ($e->errorInfo[0] ?? $e->getCode());
    }

    expect($state)->toBe('23503');
});
