<?php

declare(strict_types=1);

use App\Enums\PlanTier;
use App\Models\Release;
use App\Models\User;
use App\Support\Audit\ImpersonationContext;
use App\Support\Platform\WhatsNew;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| M181 (`R-c7247dbf`, `D125` C, `D126` A, `D157` A) — What's new: the page, the prompt, and what a person has seen
|--------------------------------------------------------------------------
| The user (2026-10-14): *"lets also have a what's new prompt to users for new login so users are aware for every
| changes"*, and *"i want the whats new to be seen right after logging in"*. The prompt is the shared `whatsNew` prop:
| the releases this person has not seen, newest first, or null. Any close marks it seen (`D126` A) through
| `POST /whats-new/seen`, which only ever moves `users.whats_new_seen_version` forward. A person who has never seen one
| gets every release since they joined, at least the newest (`D157` A). A support session never sees it, so an
| operator's close cannot use up the member's notice.
|
| ⚠️ `users` IS FORCE RLS: a write without the request's own user GUC lands ZERO rows and raises nothing, so every
| write below is proved by reading the row back, never by the status code.
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->tenant = inboxTenant();
    $this->owner = User::factory()->create(['created_at' => now()->subYear()]);
    enterTenant($this->tenant->id, $this->owner->id);
    makeActiveMember($this->owner, 'owner');
    assignPlanTier(PlanTier::Free);
});

afterEach(function (): void {
    ImpersonationContext::forget();
});

/** A release that went live `$daysAgo` days ago. */
function whatsNewRelease(string $version, int $daysAgo, string $title = 'A release'): Release
{
    return Release::query()->create([
        'version' => $version,
        'title' => $title,
        'items' => [['kind' => 'new', 'text' => "What changed in {$version}."]],
        'released_at' => now()->subDays($daysAgo),
    ]);
}

/**
 * The seen version as stored, read under the person's own user GUC — the one row security lets them see. (The
 * privileged connection cannot help here: it is a second connection, outside this test's open transaction.)
 */
function seenVersionOf(User $user): ?string
{
    enterTenant(test()->tenant->id, $user->id);

    return DB::table('users')->where('id', $user->id)->value('whats_new_seen_version');
}

function setSeenVersion(User $user, ?string $version): void
{
    enterTenant(test()->tenant->id, $user->id);

    expect(DB::table('users')->where('id', $user->id)->update(['whats_new_seen_version' => $version]))->toBe(1);
    $user->refresh();
}

/** @return list<string>|null the versions the prompt would list for `$user`, newest first */
function pendingVersions(User $user): ?array
{
    $pending = app(WhatsNew::class)->pending($user);

    return $pending === null ? null : array_column($pending['entries'], 'version');
}

it('opens the What\'s new page for every role, on the plan with the fewest features', function (string $role): void {
    whatsNewRelease('0.9.0', 3);
    $member = User::factory()->create();
    makeActiveMember($member, $role);

    $this->withoutVite()
        ->actingAs($member)
        ->get('http://acme.meridian.test/whats-new')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('whats-new/Index', false));
})->with(['owner', 'admin', 'form_editor', 'reviewer', 'viewer']);

it('sends a guest to sign in rather than showing the page', function (): void {
    $this->get('http://acme.meridian.test/whats-new')->assertRedirect('http://acme.meridian.test/login');
});

it('lists every release on the page, newest first by version, not by text', function (): void {
    whatsNewRelease('0.9.0', 30, 'First');
    whatsNewRelease('0.10.0', 1, 'Tenth');
    whatsNewRelease('0.9.1', 10, 'Patch');

    $this->withoutVite()
        ->actingAs($this->owner)
        ->get('http://acme.meridian.test/whats-new')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('whats-new/Index', false)
            ->has('releases', 3)
            ->where('releases.0.version', '0.10.0')
            ->where('releases.0.title', 'Tenth')
            ->where('releases.0.items.0.kind', 'new')
            ->where('releases.1.version', '0.9.1')
            ->where('releases.2.version', '0.9.0'));
});

it('prompts with the releases newer than the one this person last closed', function (): void {
    whatsNewRelease('0.9.0', 30);
    whatsNewRelease('0.9.1', 10);
    whatsNewRelease('0.10.0', 1);
    setSeenVersion($this->owner, '0.9.0');

    expect(pendingVersions($this->owner))->toBe(['0.10.0', '0.9.1']);
});

it('prompts with nothing once the newest release is seen', function (): void {
    whatsNewRelease('0.9.0', 30);
    setSeenVersion($this->owner, '0.9.0');

    expect(pendingVersions($this->owner))->toBeNull();
});

it('shows a person who has never seen one every release since they joined (D157)', function (): void {
    whatsNewRelease('0.8.0', 400);
    whatsNewRelease('0.9.0', 30);
    whatsNewRelease('0.10.0', 1);

    expect(pendingVersions($this->owner))->toBe(['0.10.0', '0.9.0']);
});

it('shows a brand-new person the newest release only', function (): void {
    whatsNewRelease('0.9.0', 30);
    whatsNewRelease('0.10.0', 1);
    $newcomer = User::factory()->create();
    makeActiveMember($newcomer, 'viewer');

    expect(pendingVersions($newcomer))->toBe(['0.10.0']);
});

it('prompts with nothing while there are no releases', function (): void {
    expect(pendingVersions($this->owner))->toBeNull();
});

it('prompts with nothing during a support session', function (): void {
    whatsNewRelease('0.9.0', 30);
    ImpersonationContext::set((string) User::factory()->create()->id);

    expect(pendingVersions($this->owner))->toBeNull();
});

it('prompts with nothing off-tenant', function (): void {
    whatsNewRelease('0.9.0', 30);
    TenantContext::flush();

    expect(pendingVersions($this->owner))->toBeNull();
});

it('shares the prompt with every page of the workspace, and nothing once it is seen', function (): void {
    whatsNewRelease('0.9.0', 30, 'Version numbers');

    $this->withoutVite()
        ->actingAs($this->owner)
        ->get('http://acme.meridian.test/dashboard')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('whatsNew.entries.0.version', '0.9.0')
            ->where('whatsNew.entries.0.title', 'Version numbers')
            ->has('whatsNew.entries.0.released_at')
            ->where('whatsNew.entries.0.items.0.text', 'What changed in 0.9.0.'));

    setSeenVersion($this->owner, '0.9.0');

    $this->withoutVite()
        ->actingAs($this->owner)
        ->get('http://acme.meridian.test/dashboard')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('whatsNew', null));
});

it('records what this person has seen when the prompt closes, under their own row security', function (): void {
    whatsNewRelease('0.9.0', 30);

    $this->actingAs($this->owner)
        ->from('http://acme.meridian.test/dashboard')
        ->post('http://acme.meridian.test/whats-new/seen', ['version' => '0.9.0'])
        ->assertRedirect('http://acme.meridian.test/dashboard');

    expect(seenVersionOf($this->owner))->toBe('0.9.0');
});

it('never moves what this person has seen backwards', function (): void {
    whatsNewRelease('0.9.0', 30);
    whatsNewRelease('0.10.0', 1);
    setSeenVersion($this->owner, '0.10.0');

    $this->actingAs($this->owner)
        ->from('http://acme.meridian.test/dashboard')
        ->post('http://acme.meridian.test/whats-new/seen', ['version' => '0.9.0'])
        ->assertRedirect('http://acme.meridian.test/dashboard');

    expect(seenVersionOf($this->owner))->toBe('0.10.0');
});

it('refuses to record a version that was never released', function (): void {
    whatsNewRelease('0.9.0', 30);

    $this->actingAs($this->owner)
        ->from('http://acme.meridian.test/dashboard')
        ->post('http://acme.meridian.test/whats-new/seen', ['version' => '9.9.9'])
        ->assertSessionHasErrors('version');

    expect(seenVersionOf($this->owner))->toBeNull();
});

it('refuses a guest\'s close', function (): void {
    $this->post('http://acme.meridian.test/whats-new/seen', ['version' => '0.9.0'])
        ->assertRedirect('http://acme.meridian.test/login');
});
