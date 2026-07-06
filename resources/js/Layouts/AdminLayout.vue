<script setup lang="ts">
/**
 * Central-domain super-admin console shell (RBAC §9). A minimal top-bar + content frame for the
 * platform-operations pages (Tenants, Users), distinct from the tenant AppLayout whose sidebar is
 * tenant-oriented — a documented member of the Console-shell family, NOT a third top-level shell
 * (see docs/ux/exceptions-log.md #4). Central admin pages self-lay-out (they are excluded from the
 * app.ts resolver), so each renders <AdminLayout> in its template. Composes shared tokens/components.
 */
import { computed, watch } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { MdsToastHost } from '@meridian/design-system';
import { useToast } from '@/composables/useToast';

defineProps<{ title: string }>();

const page = usePage();
const email = computed(() => page.props.auth.user?.email ?? '');

const { toasts, push, dismiss } = useToast();
watch(
    () => page.props.flash?.toast,
    (toast) => {
        if (toast) push(toast.type, toast.message);
    },
    { immediate: true },
);

const links = [
    { label: 'Tenants', href: '/admin/tenants' },
    { label: 'Users', href: '/admin/users' },
];

function isActive(href: string): boolean {
    return page.url.split('?')[0].startsWith(href);
}

function logout(): void {
    router.post('/logout');
}
</script>

<template>
    <Head :title="`${title} · Admin`" />
    <div class="admin">
        <header class="admin__bar">
            <div class="admin__brand">
                <span class="admin__wordmark">Meridian</span>
                <span class="admin__tag">Admin</span>
            </div>
            <nav class="admin__nav" aria-label="Admin sections">
                <Link
                    v-for="link in links"
                    :key="link.href"
                    :href="link.href"
                    class="admin__link"
                    :class="{ 'is-active': isActive(link.href) }"
                    :aria-current="isActive(link.href) ? 'page' : undefined"
                >
                    {{ link.label }}
                </Link>
            </nav>
            <div class="admin__right">
                <span class="admin__email">{{ email }}</span>
                <button type="button" class="admin__logout" @click="logout">Log out</button>
            </div>
        </header>

        <main class="admin__content">
            <div class="admin__inner">
                <h1 class="admin__title">{{ title }}</h1>
                <slot />
            </div>
        </main>
        <MdsToastHost :toasts="toasts" @dismiss="dismiss" />
    </div>
</template>

<style scoped>
.admin {
    display: flex;
    flex-direction: column;
    min-height: 100vh;
    min-height: 100dvh;
    background-color: var(--mds-color-bg-canvas);
}

.admin__bar {
    display: flex;
    align-items: center;
    gap: var(--mds-space-6);
    height: 56px;
    padding: 0 var(--mds-space-6);
    background-color: var(--mds-color-bg-surface);
    border-bottom: 1px solid var(--mds-color-border-default);
}

.admin__brand {
    display: flex;
    align-items: baseline;
    gap: var(--mds-space-2);
}

.admin__wordmark {
    font-family: var(--mds-font-family-display);
    font-size: var(--mds-type-heading-4-font-size);
    font-weight: var(--mds-font-weight-bold);
    text-transform: uppercase;
    letter-spacing: 0.03em;
    color: var(--mds-color-action-primary-fg);
}

.admin__tag {
    padding: var(--mds-space-0-5) var(--mds-space-1);
    border-radius: var(--mds-radius-sm);
    background-color: var(--mds-color-bg-sunken);
    color: var(--mds-color-text-secondary);
    font-size: var(--mds-type-caption-font-size);
    font-weight: var(--mds-font-weight-semibold);
    text-transform: uppercase;
    letter-spacing: 0.04em;
}

.admin__nav {
    display: flex;
    gap: var(--mds-space-1);
    flex: 1;
}

.admin__link {
    padding: var(--mds-space-2) var(--mds-space-3);
    border-radius: var(--mds-radius-md);
    color: var(--mds-color-text-body);
    font-size: var(--mds-type-body-md-font-size);
    font-weight: var(--mds-font-weight-medium);
    text-decoration: none;
}
.admin__link:hover:not(.is-active) {
    background-color: var(--mds-color-bg-sunken);
}
.admin__link:focus-visible {
    outline: 2px solid var(--mds-color-focus-ring);
    outline-offset: 2px;
}
.admin__link.is-active {
    background-color: var(--mds-color-action-primary-tint);
    color: var(--mds-color-text-heading);
    font-weight: var(--mds-font-weight-semibold);
}

.admin__right {
    display: flex;
    align-items: center;
    gap: var(--mds-space-3);
}

.admin__email {
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-secondary);
}

.admin__logout {
    min-height: 32px;
    padding: 0 var(--mds-space-3);
    border: 1px solid var(--mds-color-border-strong);
    border-radius: var(--mds-radius-md);
    background-color: transparent;
    color: var(--mds-color-text-body);
    font: inherit;
    font-size: var(--mds-type-body-sm-font-size);
    cursor: pointer;
}
.admin__logout:hover {
    background-color: var(--mds-color-bg-sunken);
}
.admin__logout:focus-visible {
    outline: 2px solid var(--mds-color-focus-ring);
    outline-offset: 2px;
}

.admin__content {
    flex: 1;
}

.admin__inner {
    max-width: 1100px;
    margin: 0 auto;
    padding: var(--mds-space-8) var(--mds-space-6);
}

.admin__title {
    margin: 0 0 var(--mds-space-6);
    font-family: var(--mds-font-family-display);
    font-size: var(--mds-type-heading-1-font-size);
    line-height: var(--mds-type-heading-1-line-height);
    font-weight: var(--mds-type-heading-1-font-weight);
    text-transform: uppercase;
    letter-spacing: 0.02em;
    color: var(--mds-color-text-heading);
}

@media (max-width: 480px) {
    .admin__bar {
        flex-wrap: wrap;
        height: auto;
        gap: var(--mds-space-3);
        padding: var(--mds-space-3) var(--mds-space-4);
    }
    .admin__email {
        display: none;
    }
    .admin__inner {
        padding: var(--mds-space-6) var(--mds-space-4);
    }
}
</style>
