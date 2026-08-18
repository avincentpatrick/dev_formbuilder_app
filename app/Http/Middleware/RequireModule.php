<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\Entitlements\ModuleDisabledException;
use App\Services\Entitlements\EntitlementService;
use App\Services\Settings\TenantSettingRegistry;
use App\Support\Entitlements\ToggleableModules;
use Closure;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The tenant's own module toggle as a route gate — `module:<key>` (K1d; gamification-design.md §9,
 * ADR-0020 Consequences).
 *
 * ── ⚠️ THIS IS NOT {@see RequireFeature} AND MUST NOT BECOME IT ─────────────────────────────────────────
 * `feature:<key>` answers *"does this tenant's PLAN include the capability"* and refuses with
 * *"Upgrade your plan"*. This answers the narrower and completely different question *"has this tenant
 * switched the capability OFF for itself"*, and refuses with something the reader can act on. ADR-0020 §D6
 * grants `gamification` on every tier including Free, so for that key the plan half can never be the thing
 * that refuses — mounting `feature:gamification` would produce a 402 telling a paying tenant to buy
 * something they already have, which is why doc #28 §9 forbids it by name and why this class exists.
 *
 * ⚠️ **IT READS THE TOGGLE DIRECTLY AND DELIBERATELY NOT {@see EntitlementService::feature()}**, which
 * composes plan AND toggle and would drag both halves of that confusion back in. Two consequences follow,
 * and both are wanted. The refusal can only ever mean the one thing the message says. And the gate stays
 * meaningful in the plan-free test suite, where `feature()` fail-closes to `false` off-catalog — the very
 * hazard `RequireFeature` needs its `currentPlan() !== null` escape for, and which would otherwise make
 * every gated route here 403 for reasons having nothing to do with a toggle.
 *
 * ⚠️ **OFF-TENANT THIS GATE PASSES, AND THAT IS THE STANCE RATHER THAN A HOLE.**
 * `TenantSettingRegistry::all()` returns an empty map with no tenant GUC, so `moduleEnabled()` falls
 * through to its default of ON. That is deliberately the same "no context to enforce against ⇒ no
 * enforcement" posture {@see RequireFeature} takes for a null plan, and it is safe for the same reason:
 * every route this can be mounted on already sits behind tenant-context middleware, so a guest cannot
 * reach one. Failing CLOSED here would instead make an unresolvable tenant look like a tenant that had
 * switched the module off — the wrong message for the second time in one feature.
 *
 * **PAIRING IS THE ROUTE'S JOB, NOT THIS CLASS'S.** A capability whose plan genuinely withholds it wants
 * `feature:<key>` as well; `gamification` wants this alone, because no plan withholds it. Stating it here
 * so nobody later "fixes" this class by folding the plan check back in and re-creating the wrong sentence.
 */
final class RequireModule
{
    public function __construct(private readonly TenantSettingRegistry $settings) {}

    public function handle(Request $request, Closure $next, string $module): Response
    {
        // A key outside the toggleable catalog can never be switched off, so this gate would pass forever
        // while looking like it guarded something — the vacuous-gate shape this project has now measured
        // several times. Loud at the first request rather than silent for the life of the route.
        if (! ToggleableModules::isToggleable($module)) {
            throw new InvalidArgumentException("[{$module}] is not a toggleable module, so module: cannot gate it.");
        }

        if (! $this->settings->moduleEnabled($module)) {
            throw ModuleDisabledException::forModule($module);
        }

        return $next($request);
    }
}
