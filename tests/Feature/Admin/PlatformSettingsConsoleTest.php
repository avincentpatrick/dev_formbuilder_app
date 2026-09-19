<?php

declare(strict_types=1);

use App\Enums\SettingKey;
use App\Models\User;
use App\Services\Admin\SuperAdminService;
use App\Services\Settings\PlatformSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
    // `clearPlatformRows()` rather than a settings-only delete: the write path files a NULL-tenant
    // AUDIT row on the elevated connection in its own transaction, so it OUTLIVES RefreshDatabase and
    // leaks into the next test's ledger assertions (M103).
    clearPlatformRows();
    app(PlatformSettings::class)->forget();

    // I8a — the console carries `step-up`; without a fresh confirmation every request here 302s to
    // /user/confirm-password instead of rendering. See tests/Pest.php.
    confirmPasswordNow();
});

afterEach(function (): void {
    clearPlatformRows();
});

/**
 * A COMMITTED super-admin with 2FA confirmed — the console's WRITE path needs both halves.
 *
 * ⛔ `User::factory()->superAdmin()->confirmedTwoFactor()->create()` is enough to REACH the page and is
 * what every read case above uses, but it cannot complete a save: `settings.updated_by` is a real FK to
 * `users`, the write runs on the separate `pgsql_superadmin` session, and an uncommitted factory row is
 * invisible there — so the FK turns that invisibility into a 23503 rather than into a null, exactly as
 * tests/Pest.php's `committedSuperAdmin()` docblock warns.
 *
 * ⚠️ NAMED `committedPlatformOperator`, NOT `committedConsoleOperator`. That name is already taken by
 * `tests/Feature/Auth/CentralHostLoginTest.php`, and because a fixture declared in a test file resolves
 * only when THAT file is loaded, the clash is invisible in a single-file run and a fatal
 * "Cannot redeclare" the moment both are in one Pest invocation — which is how M103 found it.
 *
 * ⚠️ `two_factor_secret` IS NULL ON PURPOSE, COPIED FROM THAT FILE'S REASONING RATHER THAN FROM ITS
 * NAME. Fortify's `hasEnabledTwoFactorAuthentication()` needs BOTH columns, so a null secret means no
 * TOTP challenge is ever issued, while `EnsureSuperAdminMfa` reads only the timestamp and lets the
 * console through. `UserFactory::confirmedTwoFactor()` writes a placeholder secret instead and, as that
 * docblock warns, would lock the account out permanently.
 *
 * ⚠️ RANDOM ADDRESS, AND DELIBERATELY NEVER DELETED. These rows are committed, so they outlive the
 * transaction; a fixed address would collide on `users.email` on the second run, and an afterEach DELETE
 * deadlocks against the locks the suite still holds. `migrate:fresh` is the cleaner.
 */
function committedPlatformOperator(): User
{
    $operator = committedSuperAdmin(Str::lower(Str::random(12)).'@platformconsoletest.local');

    // ⚠️ ON THE MODEL, NOT WITH A RAW TABLE UPDATE. A bare UPDATE stamps the row and leaves the
    // instance handed to `actingAs()` still reading `two_factor_confirmed_at` as null, so `superadmin.mfa`
    // redirects to enrollment — a 302 with no session errors and no toast, which reads as "the save did
    // nothing" rather than as a fixture fault.
    $operator->forceFill([
        'two_factor_secret' => null,
        'two_factor_confirmed_at' => now(),
    ])->save();

    // Back to the default connection once the committed row exists, the `committedMemberUser()` shape:
    // the acting user should not go on issuing privileged queries for the rest of the request.
    $operator->setConnection((string) config('database.default'));

    return $operator;
}

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
        // The optimistic-concurrency token the next Save must carry (M103, R-2173fe28).
        ->has('fingerprint')
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

/*
|--------------------------------------------------------------------------
| Increment M103 — R-2173fe28. The Save reported success identically whether or not anything changed,
| and the consequence was worse than the symptom: the console posts all three fields on every Save, so a
| tab opened before `registration.open_signup` was turned off silently turned it back ON, with the same
| toast and the same 303. That key is the ENTIRE enforcement of `D31` — "the testing server is
| invitation-only" is recorded as applied, not built — so a stale tab re-opened public registration on a
| box testers had been invited onto.
|
| ⚠️ `form.isDirty` IS NOT THE FIX AND THAT IS WHY THE TOKEN EXISTS. Inertia's dirty check compares
| against the page's OWN initial props, so the stale tab is legitimately dirty — it really did edit the
| notice — and posts the stale toggle anyway. Only a token carried from the read can tell a revert from
| an edit.
*/

it('refuses a save carrying a stale token, and leaves the setting alone', function () use ($adminUrl): void {
    $admin = User::factory()->superAdmin()->confirmedTwoFactor()->create();

    // The token this operator's page was rendered with, while signup was still open.
    app(PlatformSettings::class)->forget();
    $stale = app(PlatformSettings::class)->fingerprint();

    // Meanwhile somebody else closes signup — the D31 step, taken from another console session.
    $other = committedSuperAdmin('other@platformconsoletest.local');
    app(SuperAdminService::class)->updatePlatformSettings([
        SettingKey::RegistrationOpenSignup->value => false,
    ], $other, null);

    // The stale tab now saves an unrelated edit, carrying `signup_open: true` because that is what its
    // page was rendered with. Before M103 this answered 303 + "Platform settings saved".
    $this->actingAs($admin)->patch($adminUrl('/settings'), [
        'signup_open' => true,
        'maintenance_enabled' => false,
        'maintenance_message' => 'Back at 03:00 UTC.',
        'fingerprint' => $stale,
    ])->assertSessionHasErrors('fingerprint');

    app(PlatformSettings::class)->forget();
    expect(app(PlatformSettings::class)->signupOpen())->toBeFalse();
    // The whole write is refused, not partly applied — the unrelated edit does not land either.
    expect(app(PlatformSettings::class)->maintenanceMessage())
        ->not->toBe('Back at 03:00 UTC.');
});

it('requires the concurrency token, so an omitted one is refused rather than unchecked', function () use ($adminUrl): void {
    $admin = User::factory()->superAdmin()->confirmedTwoFactor()->create();

    // ⛔ THE POINT OF THIS CASE: a missing token must not mean "skip the check". That is the shape where
    // a gate reports success because nothing asked it anything.
    $this->actingAs($admin)->patch($adminUrl('/settings'), [
        'signup_open' => false,
        'maintenance_enabled' => false,
        'maintenance_message' => '',
    ])->assertSessionHasErrors('fingerprint');

    app(PlatformSettings::class)->forget();
    expect(app(PlatformSettings::class)->signupOpen())->toBeTrue();
});

it('saves when the token matches, and the toast names what actually changed', function () use ($adminUrl): void {
    $admin = committedPlatformOperator();

    app(PlatformSettings::class)->forget();
    $token = app(PlatformSettings::class)->fingerprint();

    $this->actingAs($admin)->patch($adminUrl('/settings'), [
        'signup_open' => false,
        'maintenance_enabled' => false,
        'maintenance_message' => '',
        'fingerprint' => $token,
    ])->assertSessionHasNoErrors()->assertSessionHas('toast.message', 'Saved — updated open signup');

    app(PlatformSettings::class)->forget();
    expect(app(PlatformSettings::class)->signupOpen())->toBeFalse();
});

it('tells the operator when a save changed nothing, and files no audit for it', function () use ($adminUrl): void {
    $admin = User::factory()->superAdmin()->confirmedTwoFactor()->create();

    app(PlatformSettings::class)->forget();
    $token = app(PlatformSettings::class)->fingerprint();

    // Every value posted is the value already resolved — the "I came to edit the notice and changed my
    // mind" Save, which used to be indistinguishable from turning public signup on.
    $this->actingAs($admin)->patch($adminUrl('/settings'), [
        'signup_open' => true,
        'maintenance_enabled' => false,
        'maintenance_message' => '',
        'fingerprint' => $token,
    ])->assertSessionHasNoErrors()->assertSessionHas('toast.message', 'No changes to save');

    // No row written, so the sparse table stays sparse and the ledger stays free of "nothing happened".
    expect(DB::connection('pgsql_privileged')->table('settings')->whereNull('tenant_id')->count())->toBe(0);
    expect(DB::connection('pgsql_privileged')->table('audits')
        ->whereNull('tenant_id')->where('auditable_type', 'settings')->count())->toBe(0);
});

it('ships a token with the page that the very next save accepts', function () use ($adminUrl): void {
    $this->withoutVite();
    $admin = committedPlatformOperator();

    // A round trip rather than two calls to fingerprint(): this is what proves the token the BROWSER is
    // given is the one the server will accept, which is the only claim worth making about it.
    $token = null;

    $this->actingAs($admin)->get($adminUrl('/settings'))->assertOk()->assertInertia(
        function ($page) use (&$token) {
            $token = $page->toArray()['props']['fingerprint'];

            return $page->component('admin/Settings', false)->has('fingerprint');
        }
    );

    expect($token)->toBeString()->not->toBe('');

    $this->actingAs($admin)->patch($adminUrl('/settings'), [
        'signup_open' => false,
        'maintenance_enabled' => false,
        'maintenance_message' => '',
        'fingerprint' => $token,
    ])->assertSessionHasNoErrors();
});
