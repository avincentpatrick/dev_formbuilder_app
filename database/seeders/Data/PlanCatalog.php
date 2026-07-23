<?php

declare(strict_types=1);

namespace Database\Seeders\Data;

use App\Enums\PlanTier;
use App\Enums\UsageMetric;

/**
 * The five-tier plan catalog seeded into `plans` (H5a / ADR-0008). Feature flags come from
 * pricing-feature-gating-matrix.md §3 with the ADR-0008 §D7 additions (`native_connectors`/`branding` =
 * Starter+, `custom_domain` = Business); quota numbers from §2. **Every number here is an unpinned planning
 * default** (the matrix says so explicitly — re-verify against real usage before a pricing page ships), and
 * dollar prices are deliberately left NULL (real pricing is a Phase-4 commercial decision, matrix §6).
 *
 * `business`/`enterprise` are seeded `is_active = false` — built + gated but **held from sale** until Track B
 * exists (ADR-0008 §D6); a super-admin can still assign them. Their quotas are unlimited placeholders pending
 * real pricing.
 */
final class PlanCatalog
{
    /** Every feature-gate key (pricing-feature-gating-matrix.md §3 + ADR-0008 §D7). */
    private const array FEATURE_KEYS = [
        'api_access',
        'webhooks',
        'xlsform_export',
        'offline_sync',
        'ocr_single',
        'ocr_linelist',
        'save_and_resume',
        'native_connectors',
        'branding',
        'custom_domain',
        'advanced_analytics',
        'embedded_payments',
        'sso_saml',
        'dedicated_db',
        'data_residency',
    ];

    // Storage quotas in bytes (binary units). 500 MiB / 5 GiB / 50 GiB.
    private const int MIB = 1024 * 1024;

    private const int GIB = 1024 * 1024 * 1024;

    /**
     * @return list<array<string, mixed>>
     */
    public static function all(): array
    {
        $starter = ['api_access', 'webhooks', 'xlsform_export', 'offline_sync', 'save_and_resume', 'native_connectors', 'branding'];
        $professional = [...$starter, 'ocr_single', 'ocr_linelist', 'embedded_payments'];
        $business = [...$professional, 'custom_domain', 'advanced_analytics'];
        $enterprise = [...$business, 'sso_saml', 'dedicated_db', 'data_residency'];

        return [
            self::tier(
                PlanTier::Free,
                'Free',
                'Get started with a few forms and light usage.',
                sort: 0,
                active: true,
                enabled: [],
                quotas: [
                    'forms_count' => 3,
                    'submissions_count' => 100,
                    'storage_bytes' => 500 * self::MIB,
                    'active_seats' => 2,
                    'api_requests' => 0,
                    'webhook_deliveries' => 0,
                    'exports_count' => 5,
                ],
            ),
            self::tier(
                PlanTier::Starter,
                'Starter',
                'For small teams collecting data online and offline.',
                sort: 1,
                active: true,
                enabled: $starter,
                quotas: [
                    'forms_count' => 20,
                    'submissions_count' => 2000,
                    'storage_bytes' => 5 * self::GIB,
                    'active_seats' => 10,
                    'api_requests' => 10000,
                    'webhook_deliveries' => 5000,
                    'exports_count' => null, // unlimited
                ],
            ),
            self::tier(
                PlanTier::Professional,
                'Professional',
                'For growing organizations with OCR and higher volume.',
                sort: 2,
                active: true,
                enabled: $professional,
                quotas: [
                    'forms_count' => null, // unlimited
                    'submissions_count' => 20000,
                    'storage_bytes' => 50 * self::GIB,
                    'active_seats' => 50,
                    'api_requests' => 100000,
                    'webhook_deliveries' => 50000,
                    'exports_count' => null, // unlimited
                ],
            ),
            self::tier(
                PlanTier::Business,
                'Business',
                'Custom domains and advanced analytics. Built and gated; held from sale until the production host is stood up (ADR-0008 §D6).',
                sort: 3,
                active: false, // held from sale — assignable by a super-admin only
                enabled: $business,
                quotas: self::unlimited(),
            ),
            self::tier(
                PlanTier::Enterprise,
                'Enterprise',
                'SSO, dedicated tenancy, and data residency. Activates in Phase 4.',
                sort: 4,
                active: false,
                enabled: $enterprise,
                quotas: self::unlimited(),
            ),
        ];
    }

    /**
     * @param  list<string>  $enabled  feature keys set to true (all others default false)
     * @param  array<string, int|null>  $quotas  UsageMetric key => limit (null ⇒ unlimited)
     * @return array<string, mixed>
     */
    private static function tier(
        PlanTier $code,
        string $name,
        string $description,
        int $sort,
        bool $active,
        array $enabled,
        array $quotas,
    ): array {
        $flags = array_fill_keys(self::FEATURE_KEYS, false);

        foreach ($enabled as $key) {
            $flags[$key] = true;
        }

        return [
            'code' => $code,
            'name' => $name,
            'description' => $description,
            'stripe_price_id' => null,
            // Dollar prices are a Phase-4 commercial decision (matrix §6) — left NULL rather than invented.
            'monthly_price_cents' => null,
            'yearly_price_cents' => null,
            'currency' => 'usd',
            'billing_interval_options' => ['monthly', 'yearly'],
            'feature_flags' => $flags,
            'quotas' => $quotas,
            'is_active' => $active,
            'sort_order' => $sort,
        ];
    }

    /**
     * Unlimited placeholder quotas for the not-yet-for-sale tiers.
     *
     * @return array<string, null>
     */
    private static function unlimited(): array
    {
        return array_fill_keys(UsageMetric::values(), null);
    }
}
