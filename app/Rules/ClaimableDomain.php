<?php

declare(strict_types=1);

namespace App\Rules;

use App\Http\Middleware\InitializeTenancyByPublicHost;
use App\Models\Domain;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * The single gate on what hostname a tenant may claim as a custom domain (H22a / ADR-0012).
 *
 * ⚠️ THE PLATFORM-HOST REFUSAL USES THE SAME PREDICATE AS ROUTING, and that is the point of putting it
 * here rather than inlining a check in the controller. {@see InitializeTenancyByPublicHost}
 * classifies a host BEFORE any database read: anything equal to a central domain or under one goes to the
 * subdomain arm and is looked up by its first label. So a row for `forms.meridian.test` would be
 * unreachable no matter what — and worse, `x.meridian.test` would let one tenant reserve a name that
 * resolves to whichever tenant owns the LABEL `x`. Refusing it at claim time and routing it the same way
 * are two halves of one decision; if they ever drift, the drift is silent.
 *
 * Deliberately NOT a CHECK constraint. Baking `config('tenancy.central_domains')` into DDL would make the
 * schema depend on the environment — `localhost` locally, `meridian.test` in CI, the real host in
 * production — so `migrate:fresh` would produce three structurally different databases. Recorded in
 * ADR-0012 as a decision rather than left as an omission.
 *
 * Occupancy is checked here too, against {@see Domain::unscopedQuery()}: a PENDING claim by another
 * tenant still reserves the name (`domains.domain` is globally unique), and the global scope would
 * otherwise hide exactly the rows this needs to see. stancl's own EnsuresDomainIsNotOccupied hook races
 * and is now scope-filtered as well, so this check plus the unique index are the real answer.
 */
final class ClaimableDomain implements ValidationRule
{
    /**
     * @param  Closure(string): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            $fail('The :attribute must be a hostname.');

            return;
        }

        $host = mb_strtolower(trim($value));

        if (mb_strlen($host) > 253) {
            $fail('The :attribute must not be longer than 253 characters.');

            return;
        }

        // A bare label is a subdomain, not a domain — and a dotless row is what every tenant's platform
        // host already occupies, so accepting one here would collide with tenant identification itself.
        if (! str_contains($host, '.')) {
            $fail('The :attribute must be a fully-qualified domain name.');

            return;
        }

        // An IP literal can never be verified (there is no zone to publish a TXT record in) and would sit
        // in the same column the dot rule treats as a hostname.
        if (filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false) {
            $fail('The :attribute must be a domain name, not an IP address.');

            return;
        }

        if (preg_match('/^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $host) !== 1) {
            $fail('The :attribute is not a valid domain name.');

            return;
        }

        if ($this->isPlatformHost($host)) {
            $fail('The :attribute is reserved by the platform and cannot be used as a custom domain.');

            return;
        }

        if (Domain::unscopedQuery()->where('domain', $host)->exists()) {
            // Deliberately tenant-agnostic: it does not say WHO holds it, and it says the same thing when
            // the holder is the requesting tenant itself.
            $fail('The :attribute is already claimed.');
        }
    }

    /**
     * A central domain or anything under one. Equality plus a DOT-BOUNDARY suffix — never a bare
     * `str_ends_with($host, $central)`, which would treat `evilmeridian.test` as a platform host.
     */
    private function isPlatformHost(string $host): bool
    {
        /** @var array<int, string> $centralDomains */
        $centralDomains = (array) config('tenancy.central_domains', []);

        foreach ($centralDomains as $central) {
            if ($host === $central || str_ends_with($host, '.'.$central)) {
                return true;
            }
        }

        return false;
    }
}
