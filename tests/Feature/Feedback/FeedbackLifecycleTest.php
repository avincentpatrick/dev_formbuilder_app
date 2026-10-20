<?php

declare(strict_types=1);

use App\Enums\AuditEvent;
use App\Enums\FeedbackStatus;
use App\Enums\FeedbackType;
use App\Exceptions\Admin\SuperAdminException;
use App\Models\Audit;
use App\Models\FeedbackReport;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Admin\SuperAdminService;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| M179 (`R-129c44c3`, `D151`) — the support door moves a report by the row as it IS, under a lock.
|--------------------------------------------------------------------------
| Until M179 `SuperAdminService::transitionFeedback()` guarded the move with the status of the MODEL it was
| handed — read before its transaction, on another request's say-so — and then re-read the row with no lock.
| Two moves at once could both pass a guard against a status that no longer held, and the audit recorded that
| stale status as the old one. With testers now moving reports too, the two doors can race for real.
|
| The fix is one lifecycle: inside the transaction that adopted the report's tenant context, the row is read
| `FOR UPDATE`, and both the guard and the audit read THAT row. Lock contention cannot be observed inside
| RefreshDatabase's single transaction (`PublishLockingTest` holds the full note), so this file proves the two
| things that are observable and deterministic:
|
|   · the STALE CASE — the model says one status, the row another; the guard follows the row;
|   · the STATEMENT SHAPE — a `select … for update` on `feedback_reports` is issued AFTER the tenant context is
|     set and BEFORE the write. Order matters: PostgreSQL applies the UPDATE policy to a locking SELECT as a
|     filter, so a lock taken with no tenant context would match nothing and fail silently. The re-read alone
|     makes the stale case pass, which is why only this case can catch a dropped lock.
*/

beforeEach(function (): void {
    TenantContext::flush();
    Notification::fake();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme-lifecycle']);
    $this->reporter = User::factory()->create();
    enterTenant($this->tenant->id, $this->reporter->id);
    makeActiveMember($this->reporter, 'viewer');

    $this->support = User::factory()->superAdmin()->confirmedTwoFactor()->create();
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

function lifecycleReport(User $reporter, FeedbackStatus $status): FeedbackReport
{
    return FeedbackReport::create([
        'user_id' => $reporter->id,
        'type' => FeedbackType::Bug,
        'route' => '/dashboard',
        'remarks' => 'The date range picker resets.',
        'status' => $status,
    ]);
}

it('guards a support move with the row as it is, not the model it was handed', function (): void {
    $report = lifecycleReport($this->reporter, FeedbackStatus::Resolved);

    // Another request re-opened it meanwhile. The model in hand still says Resolved, from which Support may
    // only re-open; the row says Ongoing, from which Support may resolve.
    DB::table('feedback_reports')->where('id', $report->id)->update(['status' => FeedbackStatus::Ongoing->value]);

    $written = app(SuperAdminService::class)->transitionFeedback($report, FeedbackStatus::Resolved, $this->support);

    expect($written->status)->toBe(FeedbackStatus::Resolved);

    /** @var Audit $audit */
    $audit = Audit::withoutTenantScope()->where('auditable_id', $report->id)->where('event', AuditEvent::Updated->value)->latest('id')->firstOrFail();

    // The ledger says what the row WAS — Ongoing — not what a stale model believed.
    expect($audit->old_values['status'] ?? null)->toBe(FeedbackStatus::Ongoing->value);
});

it('refuses to let support mark a report Confirmed — only a tester can', function (): void {
    $report = lifecycleReport($this->reporter, FeedbackStatus::Resolved);

    expect(fn () => app(SuperAdminService::class)->transitionFeedback($report, FeedbackStatus::Confirmed, $this->support))
        ->toThrow(SuperAdminException::class);

    expect($report->fresh()->status)->toBe(FeedbackStatus::Resolved);
});

it('locks the row after adopting the tenant context and before writing it', function (): void {
    $report = lifecycleReport($this->reporter, FeedbackStatus::New);

    /** @var list<string> $sql */
    $sql = [];
    DB::listen(static function (QueryExecuted $query) use (&$sql): void {
        $sql[] = strtolower($query->sql);
    });

    app(SuperAdminService::class)->transitionFeedback($report, FeedbackStatus::Ongoing, $this->support);

    $context = null;
    $lock = null;
    $write = null;

    foreach ($sql as $i => $statement) {
        if ($context === null && str_contains($statement, 'set_config')) {
            $context = $i;
        }
        if ($lock === null && str_contains($statement, 'from "feedback_reports"') && str_contains($statement, 'for update')) {
            $lock = $i;
        }
        if ($write === null && str_starts_with($statement, 'update "feedback_reports"')) {
            $write = $i;
        }
    }

    expect($context)->not->toBeNull('no tenant context was adopted')
        ->and($lock)->not->toBeNull('the row was never locked')
        ->and($write)->not->toBeNull('the row was never written')
        ->and($context < $lock)->toBeTrue('the lock came before the tenant context, where RLS would filter it to nothing')
        ->and($lock < $write)->toBeTrue('the row was written before it was locked');
});

it('clears a tester\'s confirmation when support re-opens a Confirmed report', function (): void {
    $report = lifecycleReport($this->reporter, FeedbackStatus::Confirmed);
    $report->forceFill(['confirmed_at' => now(), 'confirmed_by' => $this->reporter->id, 'resolved_at' => now(), 'resolved_by' => $this->support->id])->save();

    $written = app(SuperAdminService::class)->transitionFeedback($report, FeedbackStatus::Ongoing, $this->support);

    expect($written->status)->toBe(FeedbackStatus::Ongoing)
        ->and($written->confirmed_at)->toBeNull()
        ->and($written->confirmed_by)->toBeNull()
        ->and($written->resolved_at)->toBeNull()
        ->and($written->resolved_by)->toBeNull();
});
