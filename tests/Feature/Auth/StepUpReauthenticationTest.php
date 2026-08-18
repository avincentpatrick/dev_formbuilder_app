<?php

declare(strict_types=1);

use App\Http\Middleware\RequireRecentPassword;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Http\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Step-up re-authentication (Increment I8a) — PRD Feature #14's third acceptance criterion:
| "Step-up re-authentication gates high-blast-radius actions (ownership transfer, role changes, billing
| changes, and the super-admin console) — a recent credential/2FA confirmation is required, via Laravel's
| `password.confirm` mechanism, not just a live session."
|--------------------------------------------------------------------------
| Four route groups carry the `step-up` alias ({@see App\Http\Middleware\RequireRecentPassword}), and each
| is asserted here from BOTH sides — refused when stale, allowed when fresh. A one-sided test would pass
| against a middleware that refuses unconditionally, which is a broken product rather than a secure one.
|
| ⚠️ THE WINDOW IS ITS OWN TEST. `auth.step_up_timeout` (15 min) is deliberately NARROWER than the
| framework's `auth.password_timeout` (3 h) that Fortify's own 2FA routes use. A confirmation from 20
| minutes ago satisfies the second and must NOT satisfy the first — if RequireRecentPassword ever stops
| passing its config value down, every gate here silently widens by a factor of twelve and nothing else
| in the suite would notice.
|
| ⚠️ WHAT IS DELIBERATELY NOT GATED, and asserted so it stays that way: `GET /members` (a read — a
| password prompt in front of looking at a roster trains people to type their password on reflex) and
| `GET /admin/two-factor` (the enrollment landing, outside `superadmin.mfa` for anti-loop reasons that
| step-up would defeat from the other direction).
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

    $this->member = User::factory()->create();
    enterTenant($this->tenant->id, $this->member->id);
    makeActiveMember($this->member, 'viewer');

    Tenant::query()->whereKey($this->tenant->id)->update(['owner_user_id' => $this->owner->id]);

    enterTenant($this->tenant->id, $this->owner->id);
});

afterEach(fn () => app(PermissionRegistrar::class)->setPermissionsTeamId(null));

function tenantUrl(string $path): string
{
    return 'http://acme.meridian.test'.$path;
}

it('refuses every gated tenant mutation with a stale confirmation', function (string $method, string $path, array $payload): void {
    // The dataset is evaluated before beforeEach runs, so the member id is substituted here rather than
    // baked into the cases.
    $path = str_replace('{member}', (string) $this->member->id, $path);

    $this->actingAs($this->owner)
        ->{$method}(tenantUrl($path), $payload)
        ->assertRedirect(tenantUrl('/user/confirm-password'));
})->with([
    'ownership transfer' => ['post', '/members/ownership', ['user' => '00000000-0000-7000-8000-000000000000']],
    'role change' => ['patch', '/members/{member}/role', ['role' => 'reviewer']],
    'member removal' => ['delete', '/members/{member}', []],
]);

it('allows the same mutations once the password is freshly confirmed', function (): void {
    confirmPasswordNow(0);

    // Not asserting the OUTCOME here — only that step-up let the request through to the controller.
    // A 302 back (success or a domain refusal) is past the gate; a 302 to confirm-password is not.
    $this->actingAs($this->owner)
        ->patch(tenantUrl("/members/{$this->member->id}/role"), ['role' => 'reviewer'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    enterTenant($this->tenant->id, $this->owner->id);
    $this->member->refresh();
    expect($this->member->getRoleNames()->first())->toBe('reviewer');
});

it('honours the 15-minute window, not the three-hour password_timeout', function (): void {
    // 20 minutes: comfortably inside auth.password_timeout (10800s) and outside auth.step_up_timeout (900s).
    // This is the assertion that fails if RequireRecentPassword stops passing its config value down.
    confirmPasswordNow(20 * 60);

    $this->actingAs($this->owner)
        ->patch(tenantUrl("/members/{$this->member->id}/role"), ['role' => 'reviewer'])
        ->assertRedirect(tenantUrl('/user/confirm-password'));

    // And the same session is still fresh enough for Fortify's own 2FA endpoints, which use the WIDER
    // window — proving the two are genuinely independent rather than one value read twice. Asserting
    // "not 423" rather than a specific status: what matters is that RequirePassword let it past, and
    // whatever Fortify then does with an un-enrolled user is Fortify's business, not this test's.
    $wider = $this->actingAs($this->owner)->getJson(tenantUrl('/user/two-factor-qr-code'));

    expect($wider->getStatusCode())->not->toBe(423);
});

it('gates exactly the intended routes and no others', function (): void {
    // A MANIFEST assertion rather than an HTTP round trip, for two reasons. It states the whole policy in
    // one place — including the NEGATIVE half, which an HTTP test can only express one route at a time —
    // and it cannot be satisfied accidentally by a route that happens to 302 for an unrelated reason.
    // (The roster read is also awkward to exercise over HTTP here: listMembers() resolves identities on
    // the separate pgsql_auth session, which cannot see RefreshDatabase's uncommitted users. That is
    // MembersIndexTest's job, and it seeds committed fixtures for it.)
    // Routes carry the ALIAS, so that is what is asserted per route — plus one assertion that the alias
    // still means what the rest of this file assumes. Split deliberately: re-pointing `step-up` at some
    // permissive class would leave every per-route check green, and that second assertion is the one that
    // would catch it.
    expect(app(Kernel::class)->getMiddlewareAliases()['step-up'] ?? null)
        ->toBe(RequireRecentPassword::class);

    $gated = static fn (string $name): bool => in_array(
        'step-up',
        Route::getRoutes()->getByName($name)?->gatherMiddleware() ?? [],
        strict: true,
    );

    // Gated: the three tenant mutations PRD #14 names, plus every page of the console.
    expect($gated('members.role'))->toBeTrue();
    expect($gated('members.remove'))->toBeTrue();
    expect($gated('members.ownership'))->toBeTrue();
    // P1a — rewriting a tenant's IdP signing certificates is a COMPLETE authentication takeover for that
    // tenant (the create_sso_connections_table docblock says so in as many words), which is a larger blast
    // radius than any of the three above. Only the import is gated: not the read, and not the status
    // toggle or the delete, which ADR-0016 §D5 keeps reachable for a downgraded tenant.
    expect($gated('settings.sso.metadata'))->toBeTrue();
    expect($gated('admin.tenants.assign-plan'))->toBeTrue();
    expect($gated('admin.tenants.index'))->toBeTrue();
    expect($gated('admin.settings.update'))->toBeTrue();

    // NOT gated, and each absence is a decision:
    //  · members.index / members.invite — a read, and an invitation that creates no authority until it is
    //    accepted. A prompt in front of either trains people to type their password on reflex.
    //  · admin.mfa.setup — the enrollment landing, outside `superadmin.mfa` for anti-loop reasons that
    //    step-up would defeat from the other direction.
    //  · notifications.index — I4's JSON sidecar. RequirePassword answers `Accept: application/json`
    //    with a bare 423, so gating it would break the bell rather than protect anything.
    expect($gated('members.index'))->toBeFalse();
    expect($gated('members.invite'))->toBeFalse();
    expect($gated('admin.mfa.setup'))->toBeFalse();
    expect($gated('notifications.index'))->toBeFalse();
});

it('gates the super-admin console but never its enrollment landing', function (): void {
    $this->withoutVite();

    $superAdmin = User::factory()->confirmedTwoFactor()->create(['is_super_admin' => true]);

    // Inside `superadmin.mfa`, so this is the console proper: refused while the confirmation is stale.
    $this->actingAs($superAdmin)
        ->get('http://meridian.test/admin/tenants')
        ->assertRedirect('http://meridian.test/user/confirm-password');

    // Outside both gates. If step-up ever creeps onto the outer group, an operator who has to enroll
    // AND confirm can reach neither — this is the assertion that catches it.
    $this->actingAs($superAdmin)
        ->get('http://meridian.test/admin/two-factor')
        ->assertOk();

    confirmPasswordNow(0);

    $this->actingAs($superAdmin)
        ->get('http://meridian.test/admin/tenants')
        ->assertOk();
});

it('bounces an un-enrolled super-admin to enrollment rather than to a password prompt', function (): void {
    // Order matters: `superadmin.mfa` runs BEFORE `step-up`, so an operator who has done neither is sent
    // to enrollment first. Reversed, they would confirm a password and be bounced anyway — a prompt that
    // accomplished nothing, which is how a security control teaches people to click through it.
    $unenrolled = User::factory()->create(['is_super_admin' => true]);

    $this->actingAs($unenrolled)
        ->get('http://meridian.test/admin/tenants')
        ->assertRedirect('http://meridian.test/admin/two-factor');
});
