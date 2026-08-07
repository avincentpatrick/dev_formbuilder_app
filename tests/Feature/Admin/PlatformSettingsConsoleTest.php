<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\Settings\PlatformSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The super-admin platform-settings console (Increment I5, PRD Feature #10) — the HTTP surface.
|
| The gates are the same three the rest of the console carries and are asserted here rather than assumed:
| `superadmin` 404s a non-operator (non-disclosure, not 403), and `superadmin.mfa` sends an un-enrolled
| operator to enrollment. The DB-level behaviour of the write itself lives in PlatformSettingsWriteTest —
| this file is about who can reach the page and what it renders.
|
| GETs render the Inertia root view, so withoutVite() (the CI tests job builds no assets).
*/

$adminUrl = fn (string $path): string => "http://meridian.test/admin{$path}";

beforeEach(function (): void {
    DB::connection('pgsql_privileged')->table('settings')->whereNull('tenant_id')->delete();
    app(PlatformSettings::class)->forget();

    // I8a — the console carries `step-up`; without a fresh confirmation every request here 302s to
    // /user/confirm-password instead of rendering. See tests/Pest.php.
    confirmPasswordNow();
});

afterEach(function (): void {
    DB::connection('pgsql_privileged')->table('settings')->whereNull('tenant_id')->delete();
});

it('renders the platform settings page with resolved defaults', function () use ($adminUrl): void {
    $this->withoutVite();
    $admin = User::factory()->superAdmin()->confirmedTwoFactor()->create();

    $this->actingAs($admin)->get($adminUrl('/settings'))->assertOk()->assertInertia(fn ($page) => $page
        ->component('admin/Settings', false)
        // No rows exist: the sparse table's defaults have to reach the wire.
        ->where('settings.signup_open', true)
        ->where('settings.maintenance_enabled', false)
        // The RAW stored message, not the resolved one — this is an EDITOR, and pre-filling it with the
        // product's fallback copy would turn "I have no message" into a message nobody wrote the moment
        // the operator pressed Save.
        ->where('settings.maintenance_message', '')
        ->has('about.version')
    );
});

it('404s the page for an authenticated non-super-admin (non-disclosure)', function () use ($adminUrl): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get($adminUrl('/settings'))->assertNotFound();
    $this->actingAs($user)->patch($adminUrl('/settings'), [
        'signup_open' => false,
        'maintenance_enabled' => false,
        'maintenance_message' => '',
    ])->assertNotFound();
});

it('sends a super-admin without confirmed 2FA to enrollment', function () use ($adminUrl): void {
    $admin = User::factory()->superAdmin()->create();

    $this->actingAs($admin)->get($adminUrl('/settings'))->assertRedirect(route('admin.mfa.setup'));
});

it('redirects a guest to login', function () use ($adminUrl): void {
    $this->get($adminUrl('/settings'))->assertRedirect();
});

it('requires every field on the write — three fields, one operational stance', function () use ($adminUrl): void {
    $admin = User::factory()->superAdmin()->confirmedTwoFactor()->create();

    // A partial write would take the whole product offline behind a stale notice, which is why this page
    // has one Save button and no autosave.
    $this->actingAs($admin)->patch($adminUrl('/settings'), ['maintenance_enabled' => true])
        ->assertSessionHasErrors(['signup_open', 'maintenance_message']);
});
