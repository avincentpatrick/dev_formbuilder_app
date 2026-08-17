<?php

declare(strict_types=1);

namespace App\Exceptions\Entitlements;

use App\Exceptions\Authorization\GrantException;
use App\Http\Middleware\RequireFeature;
use RuntimeException;

/**
 * A plan feature-gate refusal (H5c / ADR-0008 §D5) — a tenant reached a capability its plan does not include
 * (`xlsform_export`, `offline_sync`, `form_templates`, `field_library`, `api_access`), raised by
 * {@see RequireFeature}. A grandfathered tenant's legacy override resolves the feature to
 * `true`, so a grandfathered tenant never reaches here.
 *
 * The status is 402 Payment Required ("upgrade to unlock"), deliberately the same entitlement-family status as
 * {@see QuotaExceededException} and distinct from a 403 authorization refusal ({@see GrantException}): the
 * caller is authorized, their plan simply doesn't carry the feature. The code is stable and generic
 * (`feature_not_available`) with the specific key in `details.feature`, so an integration consumer branches on
 * the detail rather than a proliferation of codes.
 */
final class FeatureGateException extends RuntimeException
{
    private function __construct(string $message, private readonly string $feature)
    {
        parent::__construct($message);
    }

    public static function forKey(string $key): self
    {
        $label = match ($key) {
            'xlsform_export' => 'XLSForm import/export',
            'offline_sync' => 'offline data collection',
            'form_templates' => 'form templates',
            'field_library' => 'the question library',
            'api_access' => 'the REST API',
            'webhooks' => 'webhooks',
            'native_connectors' => 'native integrations like Slack',
            // ADR-0011 §D9 assigns this arm to H24a by name: without it a 402 body renders the raw
            // snake_case key in user-facing copy.
            'advanced_analytics' => 'advanced cross-form analytics',
            // P1a / ADR-0016. Lowercase sentence fragment, not the console's title-case 'SSO (SAML)'
            // (TenantDetailPresenter:351) — this one has to read inside "Your plan doesn't include …".
            'sso_saml' => 'single sign-on (SAML)',
            // K1a. The arm exists so a 402 body can never render the raw snake_case key (ADR-0011 §D9's
            // rule), NOT because this gate is expected to fire on a plan refusal — `gamification` is granted
            // on EVERY tier, so the only way to reach here is a tenant that switched the module off itself,
            // and "Upgrade your plan to use it" is then the wrong sentence. ⚠️ That is why K1d must gate the
            // gamification surfaces on the module toggle rather than mounting `feature:gamification` as a
            // route middleware; see gamification-design.md §7. The wrong-copy-on-self-disable shape is
            // pre-existing and shared with all eleven toggleable keys — it is recorded here, not fixed here.
            'gamification' => 'points, badges and streaks',
            default => $key,
        };

        return new self("Your plan doesn't include {$label}. Upgrade your plan to use it.", $key);
    }

    public function feature(): string
    {
        return $this->feature;
    }

    /** The stable, machine-readable error code (branch on `details.feature` for the specific capability). */
    public function code(): string
    {
        return 'feature_not_available';
    }

    /** The HTTP status for the /api/v1 envelope — 402 Payment Required ("upgrade to unlock"). */
    public function status(): int
    {
        return 402;
    }

    /**
     * @return array{feature: string}
     */
    public function details(): array
    {
        return ['feature' => $this->feature];
    }
}
