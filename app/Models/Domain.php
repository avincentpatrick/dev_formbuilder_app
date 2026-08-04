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
     * Every row the resolver and the URL builders are allowed to ignore the global scope for. Used by
     * {@see CustomDomainService} and the verification sweep, which exist precisely to
     * act on rows that are NOT yet usable and would otherwise be invisible to them.
     *
     * @return Builder<self>
     */
    public static function unscopedQuery(): Builder
    {
        return self::query()->withoutGlobalScopes();
    }
}
