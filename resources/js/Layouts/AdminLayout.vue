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
import { MdsIcon, MdsToastHost, type IconName } from '@meridian/design-system';
import { useToast } from '@/composables/useToast';

defineProps<{ title: string; icon?: IconName }>();

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
    // I7a — the feedback support queue (PRD Feature #11). Read-and-triage, so it sits with the other
    // read surfaces rather than beside the destructive one.
    { label: 'Feedback', href: '/admin/feedback' },
    // I7b — the platform ledger (PRD Feature #12). A read surface, so it sits with the others.
    { label: 'Audit log', href: '/admin/audit-log' },
    // I5 — platform settings (PRD Feature #10). Last, because it is the only destructive one here.
    { label: 'Platform', href: '/admin/settings' },
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
                <div class="admin__heading">
                    <span v-if="icon" class="admin__badge" aria-hidden="true"><MdsIcon :name="icon" size="md" /></span>
                    <h1 class="admin__title">{{ title }}</h1>
                </div>
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

.admin__heading {
    display: flex;
    align-items: center;
    gap: var(--mds-space-3);
    margin-bottom: var(--mds-space-6);
}

.admin__badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 40px;
    height: 40px;
    flex-shrink: 0;
    border-radius: var(--mds-radius-lg);
    background-color: var(--mds-color-action-primary-tint);
    color: var(--mds-color-action-primary-fg);
}

.admin__title {
    margin: 0;
    font-family: var(--mds-font-family-display);
    font-size: var(--mds-type-heading-1-font-size);
    line-height: var(--mds-type-heading-1-line-height);
    font-weight: var(--mds-type-heading-1-font-weight);
    letter-spacing: var(--mds-type-heading-1-letter-spacing); /* JR1: was -0.01em */
    color: var(--mds-color-text-heading);
}

/*
 * ⚠️ 900px, not 480px, and I7b is what forced the measurement. `.admin__bar` is a fixed-height 56px flex
 * ROW with no wrap, so once brand + nav + email + logout exceed the viewport the bar overflows
 * horizontally rather than reflowing. At 768px the content box is ~720px while five nav links, the brand
 * and a real email address measure ~860px — and with the four pre-I7b links it was ~767px, i.e. already
 * marginally over. Raising the existing breakpoint (which already wraps the bar, frees its height and
 * drops the email) is the whole fix at tablet width.
 *
 * ⚠️ THE BAR WRAPPING IS NOT ENOUGH AT PHONE WIDTH, and I10e is what finally measured that. The claim this
 * comment used to end on — "nothing in CI would ever have caught it: /admin/* is excluded from the
 * Playwright responsive sweep because `superadmin.mfa` needs a TOTP" — no longer holds. `responsive-axe`
 * still does not cover /admin/*, but the reason given was wrong (the MFA gate needs no TOTP at all — see
 * `E2eSeeder::seedSuperAdmin()`), and a separate spec, `admin-console-axe.spec.ts`, now scans this shell at
 * all three viewports. Its first run at 375px was RED on both pages in both themes.
 *
 * The offender was measured, not guessed: `.admin__nav` is 369px of links starting at x=16, so it runs to
 * 385 against a 375px viewport — a 10px document overflow, identical on /admin/settings and
 * /admin/feedback, which is the tell that it is the SHELL and not either page. (The feedback DataTable's
 * 415px row is wider still but sits in its own `overflow-x: auto` container and never reaches
 * `documentElement.scrollWidth`.) Five links simply do not fit one phone-width row.
 *
 * `flex-wrap` on `.admin__nav` is safe HERE and only here: this block has already set the bar to
 * `height: auto`, so a second row of links grows it. Do NOT lift this to the unscoped rule above — against
 * the fixed 56px the second row would be clipped instead, which is the trap the original comment warned
 * about and the reason it was left off in the first place. The explicit `row-gap` is because the 4px
 * `gap` above was only ever a horizontal one; once the nav wraps it becomes the space BETWEEN two rows of
 * tap targets, and 4px there reads as a rendering fault rather than as a layout.
 *
 * 375px is the tested floor — the Playwright `mobile` project's width. Narrower phones should hold (the
 * right-hand group wraps to its own line) but nothing measures them.
 */
@media (max-width: 900px) {
    .admin__bar {
        flex-wrap: wrap;
        height: auto;
        gap: var(--mds-space-3);
        padding: var(--mds-space-3) var(--mds-space-4);
    }
    .admin__nav {
        flex-wrap: wrap;
        row-gap: var(--mds-space-2);
    }
    .admin__email {
        display: none;
    }
    .admin__inner {
        padding: var(--mds-space-6) var(--mds-space-4);
    }
}
</style>
