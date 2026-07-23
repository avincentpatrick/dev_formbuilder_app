import { usePage } from '@inertiajs/vue3';

/**
 * Read the tenant's entitlement snapshot (H5c) for client-side gating — hiding/disabling an affordance the
 * server would refuse with a 402. The server gate ({@see \App\Http\Middleware\RequireFeature}) is the real
 * enforcement boundary; this is UX polish so a user never clicks a button that then fails.
 *
 * Fail-closed: off-tenant the `entitlements` prop is null, so every feature reads `false`. A grandfathered
 * tenant needs no special handling — the server already merges the legacy override into `features`, so its
 * snapshot reads `true`. Reactive: reads through `usePage().props`, so `feature()` in a `v-if` re-evaluates
 * when props refresh after a navigation.
 */
export function useEntitlements(): { feature: (key: string) => boolean } {
    const page = usePage();

    return {
        feature: (key: string): boolean => page.props.entitlements?.features?.[key] === true,
    };
}
