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
                && str_starts_with($notification->acceptUrl, 'https://acme/invitations/');
        }
    );
});

it('lands the invitation on the mail queue with a scalar-only payload and drains clean', function (): void {
    config()->set('queue.default', 'database');
    config()->set('mail.default', 'array'); // no external transport when the worker sends

    Notification::route('mail', 'recipient@membertest.local')
        ->notify(new TenantInvitationNotification('Acme', 'https://acme/invitations/plain-token'));

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
