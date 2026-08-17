<?php

declare(strict_types=1);

namespace App\Support\Entitlements;

use App\Http\Middleware\RequireFeature;
use App\Services\Entitlements\EntitlementService;

/**
 * "Would the ROUTE admit this feature?" — the one predicate any surface must use before offering a link to
 * a feature-gated destination (extracted in J5; written in J4b2).
 *
 * ── WHY THIS IS NOT SIMPLY `EntitlementService::feature()` ──────────────────────────────────────────────
 * The two disagree in one reachable state, and the disagreement is a sign flip rather than a nuance.
 * {@see RequireFeature} admits a request when there is no plan catalog at all —
 * `currentPlan() !== null && ! feature($key)` — which its own docblock calls a "dev/test has no plans"
 * pass-through rather than a fail-open. {@see EntitlementService::feature()} returns **false** in that same
 * state, because `currentPlan()?->featureEnabled() ?? false` cannot distinguish *denied* from *nothing to
 * ask*.
 *
 * So a surface built on `feature()` alone is **strictly stricter than the route it mirrors**: the request
 * is admitted, the page renders 200, and the affordance that should have led there is withheld. J4b2 found
 * that live, on a breadcrumb whose root went inert on a page the middleware had just let through — and it
 * had been live in the green suite, because two existing test files assert 200 on those routes with no plan
 * seeded and nothing asserted on the crumbs.
 *
 * The unseeded-catalog state is not hypothetical: a deploy that migrates before it seeds, a restored
 * database, and every feature test that does not seed a plan.
 *
 * ── THE RULE, WHICH IS THE PART WORTH CARRYING ─────────────────────────────────────────────────────────
 * **A surface must offer exactly what the route accepts.** More permissive hands out links that bounce;
 * stricter hands out dead ends. Both are defects, and only a mirror avoids both.
 *
 * ── WHY IT LIVES HERE RATHER THAN STAYING PRIVATE TO ITS FIRST CALLER ──────────────────────────────────
 * J4b2 wrote it as a private static on {@see \App\Support\Navigation\CrumbTrail}. J5 needs the identical
 * question for the dashboard's first-run template choice, whose destination carries `feature:form_templates`.
 * Copying four lines would have created a second definition of "does the route admit this?", and two such
 * definitions drift — which is the failure this codebase has already paid for with two ADRs numbered 0017
 * and a duplicate migration prefix. One authority, referenced rather than copied.
 *
 * ⚠️ **A PLAIN STATIC, NOT AN INJECTED SERVICE, AND DELIBERATELY SO.** It holds no state and takes its
 * collaborator as an argument, so it cannot acquire a container dependency that makes it unusable from the
 * static context `CrumbTrail` calls it in. If it ever needs more than the two reads below, that is the
 * signal it has become policy rather than a mirror — and policy belongs beside the middleware.
 */
final class FeatureAdmission
{
    /**
     * True when {@see RequireFeature} would let a request through for `$key`.
     *
     * ⚠️ The `null` arm comes FIRST for readability, but the order is not load-bearing and must not be
     * "optimised" into something that calls `feature()` first — that call is the expensive half (it reads
     * the legacy overrides and the module toggles) and it is exactly the one the null case does not need.
     */
    public static function admits(EntitlementService $entitlements, string $key): bool
    {
        return $entitlements->currentPlan() === null || $entitlements->feature($key);
    }
}
