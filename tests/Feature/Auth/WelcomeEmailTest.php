<?php

declare(strict_types=1);

use App\Actions\Fortify\UpdateUserProfileInformation;
use App\Enums\TenantUserStatus;
use App\Models\TenantUser;
use App\Models\User;
use App\Notifications\Auth\QueuedVerifyEmail;
use App\Notifications\Auth\WelcomeNotification;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The welcome email (Increment J3a) — the first message this product sends that asks for nothing.
|
| Everything else either wants an action (verify, reset, review) or reports a problem (a paused endpoint, a
| failed export). `app/Mail/TenantMail.php` has been an abstract class with zero subclasses across four
| increments that each expected to be its first consumer.
|
| ⚠️ THE DESIGN DECISION UNDER TEST IS THE EVENT, NOT THE COPY. Sent on `Verified`, never on `Registered` —
| because `Registered` already fires Laravel's own SendEmailVerificationNotification, so a welcome raised
| there arrives in the same inbox in the same second as the one message the person actually has to act on.
| Ordering the two listeners does not fix that; it only picks which is on top. The case named "queues the
| verification email and NO welcome" is the one that would go red if anyone moved it back.
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    Notification::fake();
});

afterEach(function (): void {
    DB::connection('pgsql_privileged')->table('users')->where('email', 'like', '%@welcometest.local')->delete();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

it('sends exactly one welcome when an address is verified', function (): void {
    $user = User::factory()->unverified()->create();

    event(new Verified($user));

    Notification::assertSentOnDemand(WelcomeNotification::class);
    Notification::assertSentOnDemandTimes(WelcomeNotification::class, 1);
});

it('never serializes a User, so a worker restores nothing under a null GUC', function (): void {
    // The §D5 hazard, and the reason every queued mail notification in this application is delivered to an
    // on-demand notifiable: `users` carries fail-closed join-shape RLS, so a User restored on a worker with
    // no tenant context simply is not found. `QueuedAuthMailTest` pins the same property for the two Fortify
    // emails; this is the third.
    $user = User::factory()->unverified()->create();

    event(new Verified($user));

    Notification::assertSentOnDemand(
        WelcomeNotification::class,
        function (WelcomeNotification $notification, array $channels, AnonymousNotifiable $notifiable) use ($user): bool {
            return $notifiable->routes['mail'] === $user->email;
        },
    );
});

it('names the workspace and points at ITS dashboard for an ACTUAL member', function (): void {
    $tenant = inboxTenant();
    $user = User::factory()->unverified()->create();

    // ⚠️ `makeActiveMember` IS THE POINT OF THIS LINE, AND A FIRST DRAFT OMITTED IT — which made this test
    // assert the defect the case below now catches. The listener must not infer membership from the host.
    enterTenant($tenant->id, $user->id);
    makeActiveMember($user, 'viewer');
    TenantContext::flush();

    $this->app['request']->headers->set('host', 'acme.meridian.test');
    $this->app['request']->server->set('HTTP_HOST', 'acme.meridian.test');

    event(new Verified($user));

    Notification::assertSentOnDemand(
        WelcomeNotification::class,
        function (WelcomeNotification $notification) use ($tenant): bool {
            return $notification->tenantName === $tenant->name
                && str_contains($notification->actionUrl, 'acme.meridian.test')
                && str_ends_with($notification->actionUrl, '/dashboard');
        },
    );
});

it('names no workspace on the central host, which is a real state and not a lookup failure', function (): void {
    // `RegistrationGate` documents it: an account created centrally belongs to no workspace yet. The email
    // says so rather than inventing one, and points at the platform front door instead of a dashboard that
    // would 404 for them.
    $user = User::factory()->unverified()->create();

    $this->app['request']->headers->set('host', 'meridian.test');
    $this->app['request']->server->set('HTTP_HOST', 'meridian.test');

    event(new Verified($user));

    Notification::assertSentOnDemand(
        WelcomeNotification::class,
        fn (WelcomeNotification $notification): bool => $notification->tenantName === ''
            && ! str_contains($notification->actionUrl, '/dashboard'),
    );
});

it('claims no workspace for someone the seat quota refused, even on that workspace’s host', function (): void {
    // ⚠️ THE DEFECT THE ADVERSARIAL PASS FOUND, AND IT IS NOT A CORNER. `joinOpenTenant()` returns null when
    // the seat quota is full — its own "SILENT STATE 2 OF 2" — and `JoinTenantOnRegistration` discards that
    // null deliberately, leaving a committed account with NO membership. The Free plan caps `active_seats`
    // at 2, so the third person to self-register on any free-tier workspace lands here. They still verify on
    // the tenant subdomain, so the host still resolves Acme.
    //
    // Inferring membership from the host would make the product's only non-transactional email tell the one
    // person who was quietly refused a seat, in writing, that they are a member of the workspace that just
    // kept them out — and point them at its dashboard. The host is not a membership.
    $tenant = inboxTenant();
    $nonMember = User::factory()->unverified()->create();

    $this->app['request']->headers->set('host', 'acme.meridian.test');
    $this->app['request']->server->set('HTTP_HOST', 'acme.meridian.test');

    event(new Verified($nonMember));

    Notification::assertSentOnDemand(
        WelcomeNotification::class,
        fn (WelcomeNotification $notification): bool => $notification->tenantName === ''
            && ! str_contains($notification->actionUrl, '/dashboard'),
    );
});

it('claims no workspace for an INVITED but not yet accepted membership', function (): void {
    // §7: an unaccepted invitation grants nothing. Telling someone they are a member of a workspace they
    // have not joined is the same lie as the case above, in the other direction — and it is reachable,
    // because an invitee who registers independently verifies on the tenant host too.
    $tenant = inboxTenant();
    $invitee = User::factory()->unverified()->create();

    enterTenant($tenant->id, $invitee->id);
    TenantUser::create([
        'user_id' => $invitee->id,
        'status' => TenantUserStatus::Invited,
        'invited_role_id' => catalogRole('viewer'),
        'invited_at' => now(),
        'invite_expires_at' => now()->addDay(),
    ]);
    TenantContext::flush();

    $this->app['request']->headers->set('host', 'acme.meridian.test');
    $this->app['request']->server->set('HTTP_HOST', 'acme.meridian.test');

    event(new Verified($invitee));

    Notification::assertSentOnDemand(
        WelcomeNotification::class,
        fn (WelcomeNotification $notification): bool => $notification->tenantName === '',
    );
});

it('sends the verification link at REGISTRATION and no welcome alongside it', function (): void {
    // ⚠️ THE CASE THE WHOLE DESIGN EXISTS FOR. If anyone moves the listener back to `Registered`, this goes
    // red — and it is the only thing that would say so, because two emails arriving in the same second is
    // not an error anywhere, just a worse experience nobody would attribute to a listener.
    fakeHibp();

    $this->post('http://meridian.test/register', [
        'name' => 'New Person',
        'email' => 'reg@welcometest.local',
        'password' => 'Correct-Horse-Battery-9',
        'password_confirmation' => 'Correct-Horse-Battery-9',
    ]);

    Notification::assertSentOnDemand(QueuedVerifyEmail::class);
    Notification::assertNotSentTo(new AnonymousNotifiable, WelcomeNotification::class);
});

/*
|--------------------------------------------------------------------------
| Increment M103 — R-4f23d9c7. "Say hello, once" was the docblock's opening line and nothing enforced it.
|
| `Verified` is not a once-per-person event. `UpdateUserProfileInformation::updateVerifiedUser()` nulls
| `email_verified_at` and re-sends verification on EVERY address change, the tenant Settings page puts
| that field in front of every member with no `can_manage` gate, and Fortify's `VerifyEmailController`
| fires `Verified` again when the new link is clicked — so a tester who corrected a typo in their own
| address was welcomed to the product a second time.
|
| ⚠️ THE CASE ABOVE — "sends exactly one welcome when an address is verified" — COULD NOT HAVE CAUGHT IT.
| It fires the event once and asserts one send, which is one per EVENT. These fire it twice, and the
| difference between the two shapes is the whole defect.
|
| ⚠️ THE GUARD WAS ALREADY PRESENT AT THE OTHER DISPATCH SITE, which is why this one was missed:
| `GoogleSessionStarter` refuses to re-fire with `if ($outcome->created)`, pinned by GoogleSignInWebTest
| ("firing it every time would welcome the same person weekly"). Solving it per dispatch site left
| Fortify's re-verification path open; the guard now lives in the listener, which is the chokepoint —
| it is the only `Verified` listener in the application.
*/

it('welcomes a person once however many times Verified fires', function (): void {
    $user = User::factory()->unverified()->create();

    event(new Verified($user));
    event(new Verified($user));
    event(new Verified($user));

    Notification::assertSentOnDemandTimes(WelcomeNotification::class, 1);
});

it('sends no second welcome after a verified address change', function (): void {
    $user = User::factory()->unverified()->create();

    // ⚠️ THE USER GUC IS PART OF THE FIXTURE, NOT NOISE. `users` carries FORCE RLS and its visibility
    // policy is `id = app.current_user_id OR <co-tenant>`, so without this both the profile action's
    // own save and every `fresh()` below read or write ZERO rows and raise nothing. In production this
    // is set by `EstablishTenantDatabaseContext`, which `config/fortify.php` mounts on the group.
    TenantContext::applyLocal(null, (string) $user->id);

    // First verification — the welcome this person is owed.
    event(new Verified($user));
    Notification::assertSentOnDemandTimes(WelcomeNotification::class, 1);

    // The real address-change action, not a hand-rolled imitation of it: it nulls `email_verified_at`
    // and re-sends verification, which is what makes the second `Verified` reachable at all.
    app(UpdateUserProfileInformation::class)->update($user, [
        'name' => $user->name,
        'email' => 'corrected@welcometest.local',
    ]);

    expect($user->fresh()->email_verified_at)->toBeNull();

    // Clicking the new link — what Fortify's VerifyEmailController does once `hasVerifiedEmail()` is
    // false again.
    event(new Verified($user->fresh()));

    // STILL one. Before M103 this was two.
    Notification::assertSentOnDemandTimes(WelcomeNotification::class, 1);
});

it('records the welcome on the person, so the guard survives the request that sent it', function (): void {
    $user = User::factory()->unverified()->create();
    TenantContext::applyLocal(null, (string) $user->id);

    expect($user->welcomed_at)->toBeNull();

    event(new Verified($user));

    // ⚠️ The stamp is what makes this a once-per-PERSON guard rather than a once-per-request one. It is
    // written under a BORROWED user GUC — `users` carries FORCE RLS and PostgreSQL applies the SELECT
    // policy to an UPDATE whose WHERE reads a column, so without `app.current_user_id` this write would
    // affect zero rows and raise nothing, and this assertion is what would catch that.
    expect($user->fresh()->welcomed_at)->not->toBeNull();
});

it('does not welcome an account that was verified before the guard existed', function (): void {
    // The backfill's population: already verified, therefore already welcomed, therefore stamped by
    // 2026_08_17_000113. An invitee is in here too — force-verified by InvitationController, which fires
    // no event and deliberately sends no welcome — and this is the case that decided the backfill.
    $user = User::factory()->create(['welcomed_at' => now()->subMonth()]);

    event(new Verified($user));

    Notification::assertNothingSent();
});
