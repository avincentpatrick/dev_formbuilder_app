<?php

declare(strict_types=1);

use App\Models\Notification;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Marking notifications read (Increment I4). Both writes answer 204 and are called through
| `builderClient`, not as Inertia visits — see the route block in `routes/tenant.php` for why.
|
| THE TWO ASSERTIONS THAT MATTER ARE THE NEGATIVE ONES. `notifications` is strict RLS, which scopes to
| the TENANT and stops there, so route-model binding resolves a colleague's row and an unscoped mark-all
| UPDATE rewrites the whole tenant's read state. `NotificationPolicy::markRead` and the `forUser()`
| predicate in `readAll()` are the only two things standing in the way, and each gets a test that would
| pass if the guard were merely present and fail the moment it is removed.
|
| TRAPS: the tenant GUC dies with every HTTP request, so RLS-invisible reads as ABSENT — re-`enterTenant()`
| before asserting on a row. Every membership is minted BEFORE the first request (`tenant_users` is strict
| RLS, so a `makeActiveMember()` between two calls fails the INSERT policy).
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->tenant = inboxTenant();
    $this->owner = User::factory()->create();
    enterTenant($this->tenant->id, $this->owner->id);
    makeActiveMember($this->owner, 'owner');
});

afterEach(fn () => app(PermissionRegistrar::class)->setPermissionsTeamId(null));

function markReadUrl(string $id): string
{
    return 'http://acme.meridian.test/notifications/'.$id.'/read';
}

function markAllReadUrl(): string
{
    return 'http://acme.meridian.test/notifications/read-all';
}

it('stamps read_at on my own row', function (): void {
    $row = Notification::factory()->create(['user_id' => $this->owner->id]);

    $this->actingAs($this->owner)->postJson(markReadUrl((string) $row->getKey()))->assertNoContent();

    enterTenant($this->tenant->id, $this->owner->id);
    expect($row->refresh()->read_at)->not->toBeNull();
});

it('refuses to mark a colleague\'s row read', function (): void {
    $bob = User::factory()->create();
    makeActiveMember($bob, 'admin');

    $bobsRow = Notification::factory()->create(['user_id' => $bob->id]);

    // Binding resolves the row — it is in the same tenant, and RLS has no opinion about which member owns
    // it. The policy is the whole of the defence.
    $this->actingAs($this->owner)->postJson(markReadUrl((string) $bobsRow->getKey()))->assertForbidden();

    enterTenant($this->tenant->id, $this->owner->id);
    expect($bobsRow->refresh()->read_at)->toBeNull();
});

it('404s a row that belongs to another tenant', function (): void {
    $beta = inboxTenant('beta');
    $stranger = User::factory()->create();

    enterTenant($beta->id, $stranger->id);
    $foreign = Notification::factory()->create(['user_id' => $stranger->id]);

    enterTenant($this->tenant->id, $this->owner->id);

    $this->actingAs($this->owner)->postJson(markReadUrl((string) $foreign->getKey()))->assertNotFound();
});

it('does not move read_at on an already-read row', function (): void {
    $row = Notification::factory()->read()->create(['user_id' => $this->owner->id]);
    $originally = $row->read_at;

    // The bell writes optimistically, so a double click (or a second tab) arrives here twice. Without the
    // guard the timestamp would drift and `updated_at` would churn on a table something polls every minute.
    $this->actingAs($this->owner)->postJson(markReadUrl((string) $row->getKey()))->assertNoContent();

    enterTenant($this->tenant->id, $this->owner->id);
    expect($row->refresh()->read_at?->toIso8601String())->toBe($originally?->toIso8601String());
});

it('marks all of MINE read and none of a colleague\'s', function (): void {
    $bob = User::factory()->create();
    makeActiveMember($bob, 'admin');

    Notification::factory()->count(3)->create(['user_id' => $this->owner->id]);
    Notification::factory()->count(2)->create(['user_id' => $bob->id]);

    $this->actingAs($this->owner)->postJson(markAllReadUrl())->assertNoContent();

    // The mutation that would pass without `forUser()` is exactly this one: an unscoped UPDATE silently
    // clears the whole tenant's inboxes, and nothing audits it.
    enterTenant($this->tenant->id, $this->owner->id);
    expect(Notification::query()->forUser($this->owner)->unread()->count())->toBe(0)
        ->and(Notification::query()->forUser($bob)->unread()->count())->toBe(2);
});

it('is a clean no-op when nothing is unread', function (): void {
    Notification::factory()->read()->create(['user_id' => $this->owner->id]);

    $this->actingAs($this->owner)->postJson(markAllReadUrl())->assertNoContent();
});

it('lets a Viewer mark their own notification read', function (): void {
    // No permission gate anywhere on this surface, proven from the least-privileged role in the catalog.
    $viewer = User::factory()->create();
    makeActiveMember($viewer, 'viewer');

    $row = Notification::factory()->create(['user_id' => $viewer->id]);

    $this->actingAs($viewer)->postJson(markReadUrl((string) $row->getKey()))->assertNoContent();
    $this->actingAs($viewer)->postJson(markAllReadUrl())->assertNoContent();
});

it('refuses a guest', function (): void {
    $row = Notification::factory()->create(['user_id' => $this->owner->id]);

    $this->postJson(markReadUrl((string) $row->getKey()))->assertUnauthorized();
    $this->postJson(markAllReadUrl())->assertUnauthorized();
});
