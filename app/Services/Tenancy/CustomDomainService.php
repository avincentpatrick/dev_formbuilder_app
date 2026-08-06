<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Enums\AuditEvent;
use App\Enums\DomainVerificationFailure;
use App\Jobs\Tenancy\VerifyCustomDomainsJob;
use App\Models\Domain;
use App\Models\Tenant;
use App\Rules\ClaimableDomain;
use App\Services\Forms\FormService;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\DnsTxtResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The custom-domain lifecycle (H22a / ADR-0012): claim → verify → activate, plus release and the sweep.
 *
 * ⚠️ EVERY QUERY HERE GOES THROUGH {@see Domain::unscopedQuery()}, and every one that acts on a tenant's
 * behalf carries its OWN `where('tenant_id', …)`. Both halves matter. The global scope hides exactly the
 * rows this service exists to act on, and `domains` is RLS-EXEMPT — it is the table read to decide which
 * tenant a request is, so scoping it by tenant would be circular — which means there is no database
 * backstop under these queries. The tenant filter here IS the isolation.
 *
 * ⚠️ ATTRIBUTE ARRAYS ARE BUILT EXPLICITLY, NEVER FROM REQUEST DATA. stancl's Domain model is
 * `$guarded = []` and H22a deliberately did not narrow that (sixty fixture sites depend on it), so
 * `create($request->validated())` would happily set `verified_at`. CustomDomainClaimTest pins that a
 * store request carrying verification fields still lands pending.
 *
 * The state machine, derived from three nullable timestamps rather than a status column:
 *
 *   claim()     ∅ → pending    tenant, self-serve. Mints a token; reserves the hostname.
 *   verify()    pending → verified   whoever asks, on demand or from the sweep. Requires the TXT record.
 *   activate()  verified → live      OPERATOR ONLY, and only after a certificate exists for that host.
 *   release()   any → ∅        tenant or operator. The row is deleted; the name is freed.
 *
 * {@see makePrimary()} sits beside that machine rather than inside it (H22b): it moves no state, it records
 * a PREFERENCE among rows that are already live, and it refuses anything that is not.
 *
 * WHY ACTIVATION IS NOT THE TENANT'S. Per-domain TLS issuance is structurally Track B, which is deferred.
 * Until it exists a certificate is installed by hand, so a tenant that could activate its own domain
 * could put its respondents on an origin with no certificate — and the resume link they receive would
 * carry that origin. The gate is the ratified "failing closed" posture made mechanical.
 *
 * ── Auditing (Increment I2): THREE of the six verbs, and the other three are a recorded decision ───────
 * `claim`, `makePrimary` and `release` emit `domain` rows through {@see self::auditDomain()}. The rest do
 * not, and the reasons are not "we ran out of time":
 *
 *  - **`verify()`** is reached from HTTP *and* from the sweep. One method, two contexts — a conditional
 *    audit would make the ledger's contents depend on which caller won a race, which is worse than a
 *    consistent gap. It also records a CHECK RESULT the sweep rewrites every fifteen minutes forever, and
 *    flooding a compliance ledger with machine noise is exactly what spec §1's noise-reduction clause
 *    forbids.
 *  - **`activate()` / `deactivate()`** are artisan-only, with no tenant context and — decisively — no
 *    actor. {@see self::activate()}'s own docblock already argues this for `activated_by`. An audit row
 *    from there would carry `user_id = null` beside `is_system_action = false`, which is malformed by
 *    {@see AuditLogger}'s own contract.
 *  - **`sweep()` / `releaseExpiredClaims()`** are bulk deletes with no per-row read and no context. An
 *    accepted, documented gap: an expired claim was never verified, routed nowhere and appeared in no
 *    link, so there is nothing an auditor could act on.
 *
 * The actor comes from {@see AuditLogger}'s `Auth::id()` fallback rather than a threaded `?User`, which is
 * a deliberate asymmetry with {@see FormService}: an explicit actor parameter earns
 * its keep only where a non-HTTP caller exists, and the verbs that have one are precisely the verbs above.
 */
final class CustomDomainService
{
    public function __construct(
        private readonly DnsTxtResolver $dns,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Reserve a hostname for a tenant and mint its challenge token. The row is pending: it routes
     * nowhere and appears in no link, because the global scope hides it.
     *
     * The hostname must already have passed {@see ClaimableDomain} — this method does not
     * re-validate it, so the rule is the single gate and cannot be bypassed by a second caller that
     * forgot it. The unique index on `domain` is the backstop if two claims race.
     */
    public function claim(Tenant $tenant, string $host): Domain
    {
        return DB::transaction(function () use ($tenant, $host): Domain {
            /** @var Domain $domain */
            $domain = $tenant->domains()->create([
                'domain' => mb_strtolower(trim($host)),
                // 256-bit CSPRNG, random and never derived. An HMAC of (tenant, domain) would be
                // unrotatable per claim and would make an APP_KEY compromise a domain-claim primitive, for
                // no benefit — the token is published in public DNS either way.
                'verification_token' => bin2hex(random_bytes(32)),
                'token_issued_at' => Carbon::now(),
                'verified_at' => null,
                'activated_at' => null,
                'verification_checked_at' => null,
                'verification_failure_reason' => null,
                // Explicit for the same reason every field above is: the model is `$guarded = []`, so a claim
                // body carrying `is_primary` would otherwise be mass-assignable straight past the CHECK's intent.
                'is_primary' => false,
            ]);

            // ⚠️ `verification_token` IS DELIBERATELY ABSENT FROM THIS PAYLOAD, and it must not be added
            // "for completeness". The token is not in AuditRedactor::SECRETS by an explicit decision
            // recorded at 2026_08_04_000001_add_custom_domain_verification_to_domains.php:49-51 — it is
            // published in public DNS at a name only the zone's controller can write, so it is not a
            // credential and registering it would contradict that decision. The right move for a
            // non-secret that is also not accountability-relevant is simply not to record it.
            $this->auditDomain(AuditEvent::Created, $domain, null, [
                'domain' => $domain->domain,
                'is_primary' => false,
            ]);

            return $domain;
        });
    }

    /**
     * Look up the challenge TXT record and record the outcome. Returns whether the domain is verified
     * AFTER this attempt (so a re-verify of an already-verified row returns true without a lookup being
     * treated as a failure).
     *
     * NEVER THROWS on a DNS outcome. A record that has not propagated yet is an ordinary business state,
     * not a job defect — and the sweep runs with $tries = 1, so a throw would land in failed_jobs and
     * take the whole batch with it.
     */
    public function verify(Domain $domain): bool
    {
        $token = $domain->verification_token;

        if ($token === null || ! $domain->isCustom()) {
            return false;
        }

        $records = $this->dns->txt($this->challengeName($domain->domain));
        $now = Carbon::now();

        if ($records === null) {
            // "Could not ask" — evidence about us, not about the tenant. Deliberately does NOT touch
            // verified_at, so a transient resolver failure never demotes an already-verified domain.
            $domain->forceFill([
                'verification_checked_at' => $now,
                'verification_failure_reason' => DomainVerificationFailure::LookupFailed,
            ])->save();

            return $domain->isVerified();
        }

        $expected = $this->expectedValue($token);
        $matched = in_array($expected, $records, true);

        $domain->forceFill([
            'verification_checked_at' => $now,
            'verified_at' => $matched ? ($domain->verified_at ?? $now) : $domain->verified_at,
            'verification_failure_reason' => match (true) {
                $matched => null,
                $records === [] => DomainVerificationFailure::NotFound,
                default => DomainVerificationFailure::Mismatch,
            },
        ])->save();

        return $domain->isVerified();
    }

    /**
     * The OPERATOR step: put a verified domain into service. Refuses anything not verified, so the
     * ordering (prove control, then install a certificate, then serve) cannot be short-circuited.
     *
     * There is deliberately no `activated_by` column. The only caller is an artisan command run on the
     * box by whoever installed the certificate — there is no in-app actor to attribute it to, and adding
     * a nullable FK that is always null would be worse than the audit gap it pretends to close.
     *
     * H22b: the FIRST live custom domain a tenant gets also becomes its primary, in the same write. Not a
     * convenience — it is what keeps `TenantUrl::toPublic()` unchanged for the
     * overwhelmingly common one-domain tenant, who should never have to express a preference between one
     * thing and nothing. A second activation leaves the existing primary alone: repointing every
     * outstanding resume link is the tenant's decision to make on the H22b page, never a side effect of an
     * operator running this command.
     */
    public function activate(Domain $domain): bool
    {
        if (! $domain->isCustom() || ! $domain->isVerified() || $domain->activated_at !== null) {
            return false;
        }

        $domain->forceFill([
            'activated_at' => Carbon::now(),
            'is_primary' => ! $this->hasPrimary($domain),
        ])->save();

        return true;
    }

    /**
     * Take a live domain out of service without releasing the claim. The row stays verified.
     *
     * ⚠️ CLEARS `is_primary` IN THE SAME WRITE, and it must: `domains_primary_requires_live_chk` says a row
     * may be primary only while it is activated, so leaving the flag set would make this UPDATE a
     * constraint violation rather than a deactivation. The tenant's preference does not survive the host
     * leaving service — the next activation re-elects a primary if none is left.
     */
    public function deactivate(Domain $domain): bool
    {
        if ($domain->activated_at === null) {
            return false;
        }

        $domain->forceFill(['activated_at' => null, 'is_primary' => false])->save();

        return true;
    }

    /**
     * Choose which of a tenant's LIVE custom domains its respondents' links are built on (H22b).
     *
     * Refuses anything not live, which is what keeps this tenant-reachable write off the activation path:
     * ADR-0012 §D6 leaves `php artisan domains:activate` as the only code path that can put a host INTO
     * service, and this one only chooses among hosts an operator already did. `domains_primary_requires_live_chk`
     * says the same thing in DDL, so the two cannot drift.
     *
     * The clear-then-set pair runs in ONE transaction. `domains_primary_per_tenant_unique` is a partial
     * unique index, so the intermediate state (two primaries) is a 23505 — and PostgreSQL aborts the whole
     * transaction on one, which is the SavedReportViewService::guardName() lesson: the atomicity is what
     * makes the constraint a backstop rather than a live failure mode.
     */
    public function makePrimary(Domain $domain): bool
    {
        if (! $domain->isCustom() || ! $domain->isLive()) {
            return false;
        }

        if ($domain->is_primary) {
            return true;
        }

        DB::transaction(function () use ($domain): void {
            Domain::unscopedQuery()
                ->where('tenant_id', $domain->tenant_id)
                ->where('is_primary', true)
                ->update(['is_primary' => false]);

            $domain->forceFill(['is_primary' => true])->save();

            // The hostname rides on BOTH sides of every domain row. That is the price of keying on the
            // tenant id (see auditDomain()): without it a reader cannot tell which of a tenant's hosts the
            // row is about, and `domains` rows are deletable, so a join would not always answer it either.
            $this->auditDomain(
                AuditEvent::Updated,
                $domain,
                ['domain' => $domain->domain, 'is_primary' => false],
                ['domain' => $domain->domain, 'is_primary' => true],
            );
        });

        return true;
    }

    /** Whether the tenant already has a primary custom domain (excluding the row being acted on). */
    private function hasPrimary(Domain $domain): bool
    {
        return Domain::unscopedQuery()
            ->where('tenant_id', $domain->tenant_id)
            ->where('is_primary', true)
            ->whereKeyNot($domain->getKey())
            ->exists();
    }

    /**
     * Delete the row and free the hostname. Refuses to delete a tenant's SUBDOMAIN row — that is its app
     * host, and removing it would take the tenant offline with no way back through this surface.
     */
    public function release(Domain $domain): bool
    {
        if (! $domain->isCustom()) {
            return false;
        }

        // Snapshot BEFORE the delete, audit and delete in ONE transaction — the ResourceGrantService::revoke()
        // shape, and for the same reason with more force: this row is HARD-deleted, so the audit entry is the
        // only surviving record that the tenant ever controlled this hostname.
        return DB::transaction(function () use ($domain): bool {
            $old = [
                'domain' => $domain->domain,
                'verified_at' => $domain->verified_at?->toIso8601String(),
                'activated_at' => $domain->activated_at?->toIso8601String(),
                'is_primary' => $domain->is_primary,
            ];

            $deleted = (bool) $domain->delete();

            if ($deleted) {
                $this->auditDomain(AuditEvent::Deleted, $domain, $old, null);
            }

            return $deleted;
        });
    }

    /**
     * Emit one `domain` audit row, or nothing at all — the guard is the point of this method (Increment I2).
     *
     * **`auditable_id` IS THE TENANT'S UUID, NEVER THE DOMAIN ROW'S ID, AND THAT IS NOT A STYLE CHOICE.**
     * `domains.id` is an `increments()` INTEGER (2026_07_05_000100_create_domains_table.php:23) while
     * `audits.auditable_id` is `uuid NOT NULL` — so `(string) $domain->getKey()` would try to insert `'3'`
     * into a uuid column and raise SQLSTATE 22P02 on the first live claim. Nothing catches that statically:
     * not PHPStan, not the `audits_event_check`, not the migration linter. This is the same addressing
     * problem spec §1 already solves the same way for role grants (`model_has_roles` has a composite PK and
     * no surrogate id), and it is written into §1 so nobody "fixes" it back. The hostname travels in the
     * payload instead.
     *
     * ⚠️ **`domains` IS RLS-EXEMPT; `audits` IS NOT.** Two distinct failures this guard closes:
     *
     *  1. **NO TENANT CONTEXT.** This service is also reachable from the console (`domains:activate`, the
     *     verification sweep). An `audits` INSERT there violates the strict append-only `WITH CHECK` — and
     *     inside a `$tries = 1` MaintenanceJob that lands in `failed_jobs` ON EVERY TICK, FOREVER, which is
     *     the exact failure {@see VerifyCustomDomainsJob}'s docblock spends a paragraph
     *     avoiding. It fails SILENT on purpose: an audit gap is recoverable, a permanently-failing sweep is
     *     not.
     *  2. **WRONG TENANT.** Because `domains` is exempt, an operator path can hand this service tenant B's
     *     row while the ambient context is tenant A's. The `audits` `WITH CHECK` compares `tenant_id` to the
     *     AMBIENT GUC, not to the row's owner — so that INSERT would SUCCEED and file B's domain event in
     *     A's ledger. RLS cannot catch this, precisely because the source table is exempt. This equality IS
     *     the isolation, exactly as the class docblock says of the `where('tenant_id', …)` filters.
     *
     * @param  ?array<string, mixed>  $old
     * @param  ?array<string, mixed>  $new
     */
    private function auditDomain(AuditEvent $event, Domain $domain, ?array $old, ?array $new): void
    {
        $tenantId = TenantContext::currentTenantId();

        if ($tenantId === null || $tenantId !== (string) $domain->tenant_id) {
            return;
        }

        $this->audit->record($event, 'domain', $tenantId, old: $old, new: $new);
    }

    /**
     * One tenant's custom domains, newest first. Unscoped, because the whole point of the listing is to
     * show pending and failed rows.
     *
     * @return Collection<int, Domain>
     */
    public function forTenant(Tenant $tenant): Collection
    {
        /** @var Collection<int, Domain> $domains */
        $domains = Domain::unscopedQuery()
            ->where('tenant_id', $tenant->getKey())
            ->whereRaw("position('.' in domain) > 0")
            ->orderByDesc('created_at')
            ->get();

        return $domains;
    }

    /**
     * Find one of a tenant's custom domains by hostname. The explicit tenant filter is the isolation —
     * `domains` is RLS-exempt, so an unfiltered whereKey() here would resolve ANY tenant's row.
     */
    public function findForTenant(Tenant $tenant, string $host): ?Domain
    {
        /** @var Domain|null $domain */
        $domain = Domain::unscopedQuery()
            ->where('tenant_id', $tenant->getKey())
            ->where('domain', mb_strtolower(trim($host)))
            ->first();

        return $domain;
    }

    /**
     * One sweep pass. Returns [verified, released] counts.
     *
     * BOUNDED, and the bound is the most important line in this class. `dns_get_record()` takes no
     * timeout, so a batch of dead nameservers costs (attempts × servers × ~5s) EACH against the job's
     * 300s budget — and MaintenanceJob runs with $tries = 1, so blowing it means failed_jobs on every
     * tick, forever. Ordering by `verification_checked_at NULLS FIRST` makes the bound fair: a domain
     * cannot starve, and wall time is O(1) in table size rather than O(rows).
     *
     * @return array{verified: int, released: int}
     */
    public function sweep(): array
    {
        $now = Carbon::now();
        $released = $this->releaseExpiredClaims($now);

        $recheckBefore = $now->copy()->subMinutes((int) config('tenancy.custom_domains.recheck_minutes', 60));

        /** @var Collection<int, Domain> $due */
        $due = Domain::unscopedQuery()
            ->whereRaw("position('.' in domain) > 0")
            ->where(function ($query) use ($recheckBefore): void {
                // Pending rows are always due — a tenant that just published the record is waiting.
                // Verified/live rows are re-read on a cadence: that re-check is the dangling-DNS control.
                $query->whereNull('verified_at')
                    ->orWhereNull('verification_checked_at')
                    ->orWhere('verification_checked_at', '<', $recheckBefore);
            })
            ->orderByRaw('verification_checked_at NULLS FIRST')
            ->limit(max(1, (int) config('tenancy.custom_domains.sweep_batch', 50)))
            ->get();

        $verified = 0;

        foreach ($due as $domain) {
            // Per-domain isolation: one tenant's dead nameserver must not abort the batch.
            try {
                if ($this->verify($domain)) {
                    $verified++;
                }
            } catch (\Throwable) {
                // Deliberately swallowed and left for the next pass. Only infrastructure failure (the
                // database) should be able to fail this job.
                continue;
            }
        }

        return ['verified' => $verified, 'released' => $released];
    }

    /**
     * Drop pending claims older than the TTL. `domains.domain` is globally unique, so an abandoned or
     * squatted claim would otherwise block the hostname's real owner forever.
     *
     * Only PENDING rows — a verified or live domain is never released by the sweep. A transient DNS
     * outage must not take a tenant's production host out of service, and demoting on N consecutive
     * misses is explicitly deferred (see ADR-0012).
     */
    private function releaseExpiredClaims(Carbon $now): int
    {
        $cutoff = $now->copy()->subHours((int) config('tenancy.custom_domains.claim_ttl_hours', 168));

        return Domain::unscopedQuery()
            ->whereRaw("position('.' in domain) > 0")
            ->whereNull('verified_at')
            ->where('created_at', '<', $cutoff)
            ->delete();
    }

    /** The DNS name the tenant publishes the challenge at. */
    public function challengeName(string $host): string
    {
        $label = (string) config('tenancy.custom_domains.txt_record_name', '_meridian-challenge');

        return $label.'.'.$host;
    }

    /** The exact TXT value that must appear at {@see challengeName()}. */
    public function expectedValue(string $token): string
    {
        return (string) config('tenancy.custom_domains.txt_value_prefix', 'meridian-domain-verification=').$token;
    }
}
