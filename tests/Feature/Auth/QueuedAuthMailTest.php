<?php

declare(strict_types=1);

use App\Enums\QueueName;
use App\Models\User;
use App\Notifications\Auth\QueuedResetPassword;
use App\Notifications\Auth\QueuedVerifyEmail;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;

/**
 * H3 — framework auth mail (email verification + password reset) queued on the `mail` queue.
 *
 * These are TENANT-LESS, so they cannot be TenantAwareJobs. The safety property the design turns on: the
 * User is NEVER serialized into the queue. User::send*Notification() signs the URL in-request and delivers
 * to an on-demand notifiable, so SendQueuedNotifications wraps an AnonymousNotifiable (a routes array),
 * not a User model — which under a NULL GUC on the worker would fail closed against the `users` RLS policy
 * (ADR-0007 §D5). The first two tests prove the dispatch shape; the third proves it on the real queue.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    TenantContext::flush();
});

it('queues email verification to an on-demand notifiable, never serializing the User', function (): void {
    Queue::fake();

    $user = User::factory()->create(['email' => 'verify@authmailtest.local']);

    $user->sendEmailVerificationNotification();

    Queue::assertPushedOn(QueueName::Mail->value, SendQueuedNotifications::class);
    Queue::assertPushed(SendQueuedNotifications::class, function (SendQueuedNotifications $job): bool {
        $notifiable = $job->notifiables->first();

        return $notifiable instanceof AnonymousNotifiable
            && ! $notifiable instanceof User
            && ($notifiable->routes['mail'] ?? null) === 'verify@authmailtest.local'
            && $job->notification instanceof QueuedVerifyEmail
            && str_contains($job->notification->signedUrl, '/email/verify/');
    });
});

it('queues the password reset link to an on-demand notifiable, never serializing the User', function (): void {
    Queue::fake();

    $user = User::factory()->create(['email' => 'reset@authmailtest.local']);

    $user->sendPasswordResetNotification('a-reset-token');

    Queue::assertPushedOn(QueueName::Mail->value, SendQueuedNotifications::class);
    Queue::assertPushed(SendQueuedNotifications::class, function (SendQueuedNotifications $job): bool {
        $notifiable = $job->notifiables->first();

        return $notifiable instanceof AnonymousNotifiable
            && ! $notifiable instanceof User
            && ($notifiable->routes['mail'] ?? null) === 'reset@authmailtest.local'
            && $job->notification instanceof QueuedResetPassword
            && str_contains($job->notification->signedUrl, '/reset-password/');
    });
});

it('serializes no User model into the mail-queue payload and drains clean', function (): void {
    config()->set('queue.default', 'database');
    config()->set('mail.default', 'array'); // no external transport when the worker sends

    $user = User::factory()->create(['email' => 'payload@authmailtest.local']);

    $user->sendEmailVerificationNotification();

    $row = DB::table('jobs')->where('queue', QueueName::Mail->value)->first();

    expect($row)->not->toBeNull();

    // The §D5 proof for auth mail: the payload carries the email string but NO serialized User.
    $payload = json_decode($row->payload, true);

    expect($payload['data']['command'])->toContain('payload@authmailtest.local')
        ->and($payload['data']['command'])->not->toContain('ModelIdentifier')
        ->and($payload['data']['command'])->not->toContain('App\Models\User');

    Exceptions::fake();
    workOneJob(QueueName::Mail->value);

    Exceptions::assertNothingReported();
    expect(DB::table('jobs')->count())->toBe(0);
});
