<?php

declare(strict_types=1);

namespace App\Jobs\Connectors;

use App\Enums\QueueName;
use App\Jobs\TenantAwareJob;
use App\Models\Connection;
use App\Services\Connectors\ConnectionTokenRefresher;
use App\Support\Connectors\Providers\AirtableConnector;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Renew ONE OAuth grant, in a transaction that contains nothing else (M6, ADR-0009 §D6).
 *
 * ── WHY A WHOLE JOB FOR ONE REFRESH ──────────────────────────────────────────────────────────────────────
 *
 * A token refresh is an **irreversible side effect at the provider**. Airtable returns a new access+refresh
 * pair on every renewal and INVALIDATES the previous one ({@see AirtableConnector}
 * bullet 3), so the moment the token endpoint answers, the credential we still hold in the database is dead.
 * Our half of that exchange is an ordinary UPDATE — and an UPDATE is rollback-able.
 *
 * Before M6 both callers ran the refresh inside a transaction that went on to do OTHER work:
 * {@see RefreshTenantConnectorTokensJob} batched every due connection for a tenant into one, and
 * {@see DeliverConnectorMessageJob} refreshed as a pre-flight and then made up to three more outbound calls.
 * **Any later throw — including the 60s `$timeout` — rolled our side back while the provider's side stayed
 * rotated.** The next sweep then presented a refresh token Airtable had already destroyed, got
 * `invalid_grant`, and that is TERMINAL by design: `markDead()` clears the tokens, pauses every rule on the
 * connection and emails the owner. One slow batch could kill every Airtable grant a tenant had.
 *
 * So the rotation gets a transaction of its own whose entire body is "write the grant we were just handed".
 * The base class still opens it — that is where RLS scoping comes from ({@see TenantAwareJob}, `SET LOCAL`) —
 * but nothing else can throw inside it, and it commits before this job returns.
 *
 * ── THE LOCK IS THE SECOND HALF, AND IT IS A DIFFERENT DEFECT WITH THE SAME CURE ──────────────────────────
 *
 * `ensureFresh()` was a plain read-check-then-refresh with no lock, so two workers could exchange the SAME
 * rotating refresh token concurrently: the first wins, the second is answered `invalid_grant` and marks a
 * perfectly healthy grant dead. Latent while `docker-compose.yml` runs exactly one `queue:work` — and that
 * same line names adding workers as the scaling path, so it is latent by deployment accident rather than by
 * design. One connection = one lock holder makes the exchange serial per grant, which is the only shape that
 * is correct for a credential the provider rotates under us.
 *
 * The lock is taken WITHOUT a wait: a concurrent refresh of the same grant is not work to queue up behind,
 * it is work already being done. The loser returns and lets the winner's write stand.
 *
 * ⚠️ WHAT IS NARROWED RATHER THAN CLOSED, STATED SO NOBODY READS THIS AS AIRTIGHT: the window between the
 * provider committing the rotation and this job committing the write is now one UPDATE wide instead of a
 * whole batch, but it is not zero. A database failure in exactly that gap still loses a rotated token.
 * Closing it entirely needs a two-phase protocol the provider does not offer, so the honest fix is to make
 * the window as small as a window can be and to say where it still is.
 */
#[Queue(QueueName::ScheduledMaintenance)]
final class RefreshOneConnectionJob extends TenantAwareJob
{
    /** Seconds a refresh may hold its grant's lock — comfortably over the token endpoint's connect+read budget. */
    private const LOCK_SECONDS = 30;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $connectionId,
    ) {}

    protected function handleForTenant(): void
    {
        $connection = Connection::query()->find($this->connectionId);

        if ($connection === null) {
            return; // disconnected between the sweep's read and this job running
        }

        // No waiting: see the class docblock. `Cache::lock()->get()` returns false rather than blocking, and
        // false here means "someone else is already rotating this grant", which needs no action from us.
        $lock = Cache::lock('connector-refresh:'.$this->connectionId, self::LOCK_SECONDS);

        if (! $lock->get()) {
            return;
        }

        try {
            app(ConnectionTokenRefresher::class)->refreshNow($connection, Carbon::now());
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array<string, scalar|null>
     */
    protected function failureContext(): array
    {
        return ['queue' => QueueName::ScheduledMaintenance->value, 'connection_id' => $this->connectionId];
    }
}
