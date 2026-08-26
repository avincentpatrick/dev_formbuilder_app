<?php

declare(strict_types=1);

namespace App\Services\Sso;

use App\Enums\DomainVerificationFailure;
use App\Models\SsoVerifiedDomain;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\CustomDomainService;
use App\Support\Tenancy\DnsTxtResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Which email domains a workspace has proven it controls (M18 — ADR-0016 §D34).
 *
 * ── THE ONE QUESTION THAT MATTERS IS {@see self::isVerifiedFor()} ───────────────────────────────────
 * Everything else here is lifecycle. That method is the whole of this class's authority: it is what
 * {@see SsoUserProvisioner} asks before it lets an assertion reach any account, and it is deliberately
 * phrased over an EMAIL rather than over a domain so that no caller has to parse an address itself and get
 * it subtly wrong. See its docblock for the two ways that parse is easy to get wrong.
 *
 * ── ⚠️ RLS IS THE SCOPING, AND THAT DIVERGES FROM {@see CustomDomainService} ON PURPOSE ─────────────
 * That class carries an explicit `where('tenant_id', …)` on every query and says the filter IS the
 * isolation — because `domains` is RLS-EXEMPT, being the table read to decide which tenant a request is.
 * `sso_verified_domains` is under strict RLS, so the database refuses a cross-tenant row outright and a
 * hand-written predicate here would be a second, weaker copy of a guarantee that already holds. That is
 * `SsoUserProvisioner::membershipFor()`'s posture, stated rather than inherited silently.
 *
 * **It is asserted rather than assumed**: `SsoDomainVerificationTest` proves a domain verified by one
 * workspace does not authorise an assertion at another. A control whose isolation nobody demonstrated is a
 * control nobody is running.
 *
 * ── THE STATE MACHINE, WHICH IS `domains`' MINUS THE HALF THAT DOES NOT APPLY ───────────────────────
 *   claim()    ∅ → pending     mints a token. Grants NOTHING; the row exists so the token has a home.
 *   verify()   pending → verified   requires the TXT record. Idempotent, and never demotes.
 *   release()  any → ∅         the row is deleted and the proof is gone with it.
 *
 * There is no `activate()` and no operator gate. ADR-0012 §D6 keeps activation out of a tenant's hands
 * because putting respondents on a host needs a certificate installed by hand; nothing is served from an
 * email domain, so a workspace may finish this flow alone.
 *
 * ── ⚠️ NO AUDIT ROWS, AND THAT IS THE OPPOSITE OF {@see CustomDomainService}'s ANSWER ───────────────
 * That class audits `claim`, `makePrimary` and `release`. This one audits nothing YET, and the reason is
 * mechanical rather than principled: every verb here is reachable only from an artisan command, which has no
 * tenant context and — decisively — no actor, and `AuditLogger`'s contract calls a row with `user_id = null`
 * beside `is_system_action = false` malformed. That is verbatim the argument `CustomDomainService::activate()`
 * already makes for the artisan-only half of ITS surface. **When the tenant-facing card lands (filed as a
 * Lane A row), it brings an authenticated actor and the audit rows become both possible and owed** — this
 * paragraph is here so that increment does not have to rediscover why they are missing.
 */
final class SsoDomainService
{
    public function __construct(private readonly DnsTxtResolver $dns) {}

    /**
     * Reserve a domain for a workspace and mint its challenge token. The row grants nothing until
     * {@see self::verify()} succeeds.
     *
     * `firstOrNew` rather than `create`: `(tenant_id, domain)` is unique, and re-claiming a domain whose
     * verification has since failed is the ordinary way an admin retries. **A re-claim of a VERIFIED domain
     * is a no-op that returns the existing row** rather than rotating its token — rotating would revoke the
     * workspace's own proof of control as a side effect of a button press.
     */
    public function claim(Tenant $tenant, string $domain, ?User $actor = null): SsoVerifiedDomain
    {
        return DB::transaction(function () use ($tenant, $domain, $actor): SsoVerifiedDomain {
            $normalised = self::normaliseDomain($domain);

            $existing = $this->findForTenant($tenant, $normalised);

            if ($existing !== null && $existing->isVerified()) {
                return $existing;
            }

            $row = $existing ?? new SsoVerifiedDomain;
            $row->fill([
                'tenant_id' => (string) $tenant->getKey(),
                'domain' => $normalised,
                'created_by' => $actor?->getKey() === null ? null : (string) $actor->getKey(),
            ]);

            // 256-bit CSPRNG, random and never derived — `CustomDomainService::claim()`'s reasoning, which
            // holds identically here: an HMAC of (tenant, domain) would be unrotatable per claim and would
            // make an APP_KEY compromise a domain-claim primitive, for no benefit, since the token is
            // published in public DNS either way.
            //
            // Written through `forceFill` because the state columns are deliberately absent from
            // `$fillable` — see the model's docblock. A pending re-claim gets a FRESH token and a cleared
            // failure reason, so a retry never verifies against a value the admin has already replaced.
            $row->forceFill([
                'verification_token' => bin2hex(random_bytes(32)),
                'token_issued_at' => Carbon::now(),
                'verified_at' => null,
                'verification_checked_at' => null,
                'verification_failure_reason' => null,
            ])->save();

            return $row;
        });
    }

    /**
     * Look up the challenge TXT record and record the outcome. Returns whether the domain is verified AFTER
     * this attempt.
     *
     * NEVER THROWS on a DNS outcome — `CustomDomainService::verify()`'s rule, for the same reason: a record
     * that has not propagated yet is an ordinary business state, not a defect.
     *
     * ⚠️ A LOOKUP FAILURE NEVER DEMOTES AN ALREADY-VERIFIED DOMAIN, and here that matters more than it does
     * for a custom host. `verified_at` is what stands between an assertion and an account, so letting one
     * SERVFAIL retract it would turn somebody else's DNS outage into a sign-in outage for every new joiner
     * at that workspace. The null-versus-empty-array contract on {@see DnsTxtResolver::txt()} is what makes
     * the distinction expressible at all.
     */
    public function verify(SsoVerifiedDomain $row): bool
    {
        $records = $this->dns->txt($this->challengeName($row->domain));
        $now = Carbon::now();

        if ($records === null) {
            // "Could not ask" — evidence about us, not about the tenant.
            $row->forceFill([
                'verification_checked_at' => $now,
                'verification_failure_reason' => $row->isVerified() ? null : DomainVerificationFailure::LookupFailed,
            ])->save();

            return $row->isVerified();
        }

        $matched = in_array($this->expectedValue($row->verification_token), $records, true);

        $row->forceFill([
            'verification_checked_at' => $now,
            'verified_at' => $matched ? ($row->verified_at ?? $now) : $row->verified_at,
            // ⚠️ THE `$row->isVerified()` ARM IS WHAT KEEPS `sso_verified_domains_verified_has_no_failure_chk`
            // SATISFIABLE. A re-check of a verified domain that now reads NotFound must not write a failure
            // beside a live `verified_at` — the constraint would refuse the UPDATE and the re-check would
            // become an exception on a path whose whole contract is that it never throws.
            'verification_failure_reason' => match (true) {
                $matched, $row->isVerified() => null,
                $records === [] => DomainVerificationFailure::NotFound,
                default => DomainVerificationFailure::Mismatch,
            },
        ])->save();

        return $row->isVerified();
    }

    /** Delete the row. The proof goes with it, which is the point: this is how a workspace gives a domain up. */
    public function release(SsoVerifiedDomain $row): bool
    {
        return (bool) $row->delete();
    }

    /**
     * ⚠️ **THE AUTHENTICATION-PATH QUESTION. Everything else in this class exists to make this answerable.**
     *
     * Phrased over an EMAIL rather than a domain so no caller parses an address itself, because the parse is
     * wrong in two ways that both look right:
     *
     *   1. **`afterLast`, never `after`.** `filter_var(FILTER_VALIDATE_EMAIL)` accepts a quoted local part
     *      containing an `@` — `"a@b"@example.com` is a valid address — and splitting on the FIRST one yields
     *      `b"@example.com`, a domain nobody could ever have verified. The domain is what follows the LAST
     *      `@`, always.
     *   2. **Exact equality, never a suffix match.** A workspace that proved it controls `acme.test` has
     *      proved nothing about `mail.acme.test`: a subdomain can be delegated to a third party, so
     *      `str_ends_with()` here would hand an assertion authority over an address space the workspace may
     *      have given away. If per-subtree trust is ever wanted it is a deliberate feature with its own
     *      decision, not a relaxed comparison.
     *
     * The address arrives already lower-cased and `filter_var`-checked by {@see SsoIdentityResolver}, and is
     * lower-cased again here rather than trusted to be: this method is a security predicate, and a second
     * caller that skipped that normalisation would otherwise fail OPEN by matching nothing.
     */
    public function isVerifiedFor(string $email): bool
    {
        $domain = self::domainOf($email);

        if ($domain === null) {
            return false;
        }

        // RLS is the tenant scoping — see the class docblock. `verified_at` is the authority; a pending row
        // for the same domain answers false, which is why this asks the database rather than loading a row.
        return SsoVerifiedDomain::query()
            ->where('domain', $domain)
            ->whereNotNull('verified_at')
            ->exists();
    }

    /**
     * The domain half of an address, or null if it has none.
     *
     * Static and public because the refusal message names the domain, and re-deriving it at the throw site
     * is how two answers to one question start.
     */
    public static function domainOf(string $email): ?string
    {
        // Not `Str::contains`: an address that STARTS with '@' has an empty local part and no domain worth
        // the name, and `afterLast` would happily return the rest of it.
        if (! str_contains($email, '@')) {
            return null;
        }

        $domain = self::normaliseDomain(Str::afterLast($email, '@'));

        return $domain === '' ? null : $domain;
    }

    /**
     * One workspace's domains, newest first. Pending rows included — the listing exists to show them.
     *
     * @return Collection<int, SsoVerifiedDomain>
     */
    public function forTenant(Tenant $tenant): Collection
    {
        /** @var Collection<int, SsoVerifiedDomain> $rows */
        $rows = SsoVerifiedDomain::query()
            ->where('tenant_id', $tenant->getKey())
            ->orderByDesc('created_at')
            ->get();

        return $rows;
    }

    /** One workspace's row for a domain, or null. */
    public function findForTenant(Tenant $tenant, string $domain): ?SsoVerifiedDomain
    {
        /** @var SsoVerifiedDomain|null $row */
        $row = SsoVerifiedDomain::query()
            ->where('tenant_id', $tenant->getKey())
            ->where('domain', self::normaliseDomain($domain))
            ->first();

        return $row;
    }

    /** The DNS name the workspace publishes the challenge at. */
    public function challengeName(string $domain): string
    {
        $label = (string) config('saml.verified_domains.txt_record_name', '_meridian-sso');

        return $label.'.'.self::normaliseDomain($domain);
    }

    /** The exact TXT value that must appear at {@see self::challengeName()}. */
    public function expectedValue(string $token): string
    {
        return (string) config('saml.verified_domains.txt_value_prefix', 'meridian-sso-domain-verification=').$token;
    }

    /**
     * Lower-cased and trimmed of surrounding whitespace and of a trailing root dot.
     *
     * The trailing dot is not pedantry: `acme.test.` is the fully-qualified form and a perfectly ordinary
     * thing for an admin to paste out of a zone file, but `users.email` never carries one — so without this
     * a workspace could verify a domain that no assertion it ever receives can match, and the failure would
     * present as "I verified it and it still refuses me".
     */
    private static function normaliseDomain(string $domain): string
    {
        return rtrim(Str::lower(trim($domain)), '.');
    }
}
