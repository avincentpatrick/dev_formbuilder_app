<?php

declare(strict_types=1);

use App\Enums\TenantUserStatus;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Support\Auth\PasswordPolicy;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\E2eSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The J3b accessibility-scan fixtures, and the published password policy reaching its four surfaces.
|
| ⚠️ THESE EXIST BECAUSE THE E2E SUITE CANNOT RUN ON THE DEVELOPMENT HOST. `tests/e2e/auth-axe.spec.ts`
| now navigates to two seeded URLs by literal string — an invite token and a password-reset token — and
| `E2eSeeder` is precisely where a mistake becomes "all 506 specs die before one runs" rather than one
| red test. J3a took the E2E job red exactly once, through this seeder. So every fixture those specs
| depend on is asserted HERE, where it can be run locally in seconds, before CI ever sees the spec.
|
| The prop half is the same argument from the other end: four pages render `MdsPasswordStrength`, and a
| page that renders it without the prop is a runtime failure in the browser that no PHP test would notice.
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

afterEach(fn () => app(PermissionRegistrar::class)->setPermissionsTeamId(null));

it('seeds an invite whose token is the one the accessibility spec navigates to', function (): void {
    // ⚠️ THE LITERAL BELOW IS DUPLICATED IN `tests/e2e/auth-axe.spec.ts` ON PURPOSE, AND THAT IS THE
    // POINT OF THIS CASE. A Playwright spec cannot import a PHP constant, so the token is a string in
    // two places; this asserts they are the same string. Without it, renaming the constant leaves the
    // spec navigating to a 404 that reports itself as an accessibility failure on a page that is not
    // the invitation page at all.
    (new E2eSeeder)->run();

    $tenant = Tenant::query()->where('slug', 'acme')->firstOrFail();

    $found = DB::transaction(function () use ($tenant): bool {
        TenantContext::applyLocal($tenant->id, null);

        return TenantUser::query()
            ->where('status', TenantUserStatus::Invited)
            ->where('invite_token', hash('sha256', 'e2e-invitation-token-do-not-reuse-in-production'))
            ->exists();
    });

    expect($found)->toBeTrue();
});

it('seeds a password-reset row that actually resolves the token the spec uses', function (): void {
    // The reset PAGE does not consult the token — Fortify validates on POST, not on GET — so the scan
    // would pass without this row. It is asserted anyway because the fixture claims to be honest: a
    // later test that submits the form must find a token that works, rather than discovering on the day
    // that the scan had been rendering over a dead link. `password_reset_tokens` carries no RLS and its
    // primary key is the EMAIL, which is why the seeder upserts rather than inserts.
    (new E2eSeeder)->run();

    $stored = DB::table('password_reset_tokens')->where('email', 'demo@meridian.test')->value('token');

    expect($stored)->not->toBeNull()
        ->and(Hash::check('e2e-password-reset-token', (string) $stored))->toBeTrue();
});

it('gives the unverified placeholder a password the spec can actually sign in with', function (): void {
    // It carried `Str::random(48)` until J3b, which is right for an identity nobody signs in as — and the
    // verify-email scan has to BE that person, because `/email/verify` sits behind `auth`.
    //
    // Read as ITSELF: `users_users_visibility` reveals a row only to the acting user or an ACTIVE
    // co-tenant, and this membership is Invited, so a plain tenant context cannot see it. The idiom is
    // `E2eSeederIdempotencyTest`'s, and `pgsql_auth`/`pgsql_privileged` are NOT usable here — separate
    // sessions cannot see RefreshDatabase's open transaction, so the assertion would pass vacuously.
    (new E2eSeeder)->run();

    $tenant = Tenant::query()->where('slug', 'acme')->firstOrFail();

    $hash = DB::transaction(function () use ($tenant): ?string {
        TenantContext::applyLocal($tenant->id, null);

        $pendingId = (string) TenantUser::query()
            ->where('status', TenantUserStatus::Invited)
            ->value('user_id');

        TenantContext::applyLocal($tenant->id, $pendingId);

        return User::query()->whereKey($pendingId)->value('password');
    });

    expect($hash)->not->toBeNull()
        ->and(Hash::check('meridian-e2e-2026', (string) $hash))->toBeTrue();
});

it('lands the seeded placeholder on the verification notice, which is where the scan expects them', function (): void {
    // ⚠️ THE SPEC CANNOT PROVE ITS OWN PREMISE AND THIS CAN. `auth-axe.spec.ts` signs in as this identity
    // and waits for `/email/verify`; if the request stopped anywhere else the wait would time out with
    // "Timeout 30000ms exceeded" and no indication of which of several gates had answered.
    //
    // The premise is not obvious, which is exactly why it is asserted: this identity's membership is
    // INVITED, not active, so it is NOT the case `EmailVerificationGateTest` covers. A non-member reaching
    // a tenant route could plausibly be answered by a membership check, a 403, or an empty workspace
    // before `verified` ever runs — and the spec would then be scanning whatever that produced.
    (new E2eSeeder)->run();

    $tenant = Tenant::query()->where('slug', 'acme')->firstOrFail();

    $pending = DB::transaction(function () use ($tenant): ?User {
        TenantContext::applyLocal($tenant->id, null);
        $id = (string) TenantUser::query()->where('status', TenantUserStatus::Invited)->value('user_id');
        TenantContext::applyLocal($tenant->id, $id);

        return User::query()->whereKey($id)->first();
    });

    expect($pending)->not->toBeNull();

    $this->actingAs($pending)
        ->get('http://acme.meridian.test/dashboard')
        ->assertRedirect(route('verification.notice'));
});

/*
| ⚠️ THE TWO-FACTOR PREMISE IS NOT ASSERTED HERE, AND THE REASON IS A TRAP WORTH THE PARAGRAPH.
|
| `auth-axe.spec.ts` signs in as `twofactor@meridian.test` and expects to be diverted to
| `/two-factor-challenge`. The obvious mirror — POST /login and assert the redirect — CANNOT WORK under
| `RefreshDatabase`, and it fails in a way that reads like an application bug rather than a test-harness
| limit: the login throws `ValidationException::withMessages()` from deep inside Fortify, which surfaces
| as "Call to a member function all() on array" pointing at the assertion line.
|
| The cause is the one PROGRESS.md already records for SSO provisioning, arriving from a new direction:
| `RlsAwareUserProvider::retrieveByCredentials()` resolves on `pgsql_auth`, a SEPARATE SESSION which
| cannot see RefreshDatabase's open transaction. The seeded identity therefore does not exist as far as
| the credential lookup is concerned, authentication legitimately fails, and the case would be measuring
| the failure path forever.
|
| ⚠️ NOTE THE ASYMMETRY THAT MAKES THIS EASY TO GET WRONG: the case above it, which drives the same
| seeder's PENDING user, passes — because `actingAs()` sets the user on the guard directly and never goes
| near `retrieveByCredentials()`. A mirror is available for one and not the other, and the difference is
| invisible from the test's shape.
|
| VERIFIED OUT-OF-TRANSACTION INSTEAD, and recorded rather than left implicit: a probe without
| `RefreshDatabase` (so the seeder's writes commit and `pgsql_auth` can see them) answered
| `302 → http://acme.meridian.test/two-factor-challenge`. That confirms both halves the spec depends on —
| that the secret was written by the seeder's single INSERT, and that no workspace membership is needed to
| reach the challenge. The probe is not kept as a test: it would commit fixture rows outside a
| transaction and leak them into every suite that ran afterwards.
*/

it('publishes the password policy to every page that renders the checklist', function (): void {
    // ⚠️ ASSERTED PER PAGE, NOT ONCE. `PasswordPolicy::requirements()` is pure and cannot fail; what CAN
    // fail is a render site that forgets to pass it, and each of these four is a separate closure or
    // controller. A page mounting `MdsPasswordStrength` without the prop is a runtime error in the
    // browser, which no PHP test would otherwise see.
    $this->withoutVite();

    $expected = PasswordPolicy::requirements();

    $this->get('http://meridian.test/register')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('auth/Register', false)
            ->where('passwordPolicy', $expected));

    $this->get('http://meridian.test/reset-password/any-token?email=nobody%40meridian.test')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('auth/ResetPassword', false)
            ->where('passwordPolicy', $expected));
});

it('keeps the breach row unevaluatable all the way to the page', function (): void {
    // The end of the chain the class docblock, `PasswordPolicyTest` and `password-policy.test.ts` all
    // defend from their own side: a null pattern must survive JSON serialization to the client. If it
    // ever arrives as `""`, `new RegExp('')` matches EVERYTHING and the browser ticks a rule it has not
    // checked and cannot check — the one failure mode worse than not showing the row at all.
    $this->withoutVite();

    $this->get('http://meridian.test/register')
        ->assertOk()
        ->assertInertia(function ($page) {
            $breach = collect($page->toArray()['props']['passwordPolicy'])->firstWhere('key', 'uncompromised');

            expect($breach)->not->toBeNull()
                ->and($breach['pattern'])->toBeNull();

            return $page;
        });
});
