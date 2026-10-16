<?php

declare(strict_types=1);

use App\Enums\FeedbackStatus;
use App\Enums\FeedbackType;
use App\Models\FeedbackReport;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| M175 (`R-9bfd832e`, `D139`) — every member sees their own feedback.
|--------------------------------------------------------------------------
| The testers asked to be told what became of what they sent. Until now only Owner and Admin (`feedback.view`)
| could open `/feedback`, so a Viewer who reported a problem never saw its status again. `/feedback/mine`
| needs no permission beyond membership, and it shows the reporter their OWN reports only.
|
| ⚠️ RLS DOES NOT DO THE NARROWING HERE. `feedback_reports` is strict TENANT isolation: under it a member's
| query sees every colleague's report in the workspace. "Mine" is the application's `user_id` predicate,
| which is exactly what the colleague case below exists to prove — deleting the predicate leaves every
| tenant-isolation test green.
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

/** One report, written as the reporter under the tenant's context. */
function myReport(User $user, string $remarks, FeedbackStatus $status = FeedbackStatus::New, ?string $note = null): FeedbackReport
{
    $report = FeedbackReport::create([
        'user_id' => $user->id,
        'type' => FeedbackType::Bug,
        'route' => '/dashboard',
        'remarks' => $remarks,
        'status' => $status,
    ]);

    if ($note !== null) {
        $report->forceFill(['resolution_note' => $note])->save();
    }

    return $report;
}

it('shows a viewer their own reports, with the status in words and the reply, and nobody else\'s', function (): void {
    $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
    $tenant->domains()->create(['domain' => 'acme']);

    $viewer = User::factory()->create();
    $colleague = User::factory()->create();
    enterTenant($tenant->id, $viewer->id);
    makeActiveMember($viewer, 'viewer'); // no feedback.view — /feedback refuses them
    makeActiveMember($colleague, 'owner');

    myReport($viewer, 'Mine, still open.');
    myReport($viewer, 'Mine, and fixed.', FeedbackStatus::Resolved, 'Fixed in version abc1234, live on staging.');
    myReport($colleague, 'A colleague\'s report.');

    $other = Tenant::create(['name' => 'Globex', 'slug' => 'globex']);
    $stranger = User::factory()->create();
    enterTenant($other->id, $stranger->id);
    makeActiveMember($stranger, 'owner');
    myReport($stranger, 'Another workspace.');

    $this->actingAs($viewer)->get('http://acme.meridian.test/feedback')->assertForbidden();

    $this->actingAs($viewer)->get('http://acme.meridian.test/feedback/mine')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('feedback/Mine', false)
            ->has('data', 2)
            ->where('data', fn ($rows): bool => collect($rows)->pluck('remarks')->sort()->values()->all() === ['Mine, and fixed.', 'Mine, still open.'])
            ->where('data', fn ($rows): bool => collect($rows)->firstWhere('remarks', 'Mine, and fixed.')['resolution_note'] === 'Fixed in version abc1234, live on staging.'
                && collect($rows)->firstWhere('remarks', 'Mine, and fixed.')['status_label'] === 'Resolved'
                && collect($rows)->firstWhere('remarks', 'Mine, still open.')['type_label'] === 'Bug')
            ->where('empty_reason', null));
});

it('reads Ongoing and Declined over the stored values, which never change', function (): void {
    $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
    $tenant->domains()->create(['domain' => 'acme']);
    $user = User::factory()->create();
    enterTenant($tenant->id, $user->id);
    makeActiveMember($user, 'reviewer');

    myReport($user, 'In hand.', FeedbackStatus::Ongoing);
    myReport($user, 'Not this time.', FeedbackStatus::Declined, 'Out of scope for the pilot.');

    expect(FeedbackStatus::Ongoing->value)->toBe('reviewed')
        ->and(FeedbackStatus::Declined->value)->toBe('wont_fix');

    $this->actingAs($user)->get('http://acme.meridian.test/feedback/mine')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('data', fn ($rows): bool => collect($rows)->pluck('status_label', 'remarks')->sortKeys()->all()
                === ['In hand.' => 'Ongoing', 'Not this time.' => 'Declined']));
});

it('tells a member with nothing sent that there is nothing yet, rather than an error', function (): void {
    $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
    $tenant->domains()->create(['domain' => 'acme']);
    $user = User::factory()->create();
    enterTenant($tenant->id, $user->id);
    makeActiveMember($user, 'viewer');

    $this->actingAs($user)->get('http://acme.meridian.test/feedback/mine')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('data', 0)->where('empty_reason', 'no_rows'));
});

it('records the reporter\'s role when the report is sent, so the console can say who saw it', function (): void {
    $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
    $tenant->domains()->create(['domain' => 'acme']);
    $user = User::factory()->create();
    enterTenant($tenant->id, $user->id);
    makeActiveMember($user, 'form_editor');

    $this->actingAs($user)->post('http://acme.meridian.test/feedback', [
        'type' => 'bug',
        'route' => '/forms',
        'remarks' => 'Which role saw this?',
    ])->assertSessionHasNoErrors();

    enterTenant($tenant->id, $user->id);
    expect(FeedbackReport::query()->firstOrFail()->reporter_role)->toBe('form_editor');
});
