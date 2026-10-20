<?php

declare(strict_types=1);

use App\Enums\FeedbackSource;
use App\Enums\FeedbackStatus;
use App\Enums\FeedbackType;
use App\Models\FeedbackReport;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Feedback\FeedbackPresenter;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
| M179 (`D150`) — the `feedback.testing_round_open` shared prop: how many relayed reports are still open in this
| workspace, so the Feedback panel can point at the Testing-round comments tab while there is something to check.
| Open means not Confirmed and not Declined — a Resolved one is open, because it waits for a tester. Null off-tenant,
| where no query runs (the `entitlements` prop's fail-closed rule).
*/

beforeEach(function (): void {
    $this->withoutVite();
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

function sharedRoundReport(User $reporter, FeedbackStatus $status, bool $relayed, string $reference): void
{
    $report = FeedbackReport::create([
        'user_id' => $reporter->id,
        'type' => FeedbackType::Bug,
        'route' => '/forms',
        'remarks' => 'A comment.',
        'status' => $status,
    ]);

    if ($relayed) {
        $report->forceFill(['source' => FeedbackSource::Relayed, 'reference' => $reference])->save();
    }
}

it('counts the workspace\'s open relayed reports on every page, and nothing else', function (): void {
    $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
    $tenant->domains()->create(['domain' => 'acme']);
    $owner = User::factory()->create();
    $tester = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    makeActiveMember($owner, 'owner');
    makeActiveMember($tester, 'viewer');

    sharedRoundReport($owner, FeedbackStatus::Resolved, true, 'U1');
    sharedRoundReport($owner, FeedbackStatus::Ongoing, true, 'U8');
    sharedRoundReport($owner, FeedbackStatus::Confirmed, true, 'R1-4');
    sharedRoundReport($owner, FeedbackStatus::Declined, true, 'X1');
    sharedRoundReport($owner, FeedbackStatus::New, false, 'ignored');

    $other = Tenant::create(['name' => 'Globex', 'slug' => 'globex']);
    $stranger = User::factory()->create();
    enterTenant($other->id, $stranger->id);
    makeActiveMember($stranger, 'owner');
    sharedRoundReport($stranger, FeedbackStatus::Resolved, true, 'U1');
    enterTenant($tenant->id, $owner->id);

    $this->actingAs($tester)->get('http://acme.meridian.test/dashboard')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('feedback.testing_round_open', 2));
});

it('is null without a workspace, and runs no query there', function (): void {
    TenantContext::flush();

    expect(app(FeedbackPresenter::class)->testingRoundOpen())->toBeNull();
});
