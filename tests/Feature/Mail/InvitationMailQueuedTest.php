<?php

declare(strict_types=1);

use App\Enums\QueueName;
use App\Models\User;
use App\Notifications\TenantInvitationNotification;
use App\Services\Tenancy\TenantMembershipService;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\PermissionRegistrar;

/**
 * H3 — the tenant invitation, now delivered on the async substrate's `mail` queue.
 *
 * Two proofs: (1) the invite() dispatch site sends an ON-DEMAND, scalar-only notification (behavioural,
 * via Notification::fake); and (2) it lands as a real row on the `mail` queue with a payload that carries
 * NO serialized Eloquent model, then drains cleanly through a real `queue:work` — the §D5 anti-vacuity
 * check that a sync-only test could never make (mirrors DatabaseWorkerPipelineTest).
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

it('queues the invitation as an on-demand mail notification carrying only scalars', function (): void {
    Notification::fake();

    // Pinned rather than inherited from .env, so the assertion is environment-independent (the
    // GeneratePdfJobTest precedent) — locally app.url is http://localhost:8080.
    config(['app.url' => 'https://meridian.test']);

    $tenant = inboxTenant('acme'); // creates the tenant + a `domains` row, so the accept URL resolves
    $admin = User::factory()->create();
    enterTenant($tenant->id, $admin->id);

    app(TenantMembershipService::class)->invite($tenant, 'newbie@membertest.local', 'form_editor', $admin);

    Notification::assertSentOnDemand(
        TenantInvitationNotification::class,
        function (TenantInvitationNotification $notification, array $channels, object $notifiable): bool {
            expect($notification)->toBeInstanceOf(ShouldQueue::class);

            return ($notifiable->routes['mail'] ?? null) === 'newbie@membertest.local'
                && $channels === ['mail']
                && $notification->tenantName === 'Acme'
                // The accept URL was resolved IN-REQUEST from the tenant's domain row (RLS-exempt read),
                // carried as a scalar — no Tenant model rode the queue.
                //
                // H22a: this asserted `https://acme/invitations/` — a URL that resolves NOWHERE, because
                // domains.domain holds the subdomain LABEL. Every invitation email ever sent carried it.
                // The builder now goes through TenantUrl::to(), which composes the label with the
                // deployment host and takes the scheme from app.url instead of hard-coding https.
                && str_starts_with($notification->acceptUrl, 'https://acme.meridian.test/invitations/');
        }
    );
});

it('keeps the invite link on the app host even when the tenant has a live custom domain', function (): void {
    // TenantUrl::to(), not toPublic(). /invitations/{token} is served by a route group that still
    // identifies by subdomain (ADR-0009 §D2 scopes custom domains to public forms), so an invite link on
    // a custom host would 302 the invitee to the central app instead of letting them accept.
    Notification::fake();
    config(['app.url' => 'https://meridian.test']);

    $tenant = inboxTenant('acme');
    customDomain($tenant, 'forms.acme-example.com');
    $admin = User::factory()->create();
    enterTenant($tenant->id, $admin->id);

    app(TenantMembershipService::class)->invite($tenant, 'newbie@membertest.local', 'form_editor', $admin);

    Notification::assertSentOnDemand(
        TenantInvitationNotification::class,
        fn (TenantInvitationNotification $notification): bool => str_starts_with($notification->acceptUrl, 'https://acme.meridian.test/invitations/')
    );
});

it('lands the invitation on the mail queue with a scalar-only payload and drains clean', function (): void {
    config()->set('queue.default', 'database');
    config()->set('mail.default', 'array'); // no external transport when the worker sends

    Notification::route('mail', 'recipient@membertest.local')
        ->notify(new TenantInvitationNotification('Acme', 'https://acme.meridian.test/invitations/plain-token'));

    // ── anti-vacuity: a real row, on the mail queue, unreserved ────────────────────────────────────
    $row = DB::table('jobs')->where('queue', QueueName::Mail->value)->first();

    expect($row)->not->toBeNull()
        ->and($row->attempts)->toBe(0)
        ->and($row->reserved_at)->toBeNull();

    // ── §D5: scalars only — the email is there, no serialized Eloquent model is ─────────────────────
    $payload = json_decode($row->payload, true);

    expect($payload['displayName'])->toBe(TenantInvitationNotification::class)
        ->and($payload['data']['command'])->toContain('recipient@membertest.local')
        ->and($payload['data']['command'])->toContain('plain-token')
        ->and($payload['data']['command'])->not->toContain('ModelIdentifier')
        ->and($payload['data']['command'])->not->toContain('App\Models');

    Exceptions::fake();

    // §D6: draining `default` is a no-op; only `mail` consumes it.
    workOneJob(QueueName::FALLBACK);
    expect(DB::table('jobs')->count())->toBe(1);

    workOneJob(QueueName::Mail->value);

    Exceptions::assertNothingReported();
    expect(DB::table('jobs')->count())->toBe(0); // reserved AND deleted, not released
});
