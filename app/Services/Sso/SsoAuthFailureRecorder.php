<?php

declare(strict_types=1);

namespace App\Services\Sso;

use App\Models\SsoAuthFailure;
use App\Models\SsoConnection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Writes the tenant-visible half of a refused sign-in, and keeps the store bounded (P1c — ADR-0016 §D26).
 *
 * The log line stays exactly where P1b put it. This adds a second, different surface for a different
 * audience: the log is the OPERATOR's and holds everything; this is the TENANT ADMIN's and holds what they
 * can act on. §D19's uniform 404 is untouched — the caller still learns nothing.
 *
 * ── ⚠️ THE TRIM IS THE REASON THIS TABLE IS ALLOWED TO EXIST ────────────────────────────────────────
 * The ACS is unauthenticated. Any store it writes to is a store a stranger can fill, which is exactly why
 * `audits` — append-only and never pruned — was ruled out. So the bound is not a policy someone remembers
 * to apply: it runs in the same call as the insert, and it enforces two independent limits.
 *
 *   · A per-tenant ROW CAP. Whatever happens, one workspace holds at most `saml.failure_log_max_rows`.
 *   · A RETENTION WINDOW. `ip_address` and `subject_email` are personal data, so old rows go even when
 *     the cap is nowhere near — the `PruneFailedJobsJob` argument, on a table a stranger can write to.
 *
 * ⚠️ NOT A SCHEDULED JOB, AND THAT IS MEASURED RATHER THAN PREFERRED. `routes/console.php` records that
 * nothing runs the scheduler on the production box yet, so a nightly prune would be a bound that exists in
 * the repository and not on the machine. The cost of doing it here is one DELETE against a table the cap
 * keeps in the low hundreds.
 *
 * ── ⚠️ A FAILURE TO RECORD A FAILURE MUST NOT CHANGE THE RESPONSE ───────────────────────────────────
 * Everything here runs inside the ACS's `catch`. If this throws, a 404 becomes a 500 — which is both a
 * worse answer for the tenant and a §D4 disclosure, since a 500 distinguishes itself from every other
 * refusal. So the whole write is wrapped and any error is logged and swallowed. Swallowing is normally a
 * smell; here the alternative is letting a diagnostic aid decide the security posture of the endpoint.
 */
final class SsoAuthFailureRecorder
{
    public function record(
        SsoConnection $connection,
        SsoAuthenticationException $exception,
        ?string $ip,
        ?string $requestId = null,
    ): void {
        try {
            $now = Carbon::now();

            // BelongsToTenant fills tenant_id from the ambient context, which the host established before
            // any of this ran; the composite FK then confines the row to the same tenant as the connection.
            SsoAuthFailure::query()->create([
                'sso_connection_id' => (string) $connection->getKey(),
                'reason' => $exception->reason,
                // Null unless a verified signature vouched for the address — see the exception's docblock.
                'subject_email' => $exception->subject,
                'request_id' => $requestId,
                'ip_address' => $ip,
                'occurred_at' => $now,
            ]);

            $this->trim($now);
        } catch (Throwable $error) {
            Log::warning('sso.acs.failure_not_recorded', [
                'reason' => $exception->reason->value,
                'error' => $error->getMessage(),
            ]);
        }
    }

    /**
     * Drop everything past the retention window or beyond the row cap, for the current tenant.
     *
     * One statement, and RLS is the tenant scoping — there is no `tenant_id` predicate here because there
     * cannot be a cross-tenant row to match under strict isolation.
     *
     * ⚠️ THE CAP IS EXPRESSED AS "NOT AMONG THE NEWEST N", NOT AS "OLDER THAN THE Nth". The two differ when
     * timestamps tie, and rows written inside one second tie constantly on this table — a grinder produces
     * dozens per second. `ORDER BY occurred_at DESC, id DESC` makes the ordering total, so the subquery
     * names exactly N rows rather than however many share the boundary second.
     */
    private function trim(Carbon $now): void
    {
        $cap = (int) config('saml.failure_log_max_rows');
        $cutoff = $now->copy()->subDays((int) config('saml.failure_retention_days'));

        SsoAuthFailure::query()
            ->where(function (Builder $query) use ($cutoff, $cap): void {
                $query->where('occurred_at', '<', $cutoff)
                    ->orWhereNotIn('id', function (QueryBuilder $newest) use ($cap): void {
                        $newest->select('id')
                            ->from('sso_auth_failures')
                            ->orderByDesc('occurred_at')
                            ->orderByDesc('id')
                            ->limit($cap);
                    });
            })
            ->delete();
    }
}
