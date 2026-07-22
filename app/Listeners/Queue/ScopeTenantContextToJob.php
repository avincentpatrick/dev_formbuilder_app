<?php

declare(strict_types=1);

namespace App\Listeners\Queue;

use App\Jobs\TenantAwareJob;
use App\Models\Concerns\BelongsToTenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Queue\Events\JobProcessing;
use Spatie\Permission\PermissionRegistrar;

/**
 * Scopes the process-lifetime tenant statics to the lifetime of a single job (ADR-0007 §D4).
 *
 * THIS IS THE CLASS THAT CLOSES THE DEFECT ADR-0007 §Context 6 DOCUMENTS, and the reason two other
 * documents (ADR-0002 `:125`, security-threat-model `:40`) become accurate rather than aspirational.
 *
 * The defect: TenantContext::$tenantId is a PHP static that applyLocal() never rolls back — it scopes
 * only the DATABASE GUC to the transaction. So on a long-lived queue:work, once one job commits, the
 * GUC is NULL while the static still holds tenant A, and BelongsToTenant then injects A into the next
 * job's reads and its `creating` auto-fill. RLS still fails closed (reads return zero rows, inserts
 * throw), so there is no cross-tenant DISCLOSURE — but both documents promised "fails fast (explicit
 * exception)" and it did not. It escaped notice only because no second job type existed.
 *
 * ── Why SAVE/RESTORE and not a blind flush ──────────────────────────────────────────────────────
 * Under QUEUE_CONNECTION=sync — which is EVERY current CI job (phpunit.xml) — SyncQueue raises these
 * same two events INLINE, inside the caller's stack, mid-request. A blind flush would therefore wipe
 * the ambient request's tenant context and break the code that dispatched the job (for example
 * AttachmentStorageService, which creates the row and then dispatches). Save/restore is correct under
 * both a real worker (where the saved value is null, so restoring is equivalent to flushing) and sync
 * (where it puts the caller's context back).
 *
 * This is deliberately NOT gated on `$event->connectionName !== 'sync'`. Such a gate would make this
 * listener dead code in every CI job — leaving the mechanism that closes a tenant-boundary defect
 * exercised by nothing, which is precisely the unverifiability ADR-0007 §Context indicts.
 *
 * ── Why the stack is STATIC ─────────────────────────────────────────────────────────────────────
 * Illuminate\Events\Dispatcher::createClassCallable() resolves a FRESH listener instance per event
 * dispatch, so instance state would never survive from entering() to leaving() — the restore would
 * silently degrade into a blind flush, reintroducing the sync hazard above with no test failing.
 * It is a LIFO stack rather than a single slot because sync dispatches nest (a job dispatching a job).
 *
 * ── Why both methods must be infallible ─────────────────────────────────────────────────────────
 * leaving() runs inside Worker::process()'s `finally` block. A throw there would REPLACE the job's
 * real exception with this one, destroying the diagnostic. Hence flush()/restoreMirror(), which touch
 * only PHP statics — never forget(), which issues two `set_config` round-trips per job and could
 * throw on a connection that has just failed.
 */
final class ScopeTenantContextToJob
{
    /**
     * @var list<array{tenant: ?string, user: ?string, team: int|string|null}>
     */
    private static array $stack = [];

    /**
     * Before the job runs. Fires BEFORE the payload is unserialized (Worker::process() raises this,
     * then calls $job->fire(), which is what runs SerializesModels::__unserialize inside
     * CallQueuedHandler::call) — so a job cannot free-ride on a predecessor's leftover static even
     * during payload restoration. That ordering is what makes ADR-0007 §D5's reasoning hold.
     */
    public function entering(JobProcessing $event): void
    {
        $registrar = app(PermissionRegistrar::class);

        self::$stack[] = [
            'tenant' => TenantContext::currentTenantId(),
            'user' => TenantContext::currentUserId(),
            'team' => $registrar->getPermissionsTeamId(),
        ];

        // Fail closed: with no context, BelongsToTenant substitutes its NO_TENANT_SENTINEL and every
        // tenant-scoped read returns zero rows. {@see BelongsToTenant} {@see TenantAwareJob}
        TenantContext::flush();
        $registrar->setPermissionsTeamId(null);
    }

    /**
     * After the job, on EVERY path. JobAttempted — not JobProcessed — because Worker::process() raises
     * JobProcessed only on the success path and diverts to handleJobException() on a throw; it
     * dispatches JobAttempted from the `finally`. Listening to JobProcessed would leak the tenant
     * static out of every job that threw or was released, which is the failure mode this class exists
     * to prevent. (ADR-0007 §D4 names Queue::after; the framework makes JobAttempted the correct
     * edge, and the ADR text is corrected in this increment.)
     */
    public function leaving(JobAttempted $event): void
    {
        $frame = array_pop(self::$stack);

        if ($frame === null) {
            // Defensive: an unbalanced stack means a JobAttempted arrived with no matching
            // JobProcessing. Restoring nothing is the fail-closed choice.
            TenantContext::flush();
            app(PermissionRegistrar::class)->setPermissionsTeamId(null);

            return;
        }

        TenantContext::restoreMirror($frame['tenant'], $frame['user']);
        app(PermissionRegistrar::class)->setPermissionsTeamId($frame['team']);
    }

    /**
     * Test-isolation hatch, mirroring TenantContext::flush()'s role. The framework's two `finally`
     * blocks keep the stack balanced in practice, but a test that constructs events by hand can
     * leave a frame behind.
     */
    public static function resetStack(): void
    {
        self::$stack = [];
    }
}
