<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Enums\DomainVerificationFailure;
use App\Http\Resources\Api\V1\DomainResource;
use App\Models\Domain;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Entitlements\EntitlementService;
use App\Services\Webhooks\WebhookEndpointPresenter;
use App\Support\Tenancy\TenantUrl;
use Illuminate\Support\Carbon;

/**
 * Read model for the custom-domain admin UI (Increment H22b / ADR-0012).
 *
 * Field names mirror {@see DomainResource} 1:1 so the web and `/api/v1` surfaces
 * describe the same records — but the mapping is INLINE rather than reusing that resource, on the
 * {@see WebhookEndpointPresenter} precedent: a web presenter must not couple to an
 * API-versioned class, and this one adds three derivations the resource has no business carrying.
 *
 * ⚠️ `is_public_host` IS READ FROM {@see TenantUrl::publicHost()}, NEVER RE-DERIVED. That
 * method is the one definition of "which host do respondents actually get", and it is not simply
 * `is_primary`: a tenant that has expressed no preference still has a public host, chosen by the
 * oldest-activated fallback. Re-implementing the rule here would produce a page that labels the right row
 * today and the wrong one the first time the fallback matters — the failure mode being a respondent link
 * that does not match what the page said it would be.
 *
 * ⚠️ `entitled` IS NOT A GATE, IT IS A DISCLOSURE. ADR-0012 §D9 leaves reads and deletes ungated on purpose:
 * a tenant downgraded off Business keeps a LIVE, resolving hostname and must still be able to see it and
 * take it down. So this flag hides the claim/verify/primary affordances and nothing else — and it is
 * deliberately not paired with an upgrade CTA, because ADR-0008 §D6 seeds Business `is_active = false` and
 * a call to action pointing at an unbuyable plan is worse than silence.
 */
final class DomainPresenter
{
    public function __construct(
        private readonly CustomDomainService $domains,
        private readonly EntitlementService $entitlements,
    ) {}

    /**
     * The tenant's custom domains, the DNS record each one needs, and what this user may do about them.
     *
     * @return array<string, mixed>
     */
    public function index(User $user, Tenant $tenant): array
    {
        $publicHost = TenantUrl::publicHost($tenant);
        $manage = $user->can('tenant.settings.manage');
        $entitled = $this->entitlements->feature('custom_domain');

        return [
            'data' => $this->domains->forTenant($tenant)
                ->map(fn (Domain $domain): array => $this->row($domain, $publicHost))
                ->all(),
            // The app host, shown as the always-available fallback so the page can say what respondents get
            // TODAY rather than only what they would get once a custom domain goes live.
            'appHost' => TenantUrl::appHost($tenant),
            'publicHost' => $publicHost,
            'can' => [
                // `create` and `manage` differ ONLY by the plan feature. Splitting them is what lets a
                // downgraded tenant keep the Remove button while losing Add domain / Check DNS / Make primary.
                'create' => $manage && $entitled,
                'manage' => $manage,
            ],
            'entitled' => $entitled,
        ];
    }

    /**
     * One domain row. `status`, `verification` and `awaiting_operator` are the resource's own vocabulary,
     * reproduced rather than reinvented (ADR-0012 turned the derivation into a word exactly once).
     *
     * **Public since I7b, and the visibility boundary is exactly the point.** The super-admin console's
     * tenant-detail page needs these rows for a tenant it is not "inside", and it calls `row()` DIRECTLY
     * rather than {@see index()}. That is not a shortcut — `index()` is TENANT-REQUEST-ONLY. It calls
     * `EntitlementService::feature()` and `$user->can()`, and BOTH resolve false on the central host where
     * there is no tenant context, so reusing it there would silently report every workspace as un-entitled
     * and un-manageable. `row()` touches only the {@see Domain} model, {@see TenantUrl::publicHost()} and
     * two pure {@see CustomDomainService} helpers, so it is safe from anywhere the caller already holds the
     * right rows — and `CustomDomainService::forTenant()` is itself context-free (`domains` is RLS-exempt;
     * its explicit `where('tenant_id')` IS the isolation).
     *
     * @return array<string, mixed>
     */
    public function row(Domain $domain, string $publicHost): array
    {
        $token = (string) $domain->verification_token;

        return [
            'domain' => $domain->domain,
            'status' => match (true) {
                $domain->activated_at !== null => 'live',
                $domain->verified_at !== null => 'verified',
                default => 'pending',
            },
            'verification' => [
                'type' => 'TXT',
                'name' => $this->domains->challengeName($domain->domain),
                'value' => $this->domains->expectedValue($token),
            ],
            'verified_at' => $this->iso($domain->verified_at),
            'activated_at' => $this->iso($domain->activated_at),
            'last_checked_at' => $this->iso($domain->verification_checked_at),
            'failure_reason' => $domain->verification_failure_reason?->value,
            // The counter-intuitive state, stated as a field rather than left to the reader's arithmetic:
            // control is proven and the hostname still serves nothing until an operator activates it.
            'awaiting_operator' => $domain->verified_at !== null && $domain->activated_at === null,
            'is_primary' => (bool) $domain->is_primary,
            'is_public_host' => $domain->domain === $publicHost,
            // A live row that is not already the primary is the only thing the radio may offer. The service
            // refuses everything else and the CHECK refuses it underneath, so this is presentation only.
            'can_be_primary' => $domain->activated_at !== null && ! $domain->is_primary,
            'failure_hint' => $this->failureHint($domain->verification_failure_reason),
        ];
    }

    /**
     * The last DNS outcome as an instruction rather than an enum value.
     *
     * The three cases are NOT interchangeable, which is the reason {@see DomainVerificationFailure} splits
     * them at all: `not_found` is the ordinary "not propagated yet" state and the tenant should wait;
     * `mismatch` means somebody published the wrong value and the tenant must fix it; `lookup_failed` is
     * evidence about US and the tenant should be told not to go changing anything.
     */
    private function failureHint(?DomainVerificationFailure $reason): ?string
    {
        return match ($reason) {
            DomainVerificationFailure::NotFound => 'No TXT record found at that name yet. DNS changes can take up to an hour to propagate — publish the record below, then check again.',
            DomainVerificationFailure::Mismatch => 'A TXT record exists at that name, but none of its values match. Check for a typo, and remove any older verification record you published for us.',
            DomainVerificationFailure::LookupFailed => 'We could not reach your nameservers on the last attempt. Nothing is wrong with your record — we will keep trying.',
            null => null,
        };
    }

    private function iso(?Carbon $value): ?string
    {
        return $value?->toIso8601String();
    }
}
