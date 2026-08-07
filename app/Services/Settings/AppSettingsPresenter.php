<?php

declare(strict_types=1);

namespace App\Services\Settings;

use App\Enums\SettingKey;
use App\Http\Controllers\Tenant\PreferencesController;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Entitlements\EntitlementService;
use App\Support\Entitlements\ToggleableModules;
use App\Support\Platform\BuildInfo;

/**
 * The read side of the App Settings panels (PRD Feature #10) — Increment I5.
 *
 * A presenter rather than four more props assembled in {@see PreferencesController::show()}: that controller
 * already carries four dependencies and two personal-preference surfaces, and `scripts/controller-gate.php`
 * caps a controller method at 250 lines / complexity 10. It gains ONE dependency and ONE prop line here.
 *
 * Everything it returns is RESOLVED — the `settings` table is sparse, so a card that read raw rows would
 * have to know each key's default, and two cards would eventually disagree about one. The resolution lives
 * in {@see TenantSettingRegistry} and {@see SettingKey}, and this class only shapes it for the wire.
 */
final class AppSettingsPresenter
{
    public function __construct(
        private readonly TenantSettingRegistry $settings,
        private readonly EntitlementService $entitlements,
        private readonly BuildInfo $build,
    ) {}

    /**
     * @return array{
     *     can_manage: bool,
     *     access: array{invite_only: bool, require_two_factor: bool, signup_host: string, actor_enrolled: bool},
     *     maintenance: array{enabled: bool, message: ?string},
     *     modules: list<array{key: string, label: string, hint: string, plan_granted: bool, enabled: bool}>,
     *     about: array{version: string, commit: ?string, built_at: ?string, environment: string}
     * }
     */
    public function forSettings(Tenant $tenant, User $user): array
    {
        $canManage = $user->can('tenant.settings.manage');

        return [
            'can_manage' => $canManage,
            'access' => [
                'invite_only' => (bool) $this->settings->get(SettingKey::RegistrationInviteOnly),
                // I8a, PRD Feature #14's org-level enforcement policy. Enforced by
                // {@see \App\Http\Middleware\EnforceTenantTwoFactor} on the authenticated tenant group.
                'require_two_factor' => (bool) $this->settings->get(SettingKey::SecurityRequireTwoFactor),
                // Shown in the card's hint so "people can join by signing up" names WHERE, rather than
                // leaving an admin to guess which URL the setting is about.
                'signup_host' => $tenant->slug.'.'.(string) config('tenancy.central_domain'),
                // Whether the ADMIN LOOKING AT THE SWITCH is themselves enrolled. The card uses it to warn
                // that turning enforcement on will send them to the enrollment interstitial on their very
                // next request — true, recoverable, and alarming if it arrives unannounced.
                'actor_enrolled' => $user->two_factor_confirmed_at !== null,
            ],
            'maintenance' => [
                'enabled' => $tenant->isUnderMaintenance(),
                'message' => $tenant->maintenance_message,
            ],
            'modules' => $this->modules(),
            // Rendered for EVERY member, not just Owner/Admin: an About panel is a support aid ("which
            // version am I seeing?"), and gating it would mean the person reporting a bug is the one person
            // who cannot answer the first question they are asked.
            'about' => $this->build->toArray(),
        ];
    }

    /**
     * The module rows, with the two layers kept SEPARATE on the wire.
     *
     * `plan_granted` and `enabled` cannot be collapsed into one boolean, because the card has to say two
     * different things: a module the plan does not include is shown greyed with its plan reason, while one
     * the tenant has switched off is shown as a live switch in the off position. The shared `entitlements`
     * Inertia prop cannot answer this — {@see EntitlementService::snapshot()} reports the COMPOSED result,
     * which is exactly right for gating a nav item and exactly wrong for explaining why it is missing.
     *
     * @return list<array{key: string, label: string, hint: string, plan_granted: bool, enabled: bool}>
     */
    private function modules(): array
    {
        $plan = $this->entitlements->currentPlan();

        return array_map(function (string $key) use ($plan): array {
            return [
                'key' => $key,
                'label' => ToggleableModules::label($key),
                'hint' => ToggleableModules::hint($key),
                // A null plan (unseeded catalog — dev, and any test that does not seed one) reads as
                // granted, matching RequireFeature's own "no catalog ⇒ no enforcement" stance. Reading it
                // as denied would empty this card on every developer's machine.
                'plan_granted' => $plan === null || $plan->featureEnabled($key),
                'enabled' => $this->settings->moduleEnabled($key),
            ];
        }, ToggleableModules::KEYS);
    }
}
