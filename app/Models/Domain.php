<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DomainVerificationFailure;
use App\Services\Tenancy\CustomDomainService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Stancl\Tenancy\Database\Models\Domain as StanclDomain;

/**
 * The tenant-resolution row (H22a / ADR-0012). Net-new in H22a: `config/tenancy.php` bound stancl's model
 * directly until now, and `domain_model` is the single switch that repoints both `HasDomains::domains()`
 * (a hasMany on `config('tenancy.domain_model')`) and stancl's DomainTenantResolver.
 *
 * ONE TABLE, TWO KINDS OF ROW, discriminated by the dot — see the H22a migration for why that is an exact
 * partition rather than a heuristic:
 *
 *   `acme`           the tenant's platform subdomain label. What InitializeTenancyBySubdomain looks up.
 *                    Always resolvable, never verified, never swept.
 *   `forms.acme.com` a custom domain. Resolvable only once {@see isLive()} — DNS TXT verified AND activated
 *                    by an operator who has installed a certificate by hand.
 *
 * ⚠️ `$guarded = []` is inherited from stancl and is NOT overridden here, because narrowing it would change
 * the mass-assignment behaviour of sixty existing `domains()->create(['domain' => …])` fixture sites. The
 * consequence is that a `create($request->validated())` could set `verified_at` directly, so
 * {@see CustomDomainService} builds its attribute arrays explicitly and never passes
 * request data through. CustomDomainClaimTest pins that a store request carrying `verified_at` lands
 * unverified.
 *
 * Two inherited stancl behaviours worth knowing before touching this: ConvertsDomainsToLowercase rewrites
 * `domain` on every save (so a mixed-case fixture does not round-trip), and EnsuresDomainIsNotOccupied is an
 * app-level `saving` check that RACES — the unique index on `domain` is the real guard, and it always was.
 *
 * @property string $domain
 * @property string $tenant_id
 * @property string|null $verification_token
 * @property Carbon|null $token_issued_at
 * @property Carbon|null $verified_at
 * @property Carbon|null $activated_at
 * @property Carbon|null $verification_checked_at
 * @property DomainVerificationFailure|null $verification_failure_reason
 */
final class Domain extends StanclDomain
{
    /** Named so it can be dropped precisely with withoutGlobalScope() — the BelongsToTenant idiom. */
    public static string $resolvableScope = 'resolvable';

    /**
     * THE CHOKEPOINT. A custom domain is invisible to ordinary Eloquent until it is `live`.
     *
     * One scope closes five readers at once, which is the whole reason it is global rather than local:
     *
     *  - stancl's DomainTenantResolver::resolveWithoutCache() builds its `whereHas('domains', …)`
     *    existence subquery from the related model's newQueryWithoutRelationships(), which REGISTERS
     *    global scopes. A pending, verified-but-not-activated or otherwise unusable row therefore
     *    matches nothing and the request fails closed on the existing
     *    TenantCouldNotBeIdentifiedOnDomainException — no new exception class, no new render branch.
     *  - ConnectorRedirector::tenantHost(), whose own docblock calls it "the open-redirect control:
     *    the host is looked up from the tenant's own `domains` row and NEVER echoed from the request".
     *    That guarantee stays true here with ZERO edits to that file — writing an unverified `evil.com`
     *    into `domains` cannot turn it into an open redirect, because the row is not visible.
     *  - TenantUrl::host(), GuestDraftController::resumeUrl(), TenantMembershipService's invite URL.
     *  - Every reader H22b has not written yet.
     *
     * The closure wrapper around the OR is MANDATORY. A bare ->orWhere() in a global scope leaks the OR
     * out of any enclosing WHERE, which would silently widen every query this scope is meant to narrow.
     *
     * TWO COSTS, both real:
     *  1. stancl's EnsuresDomainIsNotOccupied uses $self->newQuery(), so it becomes scope-filtered and
     *     stops seeing pending rows — its friendly DomainOccupiedByOtherTenantException degrades to a
     *     raw 23505. ClaimableDomain does its own unscopedQuery() existence check, which is needed
     *     anyway to give a tenant-agnostic "already claimed" answer, and the unique index on `domain`
     *     remains the real guard exactly as it always was.
     *  2. The service and the sweep MUST use {@see unscopedQuery()} — they exist to act on rows that
     *     are not yet usable, and would otherwise be unable to see them at all. This is the single
     *     easiest mistake to make against this model, and CustomDomainSweepTest pins it.
     */
    protected static function booted(): void
    {
        self::addGlobalScope(self::$resolvableScope, function (Builder $builder): void {
            $builder->where(function (Builder $query): void {
                // position(…) rather than LIKE so this reads identically to the CHECK constraint and
                // the partial sweep index, which are the only other places the dot rule is expressed.
                $query->whereRaw("position('.' in domain) = 0")
                    ->orWhereNotNull('activated_at');
            });
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'token_issued_at' => 'datetime',
            'verified_at' => 'datetime',
            'activated_at' => 'datetime',
            'verification_checked_at' => 'datetime',
            'verification_failure_reason' => DomainVerificationFailure::class,
        ];
    }

    /** A dotted host is a custom domain; a bare label is the tenant's platform subdomain. */
    public function isCustom(): bool
    {
        return str_contains($this->domain, '.');
    }

    /**
     * Routable, and the only state that may appear in a link a respondent receives.
     *
     * A platform subdomain is always live. A custom domain is live only once an operator has activated it,
     * which by policy happens after a certificate exists for that hostname — so a tenant can never put its
     * respondents on a plaintext origin by itself.
     */
    public function isLive(): bool
    {
        return ! $this->isCustom() || $this->activated_at !== null;
    }

    /** Control of the hostname has been proven, but it is not serving yet — an operator step remains. */
    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    /**
     * The escape hatch from {@see $resolvableScope}, for the two callers that legitimately need to see
     * rows that are not usable yet: {@see CustomDomainService} (claim, verify, activate, release) and
     * the verification sweep. Drops that ONE named scope rather than all of them, so a scope added here
     * later is not silently discarded too.
     *
     * Every use of this must carry its own `where('tenant_id', …)` when acting on a tenant's behalf —
     * `domains` is RLS-exempt, so there is no database backstop on this query.
     *
     * @return Builder<self>
     */
    public static function unscopedQuery(): Builder
    {
        return self::query()->withoutGlobalScope(self::$resolvableScope);
    }
}
