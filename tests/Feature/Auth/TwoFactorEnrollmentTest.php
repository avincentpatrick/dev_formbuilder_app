<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Two-factor enrolment (Increment I8a, PRD Feature #14) — the FIRST coverage of any /user/two-factor-*
| route in this repository, and it exists because a live defect had been sitting behind that absence.
|--------------------------------------------------------------------------
| ⚠️ THE DEFECT: `config/fortify.php` enables the twoFactorAuthentication feature with
| `'confirmPassword' => true`, which makes Fortify apply `password.confirm` to all seven of its 2FA
| endpoints. TwoFactorSetup.vue reads two of them — the QR and the recovery codes — as JSON sidecars, and
| Illuminate\Auth\Middleware\RequirePassword forks on `expectsJson()`: a navigation is REDIRECTED, but a
| `fetch` with `Accept: application/json` gets a bare **423 with a JSON body**. The component read
| `res.json()` unconditionally, so a lapsed confirmation window rendered a BLANK QR under copy saying
| "scan this", with no error anywhere. On the super-admin enrolment page that is a lockout, because
| `superadmin.mfa` allows no other console route.
|
| What is pinned here, in the order the fix depends on it:
|   1. the endpoints really do answer 423 to a JSON request with a stale confirmation (if Fortify ever
|      changes that, the whole `needs_password_confirmation` prop becomes dead weight and should go);
|   2. both host pages ship the prop, and ship it TRUE when stale and FALSE when fresh;
|   3. the enrol → confirm → recovery-codes round trip works with a fresh confirmation.
|
| `withoutVite()` on every Inertia-asserting GET: the Pest CI job builds no assets, and without it the
| 200 arrives as a 500 that reports itself as "Not a valid Inertia response".
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

it('answers a JSON sidecar with 423 when the password confirmation is stale', function (): void {
    // This is the fork the whole fix turns on. A navigation would be redirected; a sidecar is refused
    // in-band, which is why the component cannot discover the guard from the response alone.
    $this->actingAs($this->owner)
        ->getJson('http://acme.meridian.test/user/two-factor-qr-code')
        ->assertStatus(423);

    $this->actingAs($this->owner)
        ->getJson('http://acme.meridian.test/user/two-factor-recovery-codes')
        ->assertStatus(423);
});

it('redirects a NAVIGATION to the confirm-password page rather than refusing it', function (): void {
    // The other arm of the same fork, pinned so a future reader can see why one surface needed a prop
    // and the rest of the application did not.
    $this->actingAs($this->owner)
        ->post('http://acme.meridian.test/user/two-factor-authentication')
        ->assertRedirect('http://acme.meridian.test/user/confirm-password');
});

it('tells the tenant settings page that the password confirmation is stale', function (): void {
    $this->withoutVite();

    $this->actingAs($this->owner)
        ->get('http://acme.meridian.test/settings')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Settings/Index', false)
            ->where('twoFactor.needs_password_confirmation', true)
        );
});

it('tells the tenant settings page when the confirmation is fresh', function (): void {
    $this->withoutVite();
    confirmPasswordNow();

    $this->actingAs($this->owner)
        ->get('http://acme.meridian.test/settings')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Settings/Index', false)
            ->where('twoFactor.needs_password_confirmation', false)
        );
});

it('tells the super-admin enrolment page that the confirmation is stale', function (): void {
    // The highest-stakes instance: this page is the ONLY route `superadmin.mfa` lets an un-enrolled
    // operator reach, so a blank QR here is a lockout rather than an inconvenience.
    $this->withoutVite();

    $superAdmin = User::factory()->create(['is_super_admin' => true]);

    $this->actingAs($superAdmin)
        ->get('http://meridian.test/admin/two-factor')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/TwoFactorSetup', false)
            ->where('needsPasswordConfirmation', true)
            ->where('enabled', false)
            ->where('confirmed', false)
        );
});

it('enrols, confirms and issues recovery codes once the password is confirmed', function (): void {
    confirmPasswordNow();

    $this->actingAs($this->owner)
        ->post('http://acme.meridian.test/user/two-factor-authentication')
        ->assertRedirect();

    $this->owner->refresh();
    expect($this->owner->two_factor_secret)->not->toBeNull();
    expect($this->owner->two_factor_confirmed_at)->toBeNull();

    // The two sidecars now succeed and return the shapes the component destructures.
    $qr = $this->actingAs($this->owner)
        ->getJson('http://acme.meridian.test/user/two-factor-qr-code')
        ->assertOk()
        ->json();
    expect($qr)->toHaveKey('svg');
    expect($qr['svg'])->toContain('<svg');

    $codes = $this->actingAs($this->owner)
        ->getJson('http://acme.meridian.test/user/two-factor-recovery-codes')
        ->assertOk()
        ->json();
    expect($codes)->toBeArray()->toHaveCount(8);

    // Confirm with a real code derived from the stored secret — the enrolment is not complete until this
    // succeeds, and `two_factor_confirmed_at` is what `superadmin.mfa` and I8a's org-wide gate both read.
    // Decrypt through Fortify's own encrypter accessor, exactly as TwoFactorAuthenticatable does — the
    // column carries no Eloquent cast, so a bare decrypt() would be a second guess at the same question.
    $secret = Fortify::currentEncrypter()->decrypt($this->owner->two_factor_secret);
    $code = app(Google2FA::class)->getCurrentOtp($secret);

    $this->actingAs($this->owner)
        ->post('http://acme.meridian.test/user/confirmed-two-factor-authentication', ['code' => $code])
        ->assertRedirect();

    $this->owner->refresh();
    expect($this->owner->two_factor_confirmed_at)->not->toBeNull();
});
