<?php

declare(strict_types=1);

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

it('names the workspace and points at ITS dashboard when verified on a tenant subdomain', function (): void {
    $tenant = inboxTenant();
    $user = User::factory()->unverified()->create();

    // Driven through a real request so `request()->getHost()` is the tenant subdomain — which is the only
    // thing the listener has to work from, exactly as `JoinTenantOnRegistration` does.
    $this->get('http://acme.meridian.test/nonexistent-probe');

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
