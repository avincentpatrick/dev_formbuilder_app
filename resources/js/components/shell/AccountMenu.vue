<script setup lang="ts">
/**
 * Account menu — avatar-initials trigger opening the shared `MdsMenu` (name/email header, Settings link,
 * Log out). Log out goes through Inertia rather than a raw form.
 *
 * ⚠️ EVERY BEHAVIOUR THIS FILE USED TO OWN NOW LIVES IN THE PRIMITIVE (J4b), AND ONE OF THEM WAS WRONG.
 * The hand-rolled version put this header INSIDE `role="menu"`, whose children must all be menuitems — so
 * a screen reader in application mode walked the items and never announced it, and the signed-in identity
 * was unreachable to precisely the readers who most need it. `MdsMenu` renders the header as a sibling and
 * wires it as the menu's description, so entering the menu announces it. Nothing moved visually.
 *
 * The defect survived because nothing could see it: an application-tree component gets no Storybook story
 * and therefore no accessibility scan, and no end-to-end spec ever opened this menu. Moving the markup into
 * the package is what put it under the merge-blocking axe job for the first time.
 *
 * `useDismissable` is deliberately no longer imported here — the primitive owns dismissal, and its Escape
 * binds on its own root rather than on `document` so a menu inside a dialog closes itself rather than the
 * dialog. The composable keeps `NotificationBell` and `FeedbackButton` as consumers.
 */
import { computed, defineComponent, h } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { MdsAvatar, MdsIcon, MdsMenu, type MenuItem } from '@meridian/design-system';

const page = usePage();
const user = computed(() => page.props.auth.user);

/**
 * M207 (`D179`): BHW Connect, the second application on this address (ADR-0024), in the top bar's menu for
 * signed-in users. Here and not as a control in the bar itself: the bar's width is measured to the pixel
 * (ThemeQuickToggle's note), and a labelled link there spilled the theme toggle at 1440px under the largest
 * type scale — `search-nav.spec.ts` caught it. The footer carries the same link under every page.
 *
 * Its item is a plain anchor, because another server answers /bhw-connect and an Inertia visit would get a
 * page that is no Inertia response. Every other item stays Inertia's Link, with `href` a real prop.
 */
const BHW_CONNECT_HREF = '/bhw-connect/';

const MenuLink = defineComponent({
    props: { href: { type: String, required: true } },
    setup(props, { slots }) {
        return () =>
            props.href === BHW_CONNECT_HREF
                ? h('a', { href: props.href }, slots.default?.())
                : h(Link, { href: props.href }, slots.default);
    },
});

const items: MenuItem[] = [
    { id: 'settings', label: 'Settings', icon: 'settings', href: '/settings' },
    // M175 (`R-9bfd832e`) — every member's own reports, with the support team's replies. Here rather than in
    // the sidebar: it is about the person, like Settings, and every role may open it.
    // M179: the page now holds the testing-round comments too, and an Owner's sidebar already has "Feedback"
    // (the workspace's list) — so the account menu names this one apart.
    { id: 'my-feedback', label: 'Feedback & testing', icon: 'feedback', href: '/feedback/mine' },
    { id: 'bhw-connect', label: 'BHW Connect', icon: 'chart-bar', href: BHW_CONNECT_HREF },
    { id: 'logout', label: 'Log out', icon: 'logout' },
];

function onSelect(id: string): void {
    if (id === 'logout') router.post('/logout');
}
</script>

<template>
    <MdsMenu
        trigger-label="Account menu"
        label="Account"
        :items="items"
        :link-component="MenuLink"
        @select="onSelect"
    >
        <template #trigger>
            <MdsAvatar :name="user?.name ?? null" size="md" />
            <MdsIcon name="chevron-down" size="sm" aria-hidden="true" />
        </template>
        <template #header>
            <p class="account__name">{{ user?.name }}</p>
            <p class="account__email">{{ user?.email }}</p>
        </template>
    </MdsMenu>
</template>

<style scoped>
/* Only the header's own typography stays local — the trigger, the panel and the items are the primitive's
   now, and their rules went with the markup. A migration deletes the old scoped CSS as well (DSR §3.4):
   dead rules for elements that are no longer on the page read to the next author as a component still in
   use, which is exactly what `.detail__back` did to the breadcrumb migration twice. */
.account__name {
    margin: 0;
    font-weight: var(--mds-font-weight-semibold);
    color: var(--mds-color-text-heading);
}

.account__email {
    margin: 0;
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-secondary);
}
</style>
