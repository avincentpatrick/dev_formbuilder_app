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
import { computed } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { MdsAvatar, MdsIcon, MdsMenu, type MenuItem } from '@meridian/design-system';

const page = usePage();
const user = computed(() => page.props.auth.user);

const items: MenuItem[] = [
    { id: 'settings', label: 'Settings', icon: 'settings', href: '/settings' },
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
        :link-component="Link"
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
