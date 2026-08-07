<?php

declare(strict_types=1);

use App\Enums\SettingKey;
use App\Models\User;
use App\Services\Admin\SuperAdminService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| GET /admin/audit-log — the HTTP surface (Increment I7b).
|
| ⚠️ `$this->withoutVite()` on EVERY GET that expects a 200. The Pest job never runs `npm run build`, so
| `@vite` throws and the 200 arrives as a 500 — locally it passes only when `public/build` happens to
| exist. Two I5 cases shipped without it and only CI ever saw the failure.
|
| The fixtures are COMMITTED (the elevated connection cannot see RefreshDatabase's transaction) and purged
| through `beforeApplicationDestroyed`, which Laravel runs AFTER that transaction is rolled back.
*/

$platformUrl = fn (string $path = ''): string => 'http://'.config('tenancy.central_domain')."/admin/audit-log{$path}";

beforeEach(function (): void {
    TenantContext::flush();
    clearPlatformRows();
    $this->beforeApplicationDestroyed(purgeCommittedPlatformAuditFixtures(...));
});

afterEach(function (): void {
    clearPlatformRows();
});

it('lets a super-admin with confirmed 2FA read the platform ledger', function () use ($platformUrl): void {
    $this->withoutVite();
    $admin = User::factory()->superAdmin()->confirmedTwoFactor()->create();

    $this->actingAs($admin)->get($platformUrl())
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('admin/AuditLog', false));
});

it('redirects a super-admin without confirmed 2FA to enrollment', function () use ($platformUrl): void {
    $admin = User::factory()->superAdmin()->create();

    $this->actingAs($admin)->get($platformUrl())->assertRedirect(route('admin.mfa.setup'));
});

it('404s the platform ledger for an authenticated non-super-admin', function () use ($platformUrl): void {
    // 404, not 403 — non-disclosure. The console must not confirm its own existence.
    $this->actingAs(User::factory()->create())->get($platformUrl())->assertNotFound();
});

it('redirects a guest to login', function () use ($platformUrl): void {
    $this->get($platformUrl())->assertRedirect();
});

it('renders every prop the page binds to, key by key', function () use ($platformUrl): void {
    $this->withoutVite();
    $operator = committedSuperAdmin('props@platformaudittest.local', 'Props Operator');
    committedAudit(null, $operator->id, 'settings', 'updated', ['registration.open_signup' => false]);

    $admin = User::factory()->superAdmin()->confirmedTwoFactor()->create();

    // Key by key, never `has('data')` alone — the PlatformSettingsConsoleTest discipline. A prop bag that
    // merely EXISTS is what lets a renamed key ship green.
    $this->actingAs($admin)->get($platformUrl())
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/AuditLog', false)
            ->has('data', 1)
            ->where('data.0.event', 'updated')
            ->where('data.0.event_label', 'Updated')
            ->where('data.0.actor', 'Props Operator')
            ->where('data.0.is_system', false)
            ->where('data.0.target.type', 'settings')
            ->where('data.0.target.type_label', 'Settings')
            ->where('data.0.target.label', null)
            ->where('data.0.target.url', null)
            ->has('data.0.created_at')
            ->has('data.0.ip_address')
            ->has('data.0.changes')
            ->has('data.0.redacted_fields')
            ->where('meta.per_page', 25)
            ->where('meta.current_page', 1)
            ->has('filters.actors')
            ->where('filters.applied.user_id', null)
            ->where('filters.applied.from', null)
            ->where('filters.applied.to', null)
            ->where('empty_reason', null)
        );
});

it('never shows a tenant-scoped audit row on the platform console', function () use ($platformUrl): void {
    // ⭐ The end-to-end statement of this increment, and the HTTP twin of PlatformAuditRlsTest's
    // load-bearing case: the operator sees their own platform action and NOT the workspace's history.
    $this->withoutVite();
    $tenant = committedPlatformTenant('platform-audit-http');
    $operator = committedSuperAdmin('http@platformaudittest.local');
    $platformRow = committedAudit(null, $operator->id);
    committedAudit($tenant->id, $operator->id, 'form');

    $admin = User::factory()->superAdmin()->confirmedTwoFactor()->create();

    $this->actingAs($admin)->get($platformUrl())
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('data', 1)
            ->where('data.0.id', $platformRow)
        );
});

it('closes the loop: a real platform settings write appears on the console', function () use ($platformUrl): void {
    // The only test that would catch a drift between what I5 WRITES and what I7b FILTERS ON. Everything
    // else in this suite seeds its own rows and would stay green if the two ever diverged.
    $this->withoutVite();
    $operator = committedSuperAdmin('loop@platformaudittest.local');

    app(SuperAdminService::class)->updatePlatformSettings([
        SettingKey::RegistrationOpenSignup->value => false,
    ], $operator);

    $admin = User::factory()->superAdmin()->confirmedTwoFactor()->create();

    $this->actingAs($admin)->get($platformUrl())
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('data', 1)
            ->where('data.0.target.type', 'settings')
            ->where('data.0.changes.0.key', 'registration.open_signup')
        );
});

it('says the ledger is empty rather than that something is wrong', function () use ($platformUrl): void {
    $this->withoutVite();
    $admin = User::factory()->superAdmin()->confirmedTwoFactor()->create();

    // A fresh install genuinely has no platform rows until somebody saves on /admin/settings. `no_rows`
    // is the real state; the page copy says so.
    $this->actingAs($admin)->get($platformUrl())
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('empty_reason', 'no_rows')->has('data', 0));
});

it('offers no export', function (): void {
    // Pins the deferral so a later export is a decision rather than drift. `can.export` is deliberately
    // absent from the prop bag too — shipping the key would imply a route that does not exist.
    expect(Route::has('admin.audit-log.export'))->toBeFalse();
});

it('accepts a bookmarked URL naming filters it no longer honours', function () use ($platformUrl): void {
    // Every rule is `sometimes` and the dropped filters have no rule at all, so unknown query keys are
    // ignored. Guards against someone adding `Rule::in` or `prohibited` and turning a stale bookmark into
    // a 422 — which on an Inertia GET redirects "back", i.e. nowhere.
    $this->withoutVite();
    $admin = User::factory()->superAdmin()->confirmedTwoFactor()->create();

    $this->actingAs($admin)->get($platformUrl('?event=created&auditable_type=form&page=1'))->assertOk();
});

it('rejects a malformed operator filter rather than 500ing on the uuid column', function () use ($platformUrl): void {
    $this->withoutVite();
    $admin = User::factory()->superAdmin()->confirmedTwoFactor()->create();

    // `users.id` is a uuid column, so an unvalidated value would reach Postgres as `where user_id =
    // 'garbage'` and raise SQLSTATE 22P02. The `uuid` rule is what keeps this a redirect, not a 500.
    $this->actingAs($admin)->get($platformUrl('?user_id=garbage'))->assertSessionHasErrors('user_id');
});
