<?php

declare(strict_types=1);

use App\Enums\SubmissionStatus;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\Dashboard\DashboardMetricsService;
use App\Services\Forms\FormService;
use App\Services\Onboarding\GettingStartedChecklist;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| J5b — the dashboard's getting-started checklist.
|--------------------------------------------------------------------------
| Two things are being held here and only one of them is arithmetic.
|
| The arithmetic: done-ness comes from the SAME numbers the KPI tiles show, so a row cannot disagree with
| the tile directly above it (ADR-0011 §D2's defect, at one glance's distance rather than one page's).
|
| The other one is the reason this is a server-side service at all rather than a client `computed`: every
| row's href mirrors the gate its own destination carries, and a href resolved in the browser sits
| somewhere no Pest test can reach. That gap has already shipped a `/forms` crumb that 403'd for two roles
| (J2d), which is why `CrumbTrail` and `FormTabSet` both resolve server-side and both have a reachability
| suite. This is that suite for the checklist.
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

/** The rows for `$user`, built from the metrics their own dashboard would show. */
function checklistFor(User $user): ?array
{
    $metrics = app(DashboardMetricsService::class);

    return app(GettingStartedChecklist::class)->forUser($user, $metrics->forUser($user));
}

/** When this member dismissed the card, as a comparable string. See the idempotency test for why. */
function dismissedAtIso(User $user): ?string
{
    return TenantUser::query()
        ->where('user_id', $user->id)
        ->value('onboarding_dismissed_at')?->toIso8601String();
}

/** @param  list<array{key: string, done: bool, href?: string}>  $items */
function rowNamed(array $items, string $key): array
{
    $found = array_values(array_filter($items, static fn (array $item): bool => $item['key'] === $key));

    expect($found)->toHaveCount(1, "no checklist row keyed \"{$key}\"");

    return $found[0];
}

it('gives a brand-new Owner four rows, none done, each pointing somewhere', function (): void {
    $tenant = inboxTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    makeActiveMember($owner, 'owner');

    // One draft form, so the checklist renders at all — the page hides it below one form, where the
    // first-run moment says the same sentence. `create_form` is therefore always the tick, never the task.
    app(FormService::class)->create($tenant, $owner, 'Draft');

    $items = checklistFor($owner);

    expect($items)->toHaveCount(4);
    expect(array_column($items, 'key'))
        ->toBe(['create_form', 'publish_form', 'first_response', 'invite_teammate']);

    expect(rowNamed($items, 'create_form')['done'])->toBeTrue();
    expect(rowNamed($items, 'publish_form'))->toMatchArray(['done' => false, 'href' => '/forms']);
    expect(rowNamed($items, 'first_response'))->toMatchArray(['done' => false, 'href' => '/forms']);
    expect(rowNamed($items, 'invite_teammate'))->toMatchArray(['done' => false, 'href' => '/members']);
});

it('ticks publish, first response and invite off the same facts the tiles count', function (): void {
    $tenant = inboxTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    makeActiveMember($owner, 'owner');

    $form = publishedInboxForm($tenant, $owner, 'Intake');

    expect(rowNamed(checklistFor($owner), 'publish_form')['done'])->toBeTrue();
    expect(rowNamed(checklistFor($owner), 'first_response')['done'])->toBeFalse();
    expect(rowNamed(checklistFor($owner), 'invite_teammate')['done'])->toBeFalse();

    // ⚠️ THE TEAMMATE BEFORE THE RESPONSE, AND THE ORDER IS FORCED RATHER THAN STYLISTIC. Completing all
    // four rows makes the card DISAPPEAR (see the test below), so a sequence that finished them in the
    // reading order would be asserting through a null on its own last step — which is exactly how the
    // first draft of this test failed. One row is deliberately left outstanding until the end.
    $colleague = User::factory()->create();
    enterTenant($tenant->id, $colleague->id);
    makeActiveMember($colleague, 'viewer');
    enterTenant($tenant->id, $owner->id);

    expect(rowNamed(checklistFor($owner), 'invite_teammate')['done'])->toBeTrue();
    expect(rowNamed(checklistFor($owner), 'first_response')['done'])->toBeFalse();

    seedInboxSubmission($form, $owner, SubmissionStatus::Submitted, ['full_name' => 'Ada']);

    // The fourth tick takes the card with it.
    expect(checklistFor($owner))->toBeNull();
});

it('drops an href the moment its row is done, because there is nothing left to do on it', function (): void {
    $tenant = inboxTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    makeActiveMember($owner, 'owner');
    publishedInboxForm($tenant, $owner, 'Intake');

    expect(rowNamed(checklistFor($owner), 'publish_form'))->not->toHaveKey('href');
});

it('omits the invite row for a role that cannot invite, rather than showing it dead', function (): void {
    // ⭐ THE ONE ROW THAT IS ABSENT RATHER THAN UNLINKED, AND THE ASYMMETRY IS THE POINT. A row about the
    // workspace stays and loses its link; an INSTRUCTION nobody can carry out is not a degraded link, it is
    // a chore assigned to the wrong person. `MdsBreadcrumb`'s rule and `FormTabSet`'s rule, each applied
    // where it belongs.
    $tenant = inboxTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    makeActiveMember($owner, 'owner');

    $editor = User::factory()->create();
    enterTenant($tenant->id, $editor->id);
    makeActiveMember($editor, 'form_editor');
    app(FormService::class)->create($tenant, $editor, 'Mine');

    $keys = array_column(checklistFor($editor), 'key');

    expect($keys)->not->toContain('invite_teammate');
    expect($keys)->toContain('publish_form');
});

it('disappears once every row it would show is done', function (): void {
    // ⭐ THE ARM THAT LETS THE DISMISSAL COLUMN SHIP WITHOUT A BACKFILL. Every established workspace
    // already satisfies all four rows, so nobody who has used the product for a year is handed a fresh
    // card to close. A "you're all set" state instead would have needed one.
    $tenant = inboxTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    makeActiveMember($owner, 'owner');

    $form = publishedInboxForm($tenant, $owner, 'Intake');
    seedInboxSubmission($form, $owner, SubmissionStatus::Submitted, ['full_name' => 'Ada']);

    $colleague = User::factory()->create();
    enterTenant($tenant->id, $colleague->id);
    makeActiveMember($colleague, 'viewer');
    enterTenant($tenant->id, $owner->id);

    expect(checklistFor($owner))->toBeNull();
});

it('disappears for a member who dismissed it, with rows still outstanding', function (): void {
    $tenant = inboxTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    makeActiveMember($owner, 'owner');
    app(FormService::class)->create($tenant, $owner, 'Draft');

    expect(checklistFor($owner))->not->toBeNull();

    TenantUser::query()->where('user_id', $owner->id)->update(['onboarding_dismissed_at' => now()]);

    expect(checklistFor($owner))->toBeNull();
});

it('dismisses for the caller alone, and only in this workspace', function (): void {
    // ⭐ WHY THE FLAG IS ON `tenant_users` AND NOT ON `user_ui_preferences` (user decision 2026-08-17).
    // The personal preferences table carries no tenant_id on purpose, so a dismissal there would silence
    // the card in a workspace this person has not opened yet — including one where they are the founding
    // Owner and it is the only thing telling them what to do next.
    $tenant = inboxTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    makeActiveMember($owner, 'owner');
    app(FormService::class)->create($tenant, $owner, 'Draft');

    $colleague = User::factory()->create();
    enterTenant($tenant->id, $colleague->id);
    makeActiveMember($colleague, 'viewer');

    $this->actingAs($owner)
        ->post('http://acme.meridian.test/onboarding/dismiss')
        ->assertRedirect();

    enterTenant($tenant->id, $owner->id);

    expect(TenantUser::query()->where('user_id', $owner->id)->value('onboarding_dismissed_at'))->not->toBeNull();
    expect(TenantUser::query()->where('user_id', $colleague->id)->value('onboarding_dismissed_at'))->toBeNull();
});

it('does not move the timestamp on a second dismissal', function (): void {
    // The column answers *when did you dismiss this*, so a re-stamp makes that answer wrong. The
    // already-set guard is `NotificationController::read()`'s, for a smaller version of its reason.
    $tenant = inboxTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    makeActiveMember($owner, 'owner');

    $this->actingAs($owner)->post('http://acme.meridian.test/onboarding/dismiss')->assertRedirect();
    enterTenant($tenant->id, $owner->id);
    $first = dismissedAtIso($owner);

    $this->travel(5)->minutes();

    $this->actingAs($owner)->post('http://acme.meridian.test/onboarding/dismiss')->assertRedirect();
    enterTenant($tenant->id, $owner->id);

    // ⚠️ ISO STRINGS, NOT THE VALUES THEMSELVES. `Builder::value()` resolves through the model, so the
    // `datetime` cast applies and each read hands back a DIFFERENT Carbon instance — an identity
    // comparison fails against a perfectly correct implementation, which is what the first draft did.
    expect(dismissedAtIso($owner))->toBe($first);
});
