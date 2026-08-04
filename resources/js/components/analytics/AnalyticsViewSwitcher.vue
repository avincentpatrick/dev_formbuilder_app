<script setup lang="ts">
// The Overview ⇄ Analytics switcher (Increment H24b2), mounted on BOTH /dashboard and /analytics — which
// is what makes it a switcher rather than a one-way link.
//
// ADR-0011 §D9: the control is HIDDEN, never rendered locked with an upgrade call-to-action. Business is
// seeded `is_active: false` (held from sale until the production host is stood up, ADR-0008 §D6), so an
// upgrade CTA would point at a plan nobody can buy — a dead end presented as an offer. There is no
// "Upgrade" branch in this file and there must not be one.
//
// The two conditions are ANDed exactly as Sidebar.vue ANDs a NavItem's `gate` and `feature`, so the
// switcher and the nav item can never disagree about whether the destination exists.
import { computed } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { MdsSegmentedControl, type IconName } from '@meridian/design-system';

defineProps<{ current: 'overview' | 'analytics' }>();

const page = usePage();

const entitled = computed(
    () =>
        page.props.auth.can.viewAnalytics &&
        page.props.entitlements?.features?.advanced_analytics === true,
);

// `SegmentOption` is not exported from @meridian/design-system (the component is, the option type is not),
// so the shape is inlined — the same thing Settings/Index.vue and members/Index.vue do.
const options: { value: string; label: string; icon?: IconName }[] = [
    { value: 'overview', label: 'Overview', icon: 'dashboard' },
    { value: 'analytics', label: 'Analytics', icon: 'chart-bar' },
];

function go(value: string): void {
    router.visit(value === 'analytics' ? '/analytics' : '/dashboard');
}
</script>

<template>
    <MdsSegmentedControl
        v-if="entitled"
        :model-value="current"
        :options="options"
        ariaLabel="Dashboard view"
        compact
        @update:model-value="go"
    />
</template>
